<?php

namespace App\Http\Controllers;

use App\Domain\Audit;
use App\Http\Resources\AnexoResource;
use App\Models\Anexo;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

final class AnexoController
{
    public function store(Request $request, string $registro)
    {
        $company = $request->attributes->get('empresa');
        $actor = $company ?? $request->user();
        $record = RegistroController::visible($request, $registro);
        Gate::forUser($actor)->authorize('update', $record);
        $data = $request->validate([
            'arquivo' => 'required|file|max:'.config('pet.upload_max_kib'),
            'descricao' => 'nullable|string|max:1000',
            'classificacao' => 'sometimes|required|in:PRIVADO,COMPARTILHAVEL,COPIA_OFICIAL',
        ]);
        $file = $data['arquivo'];
        $mime = $this->validateFile($file);
        $fingerprint = hash_file('sha256', $file->getPathname());
        $key = $company ? RegistroController::idempotencyKey($request) : null;
        $classification = $data['classificacao'] ?? 'PRIVADO';
        $name = Str::uuid().'.'.match ($mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf',
        };
        $original = Str::limit(preg_replace('/[[:cntrl:]]/u', '', $file->getClientOriginalName()) ?: 'anexo', 240, '');
        $directory = 'prontuario/'.$registro;
        $path = $directory.'/'.$name;
        $stored = false;
        $disk = Storage::disk(config('pet.storage_disk'));
        try {
            [$attachment, $created] = DB::transaction(function () use ($request, $registro, $actor, $company, $key, $file, $data, $mime, $fingerprint, $name, $original, $classification, $directory, $path, $disk, &$stored): array {
                $record = RegistroController::visible($request, $registro, locked: true);
                Gate::forUser($actor)->authorize('update', $record);
                if ($key !== null) {
                    $prior = RegistroController::reserve($company, $key, [
                        'action' => 'anexo', 'registro' => $registro, 'hash' => $fingerprint,
                        'descricao' => $data['descricao'] ?? null, 'nome' => $original, 'classificacao' => $classification,
                    ]);
                    if ($prior->id_anexo !== null) {
                        return [Anexo::findOrFail($prior->id_anexo), false];
                    }
                }
                $stored = true;
                abort_if($disk->putFileAs($directory, $file, $name, 'private') === false, 503, 'Falha no armazenamento.');
                $attachment = $record->anexos()->create([
                    'nome_original' => $original, 'nome_armazenado' => $name,
                    'caminho_arquivo' => $path, 'mime_type' => $mime, 'tamanho_bytes' => $file->getSize(),
                    'hash_sha256' => $fingerprint, 'descricao' => $data['descricao'] ?? null, 'classificacao' => $classification,
                ]);
                if ($key !== null) {
                    DB::table('integration_requests')->where('id_empresa', $actor->getKey())->where('key', $key)->update(['id_anexo' => $attachment->getKey()]);
                }
                Audit::write('anexos', $attachment->getKey(), 'ANEXAR', $request, after: ['id_registro' => $registro, 'classificacao' => $classification]);
                return [$attachment, true];
            });
        } catch (\Throwable $error) {
            if ($stored) {
                try {
                    if (! $disk->delete($path)) {
                        throw new \RuntimeException('Falha ao limpar anexo sem registro.');
                    }
                } catch (\Throwable $cleanupError) {
                    report($cleanupError);
                }
            }
            throw $error;
        }

        return response()->json((new AnexoResource($attachment->refresh(), $company))->resolve($request), $created ? 201 : 200);
    }

    public function download(Request $request, string $anexo)
    {
        $company = $request->attributes->get('empresa');
        $attachment = Anexo::findOrFail($anexo);
        $record = RegistroController::visible($request, $attachment->id_registro);
        abort_unless(AnexoResource::canRead($record, $attachment, $company), 404);
        if ($request->query->has('signature')) {
            abort_unless($request->hasValidSignature(), 403);
        }
        $disk = Storage::disk(config('pet.storage_disk'));
        abort_unless($disk->exists($attachment->caminho_arquivo), 404);
        Audit::write('anexos', $anexo, 'BAIXAR', $request);
        $filename = preg_replace('/[\/\\\\%]/', '_', $attachment->nome_original) ?: 'anexo';

        return $disk->download($attachment->caminho_arquivo, $filename, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function destroy(Request $request, string $anexo)
    {
        DB::transaction(function () use ($request, $anexo): void {
            $attachment = Anexo::findOrFail($anexo);
            $record = RegistroController::visible($request, $attachment->id_registro, locked: true);
            Gate::authorize('update', $record);
            abort_if($attachment->classificacao === 'COPIA_OFICIAL', 409, 'O documento oficial está sujeito à retenção.');
            Anexo::lockForUpdate()->findOrFail($anexo)->delete();
            Audit::write('anexos', $anexo, 'EXCLUIR_LOGICO', $request);
        });

        return response()->noContent();
    }

    private function validateFile(UploadedFile $file): string
    {
        $mime = $file->getMimeType();
        $head = file_get_contents($file->getPathname(), length: 8);
        $signature = is_string($head) && match ($mime) {
            'image/jpeg' => str_starts_with($head, "\xff\xd8\xff"),
            'image/png' => $head === "\x89PNG\x0d\x0a\x1a\x0a",
            'application/pdf' => str_starts_with($head, '%PDF-'),
            default => false,
        };
        if (! $signature) {
            throw ValidationException::withMessages(['arquivo' => 'Envie um JPEG, PNG ou PDF válido.']);
        }
        if ($scanner = config('pet.scanner')) {
            $process = new Process([$scanner, '--no-summary', $file->getPathname()], timeout: 30);
            $process->run();
            if ($process->getExitCode() === 1) {
                throw ValidationException::withMessages(['arquivo' => 'Arquivo rejeitado pela verificação de segurança.']);
            }
            abort_unless($process->isSuccessful(), 503, 'Verificação de arquivo indisponível.');
        }
        return $mime;
    }
}

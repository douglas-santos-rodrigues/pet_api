<?php

use App\Domain\Audit;
use App\Domain\Identifiers;
use App\Domain\PushReminders;
use App\Models\Anexo;
use App\Models\Empresa;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

Artisan::command('pet:empresa {acao : cadastrar|ativar|permissao|suspender|rotacionar} {cnpj} {--razao=} {--email=} {--permissao=}', function (): int {
    $cnpj = Identifiers::cnpj($this->argument('cnpj'));
    $action = $this->argument('acao');
    $permission = $this->option('permissao');
    if (! $cnpj || ! in_array($action, ['cadastrar', 'ativar', 'permissao', 'suspender', 'rotacionar'], true)) {
        $this->error('Ação ou CNPJ inválido.');
        return 1;
    }
    if (in_array($action, ['ativar', 'permissao'], true) && ! in_array($permission, ['LEITURA', 'LEITURA_ESCRITA', 'BLOQUEADO'], true)) {
        $this->error('Informe --permissao=LEITURA, LEITURA_ESCRITA ou BLOQUEADO.');
        return 1;
    }
    if ($action === 'cadastrar' && validator(['razao' => $this->option('razao'), 'email' => $this->option('email')], [
        'razao' => 'required|string|max:200', 'email' => 'required|email:rfc|max:255',
    ])->fails()) {
        $this->error('Informe a razão social e o e-mail da empresa.');
        return 1;
    }
    try {
        [$company, $issued] = DB::transaction(function () use ($action, $cnpj, $permission): array {
            $company = Empresa::where('cnpj', $cnpj)->lockForUpdate()->first();
            if ($action === 'cadastrar') {
                if ($company) {
                    throw new DomainException('Empresa já cadastrada.');
                }
                $company = Empresa::create(['cnpj' => $cnpj, 'razao_social' => $this->option('razao'), 'email' => mb_strtolower($this->option('email'))]);
                Audit::write('empresas', $company->getKey(), 'CRIAR');
                return [$company, null];
            }
            if (! $company) {
                throw new DomainException('Empresa inexistente.');
            }
            $before = $company->only(['ativo', 'api_ativo', 'api_permissao']);
            $secret = null;
            if ($action === 'rotacionar' || ($action === 'ativar' && ! $company->api_client_id)) {
                $secret = bin2hex(random_bytes(32));
                $company->api_client_id ??= (string) Str::uuid();
                $company->api_client_secret_hash = Hash::make($secret);
                $company->tokens()->delete();
            }
            if ($action === 'ativar' || $action === 'permissao') {
                $company->api_permissao = $permission;
            }
            if ($action === 'ativar') {
                $company->ativo = true;
                $company->api_ativo = true;
            }
            if ($action === 'suspender') {
                $company->ativo = false;
                $company->api_ativo = false;
            }
            if ($action === 'suspender' || $permission === 'BLOQUEADO') {
                $company->tokens()->delete();
            }
            $company->save();
            Audit::write('empresas', $company->getKey(), strtoupper($action), before: $before, after: $company->only(array_keys($before)));

            return [$company, $secret];
        });
    } catch (DomainException $error) {
        $this->error($error->getMessage());
        return 1;
    }

    $this->info('Empresa atualizada: '.$company->getKey());
    if ($issued !== null) {
        $this->line('client_id: '.$company->api_client_id);
        $this->line('client_secret: '.$issued);
    }

    return 0;
})->purpose('Administra as credenciais de integração');

Artisan::command('pet:copia-oficial {anexo} {empresa} {referencia : Protocolo de autorização verificado} {--confirmar}', function (): int {
    if (! Str::isUuid($this->argument('anexo')) || ! Str::isUuid($this->argument('empresa'))) {
        $this->error('Informe identificadores UUID válidos.');
        return 1;
    }
    $anexo = Anexo::with('registro.pet')->find($this->argument('anexo'));
    $company = Empresa::find($this->argument('empresa'));
    $reference = $this->argument('referencia');
    if (! $this->option('confirmar') || ! preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $reference)
        || ! $anexo || $anexo->classificacao !== 'COPIA_OFICIAL' || ! $anexo->registro?->pet || ! $company?->podeConsultar()) {
        $this->error('Confira o documento, a empresa ativa e a autorização vigente para este pet antes de confirmar.');
        return 1;
    }
    $path = 'entregas-oficiais/'.Str::uuid().'.'.match ($anexo->mime_type) {
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf',
    };
    $output = Storage::disk('local');
    $stream = Storage::disk(config('pet.storage_disk'))->readStream($anexo->caminho_arquivo);
    if (! is_resource($stream)) {
        $this->error('Arquivo indisponível.');
        return 1;
    }
    try {
        if (! $output->put($path, $stream, 'private')) {
            throw new RuntimeException('Falha na exportação.');
        }
        Audit::write('anexos', $anexo->getKey(), 'EXPORTAR_COPIA_OFICIAL', after: [
            'id_empresa_destino' => $company->getKey(), 'id_pet' => $anexo->registro->id_pet, 'protocolo' => $reference,
        ]);
    } catch (Throwable $error) {
        $output->delete($path);
        throw $error;
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
    $this->info('Cópia preparada em armazenamento local privado: '.$path);

    return 0;
})->purpose('Prepara uma cópia para entrega controlada com autorização verificada');

Schedule::command('sanctum:prune-expired --hours=24')->daily();
Artisan::command('pet:enviar-lembretes', function (PushReminders $reminders): void {
    $reminders->run();
})->purpose('Envia e confirma lembretes push de procedimentos');
Schedule::command('pet:enviar-lembretes')->everyMinute()->withoutOverlapping(5);

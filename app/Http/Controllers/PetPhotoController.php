<?php

namespace App\Http\Controllers;

use App\Domain\Audit;
use App\Models\Pet;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class PetPhotoController
{
    public function store(Request $request, string $pet)
    {
        $this->ownedPet($request, $pet);
        $data = $request->validate(['foto' => 'required|file|mimetypes:image/jpeg|max:5120']);
        $photo = $data['foto'];
        $source = $photo->getPathname();
        $image = @getimagesize($source);
        if ($image === false || $image[2] !== IMAGETYPE_JPEG || min($image[0], $image[1]) < 1
            || max($image[0], $image[1]) > 1600 || file_get_contents($source, offset: -2, length: 2) !== "\xff\xd9") {
            throw ValidationException::withMessages(['foto' => 'Envie um JPEG válido de até 1.600 pixels no lado maior.']);
        }

        $disk = Storage::disk(config('pet.storage_disk'));
        $directory = 'fotos-pets/'.$pet;
        $name = Str::uuid().'.jpg';
        $path = $directory.'/'.$name;

        try {
            abort_if($disk->putFileAs($directory, $photo, $name, 'private') === false, 503, 'Falha no armazenamento da foto.');
            $previous = DB::transaction(function () use ($request, $pet, $path): ?string {
                $owned = $this->ownedPet($request, $pet, lock: true);
                $previous = $owned->caminho_foto;
                $owned->update(['caminho_foto' => $path]);
                Audit::write('pets', $pet, 'ENVIAR_FOTO', $request);

                return $previous;
            });
        } catch (Throwable $error) {
            $this->removeFile($disk, $path);
            throw $error;
        }

        $this->removeFile($disk, $previous);

        return response()->noContent();
    }

    public function show(Request $request, string $pet)
    {
        $path = $this->ownedPet($request, $pet)->caminho_foto;
        abort_if($path === null, 404);

        $stream = Storage::disk(config('pet.storage_disk'))->readStream($path);
        abort_unless(is_resource($stream), 404);

        return response()->stream(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, string $pet)
    {
        $previous = DB::transaction(function () use ($request, $pet): ?string {
            $owned = $this->ownedPet($request, $pet, lock: true);
            $path = $owned->caminho_foto;
            if ($path !== null || $owned->foto_url !== null) {
                $owned->update(['caminho_foto' => null, 'foto_url' => null]);
                Audit::write('pets', $pet, 'REMOVER_FOTO', $request);
            }

            return $path;
        });
        $this->removeFile(Storage::disk(config('pet.storage_disk')), $previous);

        return response()->noContent();
    }

    private function ownedPet(Request $request, string $pet, bool $lock = false): Pet
    {
        $query = $request->user()->pets();
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($pet);
    }

    private function removeFile(FilesystemAdapter $disk, ?string $path): void
    {
        if ($path === null) {
            return;
        }
        try {
            if (! $disk->delete($path)) {
                throw new RuntimeException('Falha ao limpar arquivo de foto de pet.');
            }
        } catch (Throwable $error) {
            report($error);
        }
    }
}

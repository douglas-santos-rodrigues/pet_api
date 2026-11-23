<?php

namespace App\Http\Resources;

use App\Models\Anexo;
use App\Models\Empresa;
use App\Models\Registro;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

final class AnexoResource extends JsonResource
{
    public function __construct(Anexo $anexo, private readonly ?Empresa $company = null)
    {
        parent::__construct($anexo);
    }

    public function toArray(Request $request): array
    {
        $anexo = $this->resource;
        if (! self::canRead($anexo->registro, $anexo, $this->company)) {
            return ['id_anexo' => $anexo->getKey(), 'classificacao' => $anexo->classificacao];
        }
        return $anexo->attributesToArray() + [
            'download_url' => URL::temporarySignedRoute(
                $this->company ? 'empresa.anexo.download' : 'tutor.anexo.download', now()->addMinutes(5), ['anexo' => $anexo->getKey()],
            ),
        ];
    }

    public static function canRead(Registro $record, Anexo $anexo, ?Empresa $company): bool
    {
        return ! $company || ($anexo->classificacao !== 'COPIA_OFICIAL'
            && ($record->id_empresa_origem === $company->getKey()
                || ($anexo->classificacao === 'COMPARTILHAVEL' && config('pet.public_attachments'))));
    }
}

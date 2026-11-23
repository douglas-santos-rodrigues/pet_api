<?php

namespace App\Http\Resources;

use App\Models\Anexo;
use App\Models\Empresa;
use App\Models\Registro;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

final class RegistroResource extends JsonResource
{
    public function __construct(Registro $registro, private readonly ?Empresa $company = null, private readonly bool $withAttachments = false)
    {
        parent::__construct($registro);
    }

    public function toArray(Request $request): array
    {
        $record = $this->resource;
        $data = $record->attributesToArray();
        if ($this->company) {
            unset($data['id_pet']);
            if ($record->id_empresa_origem !== $this->company->getKey()) {
                unset($data['descricao'], $data['observacoes'], $data['local_atendimento'], $data['dados_adicionais']);
            }
        }
        $data['declaracao_do_tutor'] = $record->origem === 'TUTOR';
        if ($this->withAttachments) {
            if (! $this->company && ($tutor = $request->user())) {
                $data['pode_anexar'] = Gate::forUser($tutor)->allows('update', $record);
                $data['limite_anexo_bytes'] = config('pet.upload_max_kib') * 1024;
            }
            $data['anexos'] = $record->anexos()->orderBy('created_at')->get()
                ->filter(fn (Anexo $anexo) => AnexoResource::canRead($record, $anexo, $this->company))
                ->values()->map(function (Anexo $anexo) use ($record, $request): array {
                    $anexo->setRelation('registro', $record);
                    return (new AnexoResource($anexo, $this->company))->resolve($request);
                });
        }
        return $data;
    }
}

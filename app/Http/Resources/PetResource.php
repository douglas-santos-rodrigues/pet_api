<?php

namespace App\Http\Resources;

use App\Models\Pet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

final class PetResource extends JsonResource
{
    public function __construct(Pet $pet, private readonly bool $integration = false)
    {
        parent::__construct($pet);
    }

    public function toArray(Request $request): array
    {
        $pet = $this->resource;
        $data = $pet->toArray();
        if ($this->integration) {
            $data = collect($data)->only([
                'cpf_pet', 'id_especie', 'id_raca', 'nome', 'sexo', 'dt_nascimento', 'nascimento_estimado',
                'cor', 'pelagem', 'porte', 'castrado', 'dt_castracao', 'microchip', 'registro_oficial',
                'peso_atual', 'tipo_sanguineo', 'alergias', 'doencas_cronicas', 'medicamentos_continuos',
                'status', 'dt_obito',
            ])->all();
        } else {
            $data['foto_enviada'] = $pet->caminho_foto !== null;
        }
        $data['idade_anos'] = $pet->dt_nascimento
            ? (int) Carbon::parse($pet->dt_nascimento)->diffInYears($pet->dt_obito ? Carbon::parse($pet->dt_obito) : Carbon::today())
            : null;

        return $data;
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PetRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('PATCH') ? ['sometimes', 'required'] : ['required'];

        return [
            'cpf_pet' => 'missing', 'id_tutor' => 'missing',
            'nome' => [...$required, 'string', 'max:150'],
            'id_especie' => [...$required, 'integer', Rule::exists('especies', 'id_especie')->where('ativo', true)],
            'id_raca' => ['nullable', 'integer', Rule::exists('racas', 'id_raca')->where('ativo', true)],
            'sexo' => 'nullable|in:M,F',
            'dt_nascimento' => 'nullable|date_format:Y-m-d|before_or_equal:today',
            'nascimento_estimado' => 'sometimes|boolean',
            'cor' => 'nullable|string|max:100', 'pelagem' => 'nullable|string|max:100', 'porte' => 'nullable|string|max:20',
            'castrado' => 'nullable|boolean', 'dt_castracao' => 'nullable|date_format:Y-m-d|before_or_equal:today',
            'microchip' => 'nullable|string|max:50', 'registro_oficial' => 'nullable|string|max:100',
            'peso_atual' => 'nullable|numeric|min:0|max:9999.999|decimal:0,3', 'tipo_sanguineo' => 'nullable|string|max:30',
            'alergias' => 'nullable|string|max:5000', 'doencas_cronicas' => 'nullable|string|max:5000',
            'medicamentos_continuos' => 'nullable|string|max:5000', 'observacoes' => 'nullable|string|max:5000',
            'foto_url' => 'nullable|url:https|max:2048',
            'status' => 'sometimes|required|in:ATIVO,DESAPARECIDO,FALECIDO,INATIVO',
            'dt_obito' => 'nullable|date_format:Y-m-d|before_or_equal:today',
        ];
    }
}

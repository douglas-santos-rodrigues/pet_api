<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class RegistroRequest extends FormRequest
{
    public const STATUSES = ['AGENDADO', 'REALIZADO', 'CANCELADO', 'NAO_REALIZADO'];
    public const INSTANT = 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D';

    public function rules(): array
    {
        $update = $this->isMethod('PATCH');
        $required = $update ? ['sometimes', 'required'] : ['required'];
        $instant = ['nullable', 'date', self::INSTANT];

        return [
            'id_tipo_procedimento' => [...$required, 'integer', Rule::exists('tipos_procedimento', 'id_tipo_procedimento')->where('ativo', true)],
            'titulo' => [...$required, 'string', 'max:200'],
            'status' => [...$required, Rule::in(self::STATUSES)],
            'descricao' => 'nullable|string|max:20000', 'observacoes' => 'nullable|string|max:10000',
            'id_registro_origem' => 'nullable|uuid',
            'dt_agendamento' => $instant, 'dt_fim_agendamento' => $instant, 'dt_realizacao' => $instant,
            'origem' => 'missing', 'id_tutor_origem' => 'missing', 'id_empresa_origem' => 'missing', 'id_pet' => 'missing',
            'profissional_nome' => 'nullable|string|max:150', 'profissional_registro' => 'nullable|string|max:50',
            'local_atendimento' => 'nullable|string|max:200',
            'dados_adicionais' => 'nullable|array',
            'motivo_correcao' => $update ? 'nullable|string|max:500' : 'missing',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->isJson() && is_array(json_decode($this->getContent())->dados_adicionais ?? null)) {
                $validator->errors()->add('dados_adicionais', 'Informe um objeto JSON.');
            }
        }];
    }
}

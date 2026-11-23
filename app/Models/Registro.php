<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Registro extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'prontuario_registros';
    protected $primaryKey = 'id_registro';
    protected $dateFormat = 'Y-m-d H:i:s.uP';
    protected $guarded = [];
    protected $hidden = ['id_tutor_origem', 'id_empresa_origem', 'deleted_at', 'push_agendamento_atualizado_em'];

    protected function casts(): array
    {
        return [
            'id_tipo_procedimento' => 'integer', 'dt_agendamento' => 'immutable_datetime',
            'dt_fim_agendamento' => 'immutable_datetime', 'dt_realizacao' => 'immutable_datetime',
            'dados_adicionais' => 'object',
            'push_agendamento_atualizado_em' => 'immutable_datetime',
        ];
    }

    public function pet()
    {
        return $this->belongsTo(Pet::class, 'id_pet');
    }

    public function anexos()
    {
        return $this->hasMany(Anexo::class, 'id_registro');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Pet extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'pets';
    protected $primaryKey = 'id_pet';
    protected $guarded = [];
    protected $hidden = ['versao_tutoria', 'deleted_at', 'caminho_foto'];

    protected function casts(): array
    {
        return ['id_especie' => 'integer', 'id_raca' => 'integer', 'castrado' => 'boolean', 'nascimento_estimado' => 'boolean', 'versao_tutoria' => 'integer'];
    }

    public function tutor()
    {
        return $this->belongsTo(Tutor::class, 'id_tutor');
    }

    public function registros()
    {
        return $this->hasMany(Registro::class, 'id_pet');
    }
}

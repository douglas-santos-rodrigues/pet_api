<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Anexo extends Model
{
    use HasUuids, SoftDeletes;

    public const UPDATED_AT = null;

    protected $table = 'anexos';
    protected $primaryKey = 'id_anexo';
    protected $guarded = [];
    protected $hidden = ['caminho_arquivo', 'nome_armazenado', 'hash_sha256', 'deleted_at'];

    public function registro()
    {
        return $this->belongsTo(Registro::class, 'id_registro');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User;
use Laravel\Sanctum\HasApiTokens;

final class Empresa extends User
{
    use HasApiTokens, HasUuids, SoftDeletes;

    protected $table = 'empresas';
    protected $primaryKey = 'id_empresa';
    protected $guarded = [];
    protected $hidden = ['api_client_secret_hash', 'deleted_at'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean', 'api_ativo' => 'boolean', 'api_ultimo_acesso' => 'datetime'];
    }

    public function podeConsultar(): bool
    {
        return $this->ativo && $this->api_ativo && in_array($this->api_permissao, ['LEITURA', 'LEITURA_ESCRITA'], true);
    }
}

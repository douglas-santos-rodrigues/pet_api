<?php

namespace App\Domain;

use App\Models\Empresa;
use App\Models\Tutor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class Audit
{
    public static function write(string $table, string $id, string $action, ?Request $request = null, ?array $before = null, ?array $after = null, Tutor|Empresa|null $actor = null): void
    {
        $actor ??= $request?->attributes->get('empresa') ?? $request?->user();

        DB::table('auditoria')->insert([
            'tabela' => $table, 'id_registro' => $id, 'acao' => $action,
            'id_tutor' => $actor instanceof Tutor ? $actor->getKey() : null,
            'id_empresa' => $actor instanceof Empresa ? $actor->getKey() : null,
            'origem' => match (true) {
                $actor instanceof Tutor => 'TUTOR',
                $actor instanceof Empresa => 'EMPRESA',
                default => 'SISTEMA',
            },
            'dados_anteriores' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'dados_novos' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'ip_origem' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 512) : null,
            'request_id' => $request?->attributes->get('request_id'),
        ]);
    }
}

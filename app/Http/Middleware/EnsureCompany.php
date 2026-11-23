<?php

namespace App\Http\Middleware;

use App\Models\Empresa;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\PersonalAccessToken;

final class EnsureCompany
{
    public function handle(Request $request, Closure $next)
    {
        $raw = $request->bearerToken();
        $token = $raw ? PersonalAccessToken::findToken($raw) : null;
        abort_unless($token && $token->expires_at?->isFuture() && $token->can('integracao'), 401);
        $empresa = $token->tokenable;
        abort_unless($empresa instanceof Empresa, 401);
        $request->attributes->set('empresa', $empresa);
        return app(ThrottleRequests::class)->handle($request, function (Request $request) use ($empresa, $next) {
            abort_unless($empresa->podeConsultar(), 403);
            abort_if(! $request->isMethodSafe() && $empresa->api_permissao !== 'LEITURA_ESCRITA', 403);

            return $next($request);
        }, 'integration-api');
    }
}

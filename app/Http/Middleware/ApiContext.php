<?php

namespace App\Http\Middleware;

use App\Domain\Audit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class ApiContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('request_id', (string) Str::uuid());
        if ($request->is('api/v1/tutor/*') && is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }

        $response = $next($request);
        if ($request->is('api/*')) {
            $response->headers->set('X-Request-Id', $request->attributes->get('request_id'));
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($request->is('api/v1/*') && in_array($response->getStatusCode(), [401, 403, 404, 429], true)) {
            try {
                Audit::write('api', (string) $request->attributes->get('request_id'), 'ACESSO_NEGADO', $request, after: [
                    'status' => $response->getStatusCode(), 'rota' => $request->route()?->uri(), 'metodo' => $request->method(),
                ]);
            } catch (\Throwable $error) {
                report($error);
            }
        }
    }
}

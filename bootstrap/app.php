<?php

use App\Http\Middleware\ApiContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(ApiContext::class);
        $middleware->statefulApi();
        $middleware->trimStrings(except: ['senha', 'senha_confirmation', 'senha_atual', 'client_secret']);
        $middleware->alias(['tutor' => App\Http\Middleware\EnsureTutor::class, 'empresa' => App\Http\Middleware\EnsureCompany::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (Throwable $error, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            $status = match (true) {
                $error instanceof ValidationException => 422,
                $error instanceof Illuminate\Auth\AuthenticationException => 401,
                $error instanceof Illuminate\Auth\Access\AuthorizationException => $error->status() ?? 403,
                $error instanceof Illuminate\Database\Eloquent\ModelNotFoundException => 404,
                $error instanceof HttpExceptionInterface => $error->getStatusCode(),
                default => 500,
            };
            $body = [
                'message' => $status === 500 ? 'Erro interno.' : ($error->getMessage() ?: (Symfony\Component\HttpFoundation\Response::$statusTexts[$status] ?? 'Erro')),
                'request_id' => $request->attributes->get('request_id'),
            ];
            if ($error instanceof ValidationException) {
                $body['errors'] = $error->errors();
            }
            if ($error instanceof Illuminate\Database\UniqueConstraintViolationException) {
                return response()->json(['message' => 'O valor informado já está em uso.', 'request_id' => $body['request_id']], 409);
            }
            return response()->json($body, $status, $error instanceof HttpExceptionInterface ? $error->getHeaders() : []);
        });
    })
    ->create();

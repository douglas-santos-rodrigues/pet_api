<?php

namespace App\Http\Middleware;

use App\Models\Tutor;
use Closure;
use Illuminate\Http\Request;

final class EnsureTutor
{
    public function handle(Request $request, Closure $next, string $verification = 'verified')
    {
        $actor = $request->user();
        abort_unless($actor instanceof Tutor && $actor->ativo, 401);
        abort_if($verification === 'verified' && ! $actor->hasVerifiedEmail(), 403, 'Verifique seu e-mail.');

        return $next($request);
    }
}

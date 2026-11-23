<?php

namespace App\Providers;

use App\Models\Pet;
use App\Models\Registro;
use App\Policies\PetPolicy;
use App\Policies\RegistroPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Pet::class, PetPolicy::class);
        Gate::policy(Registro::class, RegistroPolicy::class);

        ResetPassword::createUrlUsing(fn ($tutor, string $token) => rtrim(config('pet.client_url'), '/').'/redefinir-senha?'.http_build_query([
            'token' => $token,
            'email' => $tutor->email,
        ]));

        RateLimiter::for('tutor-auth', fn (Request $request) => Limit::perMinute(12)->by($request->ip()));
        RateLimiter::for('integration-token', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('integration-ip', fn (Request $request) => Limit::perMinute(config('pet.integration_ip_limit'))->by($request->ip()));
        RateLimiter::for('integration-api', fn (Request $request) =>
            Limit::perMinute(config('pet.integration_company_limit'))->by($request->attributes->get('empresa')->getKey())
        );
    }
}

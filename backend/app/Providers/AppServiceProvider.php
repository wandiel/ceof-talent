<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Em desenvolvimento e testes, falha cedo em lazy loading e atributos inexistentes.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Rotas de autenticação: limite por IP (além do limite por e-mail+IP do login).
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(20)->by('auth:'.$request->ip()));

        // API autenticada: limite por usuário (ou IP, se anônimo).
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(300)->by($request->user()?->id ?: $request->ip()));
    }
}

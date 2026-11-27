<?php

namespace App\Providers;

use App\Domain\Intelligence\Contracts\CandidateProfileExtractor;
use App\Domain\Intelligence\Contracts\DocumentTextExtractor;
use App\Domain\Intelligence\Contracts\DuplicateDetector;
use App\Domain\Intelligence\Contracts\JobMatchScorer;
use App\Domain\Intelligence\Local\FinderDuplicateDetector;
use App\Domain\Intelligence\Local\KeywordJobMatchScorer;
use App\Domain\Intelligence\Local\KeywordProfileExtractor;
use App\Domain\Intelligence\Local\LocalTextExtractor;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // IA (Fase 7.9): implementações locais, trocáveis por serviços externos sem mudar o restante do sistema.
        $this->app->bind(DocumentTextExtractor::class, LocalTextExtractor::class);
        $this->app->bind(CandidateProfileExtractor::class, KeywordProfileExtractor::class);
        $this->app->bind(JobMatchScorer::class, KeywordJobMatchScorer::class);
        $this->app->bind(DuplicateDetector::class, FinderDuplicateDetector::class);
    }

    public function boot(): void
    {
        // Em desenvolvimento e testes, falha cedo em lazy loading e atributos inexistentes.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Rotas de autenticação: limite por IP (além do limite por e-mail+IP do login).
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(20)->by('auth:'.$request->ip()));

        // API autenticada: limite por usuário (ou IP, se anônimo).
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(300)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(30)->by('uploads:'.($request->user()?->id ?: $request->ip())));
    }
}

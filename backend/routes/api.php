<?php

use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API REST do CEOF TALENT
|--------------------------------------------------------------------------
| Todas as rotas aqui recebem o prefixo /api. Versões futuras da API devem
| ser agrupadas por prefixo (ex.: /api/v1) conforme docs/01-planejamento-arquitetura.md.
*/

Route::get('/health', HealthController::class)
    ->middleware('throttle:60,1')
    ->name('api.health');

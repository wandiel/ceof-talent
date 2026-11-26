<?php

use Illuminate\Support\Facades\Route;

/*
| O CEOF TALENT expõe sua interface pelo frontend Vue (container "frontend")
| e sua API REST em /api. Esta rota existe apenas para identificar o serviço
| caso o backend seja acessado diretamente.
*/
Route::get('/', fn () => response()->json([
    'service' => config('app.name'),
    'api' => url('/api'),
]));

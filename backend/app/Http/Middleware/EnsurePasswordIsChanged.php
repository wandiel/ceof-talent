<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usuários com senha provisória (definida por um administrador) só podem
 * consultar a própria sessão, trocar a senha e sair até definirem uma nova.
 */
class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            return response()->json([
                'message' => 'Defina uma nova senha para continuar.',
                'code' => 'password_change_required',
            ], 403);
        }

        return $next($request);
    }
}

<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\AuditLogger;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Autentica um usuário interno por e-mail e senha (sessão + cookie HttpOnly).
 *
 * Regras: limite de tentativas por e-mail+IP, usuários inativos não entram,
 * mensagens genéricas (não revelam se o e-mail existe), sessão regenerada
 * após o login (proteção contra fixação de sessão) e auditoria de sucesso e falha.
 */
class AuthenticateUser
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(LoginRequest $request): User
    {
        $request->ensureIsNotRateLimited();

        $credentials = $request->only('email', 'password');

        if (! Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            $request->hitRateLimiter();
            $this->audit->log('auth.login_failed', description: 'Tentativa de login sem sucesso.', context: [
                'email' => mb_strtolower((string) $request->input('email')),
            ]);

            throw ValidationException::withMessages(['email' => trans('auth.failed')]);
        }

        /** @var User $user */
        $user = Auth::guard('web')->user();

        if (! $user->is_active) {
            Auth::guard('web')->logout();
            $request->hitRateLimiter();
            $this->audit->log('auth.login_blocked_inactive', $user, 'Login recusado: usuário inativo.', userId: $user->id);

            throw ValidationException::withMessages(['email' => trans('auth.inactive')]);
        }

        $request->clearRateLimiter();
        $request->session()->regenerate();

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->saveQuietly();

        $this->audit->log('auth.login', $user, 'Login realizado.', userId: $user->id);

        return $user;
    }
}

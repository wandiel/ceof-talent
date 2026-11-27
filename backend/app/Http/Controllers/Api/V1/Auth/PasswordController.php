<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    /** Troca de senha pelo próprio usuário autenticado. */
    public function update(UpdatePasswordRequest $request, AuditLogger $audit): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->forceFill([
            'password' => $request->validated('password'),
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();

        // Encerra as demais sessões abertas com a senha antiga e renova a atual.
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->when($request->hasSession(), fn ($q) => $q->where('id', '!=', $request->session()->getId()))
            ->delete();

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $audit->log('auth.password_changed', $user, 'Senha alterada pelo próprio usuário.');

        return response()->json(['message' => 'Senha alterada com sucesso.']);
    }

    /**
     * Solicita o link de redefinição. A resposta é sempre a mesma, exista ou
     * não o e-mail, para não permitir descobrir quem tem conta no sistema.
     */
    public function forgot(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);

        $user = User::query()->where('email', $data['email'])->first();

        if ($user?->is_active) {
            Password::broker()->sendResetLink(['email' => $user->email]);
            $audit->log('auth.password_reset_requested', $user, 'Link de redefinição de senha solicitado.', userId: $user->id);
        }

        return response()->json([
            'message' => 'Se o e-mail estiver cadastrado, você receberá as instruções para redefinir a senha.',
        ]);
    }

    public function reset(ResetPasswordRequest $request, AuditLogger $audit): JsonResponse
    {
        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use ($audit) {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    'must_change_password' => false,
                    'password_changed_at' => now(),
                ])->save();

                event(new PasswordReset($user));
                $audit->log('auth.password_reset', $user, 'Senha redefinida por link de recuperação.', userId: $user->id);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => trans($status)]);
        }

        return response()->json(['message' => trans($status)]);
    }
}

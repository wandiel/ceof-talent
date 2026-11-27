<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Define uma senha provisória para outro usuário (troca obrigatória no próximo acesso). */
class ResetUserPassword
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $user, #[\SensitiveParameter] string $temporaryPassword): void
    {
        DB::transaction(function () use ($user, $temporaryPassword) {
            $user->forceFill([
                'password' => $temporaryPassword,
                'must_change_password' => true,
            ])->save();

            DB::table('sessions')->where('user_id', $user->id)->delete();

            $this->audit->log('user.password_reset_by_admin', $user, "Senha provisória definida para {$user->email}.");
        });
    }
}

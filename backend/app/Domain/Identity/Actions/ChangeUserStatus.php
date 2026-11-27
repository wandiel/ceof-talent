<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ativa ou desativa um usuário. Usuários não são excluídos, para preservar
 * a trilha de auditoria; desativados não conseguem entrar e perdem a sessão.
 */
class ChangeUserStatus
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $user, bool $active): User
    {
        if ($user->is_active === $active) {
            return $user;
        }

        return DB::transaction(function () use ($user, $active) {
            $user->forceFill(['is_active' => $active])->save();

            if (! $active && User::role(Role::Admin->value)->where('is_active', true)->doesntExist()) {
                throw ValidationException::withMessages(['is_active' => 'O sistema precisa de pelo menos um Administrador ativo.']);
            }

            if (! $active) {
                // Encerra sessões abertas do usuário desativado.
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $user->tokens()->delete();
            }

            $this->audit->log(
                $active ? 'user.activated' : 'user.deactivated',
                $user,
                ($active ? 'Usuário ativado: ' : 'Usuário desativado: ').$user->email,
                old: ['is_active' => ! $active],
                new: ['is_active' => $active],
            );

            return $user;
        });
    }
}

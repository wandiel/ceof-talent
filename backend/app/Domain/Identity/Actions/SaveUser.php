<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\Role;
use App\Models\Department;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cria ou atualiza um usuário interno, com seus perfis de acesso.
 *
 * Regras de negócio:
 *  - somente Administradores concedem ou retiram o perfil Administrador;
 *  - ninguém retira o próprio perfil Administrador;
 *  - o sistema nunca fica sem ao menos um Administrador ativo;
 *  - usuários criados por outra pessoa recebem senha provisória e devem trocá-la no 1º acesso.
 */
class SaveUser
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, array $data, ?User $user = null): User
    {
        $creating = $user === null;
        $user ??= new User;
        $roles = array_values(array_unique($data['roles']));

        $this->guardAdminRole($actor, $user, $roles, $creating);

        return DB::transaction(function () use ($actor, $user, $data, $roles, $creating) {
            $originalAttributes = $user->getAttributes();
            $originalRoles = $creating ? [] : $user->getRoleNames()->sort()->values()->all();

            $user->fill([
                'name' => $data['name'],
                'email' => mb_strtolower($data['email']),
                'job_title' => $data['job_title'] ?? null,
                'phone' => $data['phone'] ?? null,
                'unit_id' => isset($data['unit_id']) ? Unit::query()->where('uuid', $data['unit_id'])->value('id') : null,
                'department_id' => isset($data['department_id']) ? Department::query()->where('uuid', $data['department_id'])->value('id') : null,
            ]);

            if (array_key_exists('is_active', $data)) {
                $user->is_active = (bool) $data['is_active'];
            }

            if ($creating) {
                $user->password = $data['password'];
                $user->must_change_password = true;
            }

            $user->save();
            $user->syncRoles($roles);

            $this->ensureAnActiveAdminRemains();

            $newRoles = collect($roles)->sort()->values()->all();

            if ($creating) {
                $this->audit->log('user.created', $user, "Usuário {$user->email} criado.", new: [
                    'name' => $user->name, 'email' => $user->email, 'roles' => $newRoles, 'is_active' => $user->is_active,
                ]);
            } else {
                $this->audit->logChanges('user.updated', $user, $originalAttributes, "Usuário {$user->email} atualizado.");

                if ($originalRoles !== $newRoles) {
                    $this->audit->log('user.roles_changed', $user, "Perfis de {$user->email} alterados.", old: ['roles' => $originalRoles], new: ['roles' => $newRoles]);
                }
            }

            return $user->load(['roles', 'unit', 'department']);
        });
    }

    /** @param list<string> $roles */
    private function guardAdminRole(User $actor, User $user, array $roles, bool $creating): void
    {
        $grantsAdmin = in_array(Role::Admin->value, $roles, true);
        $hadAdmin = ! $creating && $user->hasRole(Role::Admin->value);

        if (($grantsAdmin || $hadAdmin) && ! $actor->hasRole(Role::Admin->value)) {
            throw new AuthorizationException('Somente Administradores podem conceder ou retirar o perfil Administrador.');
        }

        if ($hadAdmin && ! $grantsAdmin && $actor->is($user)) {
            throw ValidationException::withMessages(['roles' => 'Você não pode retirar o seu próprio perfil de Administrador.']);
        }
    }

    private function ensureAnActiveAdminRemains(): void
    {
        $activeAdmins = User::role(Role::Admin->value)->where('is_active', true)->count();

        if ($activeAdmins === 0) {
            throw ValidationException::withMessages(['roles' => 'O sistema precisa de pelo menos um Administrador ativo.']);
        }
    }
}

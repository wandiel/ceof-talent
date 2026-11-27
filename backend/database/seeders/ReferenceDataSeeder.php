<?php

namespace Database\Seeders;

use App\Domain\Identity\Enums\Permission as PermissionEnum;
use App\Domain\Identity\Enums\Role as RoleEnum;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sincroniza perfis e permissões com os enums do código.
 * Idempotente: pode ser executado a cada inicialização do container.
 * Não cria usuários.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $guard = config('auth.defaults.guard', 'web');

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionEnum::cases() as $permission) {
            Permission::findOrCreate($permission->value, $guard);
        }

        // Remove permissões que deixaram de existir no catálogo.
        Permission::query()
            ->where('guard_name', $guard)
            ->whereNotIn('name', PermissionEnum::values())
            ->delete();

        foreach (RoleEnum::cases() as $roleEnum) {
            $role = Role::findOrCreate($roleEnum->value, $guard);
            $role->syncPermissions(array_map(fn (PermissionEnum $p) => $p->value, $roleEnum->permissions()));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\Role;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

/** Garante que o banco reflete exatamente a matriz de perfis definida no código. */
class PermissionMatrixTest extends TestCase
{
    use InteractsWithIdentity;

    public function test_every_role_has_exactly_its_documented_permissions(): void
    {
        foreach (Role::cases() as $role) {
            $expected = collect($role->permissions())->map->value->sort()->values()->all();
            $actual = RoleModel::findByName($role->value, 'web')->permissions->pluck('name')->sort()->values()->all();

            $this->assertSame($expected, $actual, "Permissões divergentes para o perfil {$role->value}");
        }
    }

    public function test_admin_has_every_permission_and_interviewer_only_interviews(): void
    {
        $this->assertCount(count(Permission::cases()), Role::Admin->permissions());
        $this->assertSame(['interviews.view', 'interviews.evaluate'], array_map(fn ($p) => $p->value, Role::Interviewer->permissions()));
    }

    public function test_reference_seeder_is_idempotent(): void
    {
        $this->seed(\Database\Seeders\ReferenceDataSeeder::class);
        $this->seed(\Database\Seeders\ReferenceDataSeeder::class);

        $this->assertSame(count(Role::cases()), RoleModel::count());
    }
}

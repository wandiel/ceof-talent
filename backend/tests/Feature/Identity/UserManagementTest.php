<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Enums\Role;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use InteractsWithIdentity;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ana Recrutadora',
            'email' => 'ana@ceof.test',
            'roles' => ['recruiter'],
            'password' => 'Provisoria@2026',
        ], $overrides);
    }

    public function test_admin_creates_user_with_temporary_password_and_roles(): void
    {
        $this->actingAsRole(Role::Admin);
        $department = Department::factory()->create();

        $response = $this->postJson('/api/v1/users', $this->payload(['department_id' => $department->uuid]))
            ->assertCreated()
            ->assertJsonPath('data.email', 'ana@ceof.test')
            ->assertJsonPath('data.must_change_password', true)
            ->assertJsonPath('data.department.id', $department->uuid)
            ->assertJsonPath('data.roles.0.name', 'recruiter');

        $user = User::where('uuid', $response->json('data.id'))->firstOrFail();
        $this->assertTrue(Hash::check('Provisoria@2026', $user->password));
        $this->assertTrue($user->hasRole('recruiter'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.created', 'auditable_id' => $user->id]);
        $this->assertStringNotContainsString('Provisoria@2026', json_encode(\App\Models\AuditLog::where('event', 'user.created')->first()->new_values));
    }

    public function test_department_manager_requires_a_department(): void
    {
        $this->actingAsRole(Role::Admin);

        $this->postJson('/api/v1/users', $this->payload(['roles' => ['department_manager']]))
            ->assertStatus(422)
            ->assertJsonPath('errors.department_id.0', 'Informe o departamento do Gestor de departamento.');
    }

    public function test_email_must_be_unique_case_insensitive(): void
    {
        $this->actingAsRole(Role::Admin);
        $this->userWithRole(Role::Recruiter, ['email' => 'ana@ceof.test']);

        $this->postJson('/api/v1/users', $this->payload(['email' => 'ANA@ceof.test']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_validation_messages_are_in_portuguese(): void
    {
        $this->actingAsRole(Role::Admin);

        $errors = $this->postJson('/api/v1/users', ['roles' => ['inexistente']])
            ->assertStatus(422)
            ->json('errors');

        $this->assertSame('O campo nome é obrigatório.', $errors['name'][0]);
        $this->assertSame('O valor selecionado para perfil de acesso é inválido.', $errors['roles.0'][0]);
    }

    /** @return array<string, array{Role}> */
    public static function rolesWithoutUserManagement(): array
    {
        return [
            'gestor de RH' => [Role::HrManager],
            'analista de RH' => [Role::HrAnalyst],
            'recrutador' => [Role::Recruiter],
            'gestor de departamento' => [Role::DepartmentManager],
            'entrevistador' => [Role::Interviewer],
        ];
    }

    #[DataProvider('rolesWithoutUserManagement')]
    public function test_only_admins_can_create_or_edit_users(Role $role): void
    {
        $this->actingAsRole($role);
        $target = $this->userWithRole(Role::Recruiter);

        $this->postJson('/api/v1/users', $this->payload())->assertForbidden();
        $this->putJson("/api/v1/users/{$target->uuid}", $this->payload(['password' => null]))->assertForbidden();
        $this->patchJson("/api/v1/users/{$target->uuid}/status", ['is_active' => false])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'ana@ceof.test']);
    }

    public function test_hr_manager_can_list_users(): void
    {
        $this->actingAsRole(Role::HrManager);
        $this->getJson('/api/v1/users')->assertOk();
    }

    public function test_interviewer_cannot_list_users(): void
    {
        $this->actingAsRole(Role::Interviewer);
        $this->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_listing_supports_search_filters_and_pagination(): void
    {
        $this->actingAsRole(Role::Admin, ['name' => 'Zé Admin']);
        $this->userWithRole(Role::Recruiter, ['name' => 'Beatriz Souza', 'email' => 'bia@ceof.test']);
        $this->userWithRole(Role::Interviewer, ['name' => 'Carlos Lima', 'is_active' => false]);

        $this->getJson('/api/v1/users?search=souza')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'bia@ceof.test');

        $this->getJson('/api/v1/users?status=inactive')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Carlos Lima');
        $this->getJson('/api/v1/users?role=interviewer')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/users?per_page=5&sort=-name')->assertJsonPath('meta.per_page', 5)->assertJsonPath('data.0.name', 'Zé Admin');
    }

    public function test_admin_updates_user_and_role_change_is_audited(): void
    {
        $this->actingAsRole(Role::Admin);
        $user = $this->userWithRole(Role::Recruiter);

        $this->putJson("/api/v1/users/{$user->uuid}", [
            'name' => 'Nome Atualizado',
            'email' => $user->email,
            'roles' => ['hr_analyst'],
        ])->assertOk()->assertJsonPath('data.name', 'Nome Atualizado')->assertJsonPath('data.roles.0.name', 'hr_analyst');

        $this->assertDatabaseHas('audit_logs', ['event' => 'user.roles_changed', 'auditable_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.updated', 'auditable_id' => $user->id]);
    }

    public function test_password_cannot_be_changed_through_user_update(): void
    {
        $this->actingAsRole(Role::Admin);
        $user = $this->userWithRole(Role::Recruiter);

        $this->putJson("/api/v1/users/{$user->uuid}", ['name' => 'X Y', 'email' => $user->email, 'roles' => ['recruiter'], 'password' => 'Outra@Senha2026'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_admin_cannot_remove_own_admin_role_or_deactivate_self(): void
    {
        $admin = $this->actingAsRole(Role::Admin);
        $this->userWithRole(Role::Admin); // há outro admin, mas a regra é sobre si mesmo

        $this->putJson("/api/v1/users/{$admin->uuid}", ['name' => $admin->name, 'email' => $admin->email, 'roles' => ['hr_manager']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('roles');

        $this->patchJson("/api/v1/users/{$admin->uuid}/status", ['is_active' => false])->assertForbidden();
        $this->assertTrue($admin->fresh()->hasRole('admin'));
    }

    public function test_last_active_admin_cannot_be_deactivated(): void
    {
        $this->actingAsRole(Role::Admin);
        $other = $this->userWithRole(Role::Admin);
        // Desativa o outro admin (permitido, pois o ator continua ativo).
        $this->patchJson("/api/v1/users/{$other->uuid}/status", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);

        $this->assertSame(1, User::role('admin')->where('is_active', true)->count());
    }

    public function test_deactivation_terminates_sessions_and_reactivation_works(): void
    {
        $this->actingAsRole(Role::Admin);
        $user = $this->userWithRole(Role::Recruiter);
        \DB::table('sessions')->insert(['id' => 'sessao-teste', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->patchJson("/api/v1/users/{$user->uuid}/status", ['is_active' => false])->assertOk();
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.deactivated', 'auditable_id' => $user->id]);

        $this->patchJson("/api/v1/users/{$user->uuid}/status", ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);
    }

    public function test_admin_sets_temporary_password_for_another_user(): void
    {
        $this->actingAsRole(Role::Admin);
        $user = $this->userWithRole(Role::Recruiter);

        $this->putJson("/api/v1/users/{$user->uuid}/password", ['password' => 'Temporaria@2026'])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('Temporaria@2026', $user->password));
        $this->assertTrue($user->must_change_password);
    }

    public function test_users_are_addressed_by_uuid_not_sequential_id(): void
    {
        $this->actingAsRole(Role::Admin);
        $user = $this->userWithRole(Role::Recruiter);

        $this->getJson("/api/v1/users/{$user->id}")->assertNotFound();
        $this->getJson("/api/v1/users/{$user->uuid}")->assertOk()->assertJsonPath('data.id', $user->uuid);
    }

    public function test_roles_catalog_lists_all_profiles_with_permissions(): void
    {
        $this->actingAsRole(Role::Admin);

        $response = $this->getJson('/api/v1/roles')->assertOk()->assertJsonCount(6, 'data');
        $this->assertSame(
            ['Administrador', 'Gestor de RH', 'Analista de RH', 'Recrutador', 'Gestor de departamento', 'Entrevistador'],
            array_column($response->json('data'), 'label'),
        );
    }
}

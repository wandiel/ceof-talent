<?php

namespace Tests\Feature\Organization;

use App\Domain\Identity\Enums\Role;
use App\Models\Department;
use App\Models\Position;
use App\Models\Unit;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use InteractsWithIdentity;

    public function test_hr_manager_manages_units(): void
    {
        $this->actingAsRole(Role::HrManager);

        $id = $this->postJson('/api/v1/units', ['name' => 'Sede Manaus', 'code' => 'mao-01', 'city' => 'Manaus', 'state' => 'am'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'MAO-01')
            ->assertJsonPath('data.state', 'AM')
            ->assertJsonPath('data.is_active', true)
            ->json('data.id');

        $this->putJson("/api/v1/units/{$id}", ['name' => 'Sede Manaus', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->getJson('/api/v1/units?status=inactive')->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('audit_logs', ['event' => 'unit.updated']);
    }

    public function test_unit_validation(): void
    {
        $this->actingAsRole(Role::HrManager);
        Unit::factory()->create(['name' => 'Filial Norte']);

        $this->postJson('/api/v1/units', ['name' => 'Filial Norte', 'state' => 'XX'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'state']);
    }

    public function test_recruiter_can_view_but_not_manage_organization(): void
    {
        $this->actingAsRole(Role::Recruiter);
        $department = Department::factory()->create();

        $this->getJson('/api/v1/departments')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/departments', ['name' => 'TI'])->assertForbidden();
        $this->putJson("/api/v1/departments/{$department->uuid}", ['name' => 'Outro'])->assertForbidden();
        $this->deleteJson("/api/v1/departments/{$department->uuid}")->assertForbidden();
    }

    public function test_interviewer_cannot_view_organization(): void
    {
        $this->actingAsRole(Role::Interviewer);

        $this->getJson('/api/v1/units')->assertForbidden();
    }

    public function test_positions_are_unique_per_department_and_normalize_cbo(): void
    {
        $this->actingAsRole(Role::HrManager);
        $ti = Department::factory()->create(['name' => 'TI']);
        $rh = Department::factory()->create(['name' => 'RH']);

        $this->postJson('/api/v1/positions', ['title' => 'Analista', 'department_id' => $ti->uuid, 'cbo_code' => '212405'])
            ->assertCreated()
            ->assertJsonPath('data.cbo_code', '2124-05')
            ->assertJsonPath('data.department.name', 'TI');

        $this->postJson('/api/v1/positions', ['title' => 'ANALISTA', 'department_id' => $ti->uuid])
            ->assertStatus(422)
            ->assertJsonPath('errors.title.0', 'Já existe um cargo com este título neste departamento.');

        $this->postJson('/api/v1/positions', ['title' => 'Analista', 'department_id' => $rh->uuid])->assertCreated();

        $this->getJson("/api/v1/positions?department_id={$ti->uuid}")->assertJsonCount(1, 'data');
    }

    public function test_department_in_use_cannot_be_deleted_but_unused_can(): void
    {
        $this->actingAsRole(Role::HrManager);
        $used = Department::factory()->create();
        Position::factory()->create(['department_id' => $used->id]);
        $unused = Department::factory()->create();

        $this->deleteJson("/api/v1/departments/{$used->uuid}")
            ->assertStatus(409)
            ->assertJsonPath('errors.record.0', fn ($m) => str_contains($m, 'cargos vinculados'));

        $this->deleteJson("/api/v1/departments/{$unused->uuid}")->assertNoContent();
        $this->assertDatabaseMissing('departments', ['id' => $unused->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'department.deleted']);
    }

    public function test_listing_search_and_sort(): void
    {
        $this->actingAsRole(Role::HrAnalyst);
        Department::factory()->create(['name' => 'Financeiro']);
        Department::factory()->create(['name' => 'Tecnologia da Informação', 'code' => 'TI']);

        $this->getJson('/api/v1/departments?search=tecno')->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'TI');
        $this->getJson('/api/v1/departments?sort=-name')->assertJsonPath('data.0.name', 'Tecnologia da Informação');
    }
}

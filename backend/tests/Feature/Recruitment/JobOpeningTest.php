<?php

namespace Tests\Feature\Recruitment;

use App\Domain\Identity\Enums\Role;
use App\Domain\Recruitment\Enums\JobStatus;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\JobOpening;
use App\Models\Position;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class JobOpeningTest extends TestCase
{
    use InteractsWithIdentity;

    private function payload(Department $department, array $overrides = []): array
    {
        return array_merge([
            'title' => 'Técnico de Enfermagem',
            'department_id' => $department->uuid,
            'professional_area' => 'nursing',
            'employment_type' => 'clt',
            'reason' => 'new_position',
            'positions_count' => 2,
            'description' => 'Atuar no centro cirúrgico prestando assistência de enfermagem.',
            'salary_min' => 2500,
            'salary_max' => 3200,
            'requirements' => [
                ['description' => 'COREN ativo', 'is_mandatory' => true],
                ['description' => 'Experiência em centro cirúrgico', 'is_mandatory' => false],
                ['description' => '   '],
            ],
        ], $overrides);
    }

    public function test_requester_creates_a_draft_with_code_and_requirements(): void
    {
        $department = Department::factory()->create();
        $manager = $this->actingAsRole(Role::DepartmentManager, ['department_id' => $department->id]);

        $response = $this->postJson('/api/v1/jobs', $this->payload($department))->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.requester.id', $manager->uuid)
            ->assertJsonCount(2, 'data.requirements')
            ->assertJsonPath('data.requirements.1.is_mandatory', false);

        $this->assertMatchesRegularExpression('/^VAGA-\d{4}-0001$/', $response->json('data.code'));
        $this->postJson('/api/v1/jobs', $this->payload($department))->assertCreated()->assertJsonPath('data.code', fn ($c) => str_ends_with($c, '-0002'));
        $this->assertSame(1, AuditLog::where('event', 'job.created')->where('auditable_id', JobOpening::where('code', $response->json('data.code'))->value('id'))->count());
    }

    public function test_validation_rules(): void
    {
        $department = Department::factory()->create();
        $this->actingAsRole(Role::HrManager);

        $this->postJson('/api/v1/jobs', $this->payload($department, [
            'title' => '', 'reason' => 'replacement', 'salary_max' => 1000, 'positions_count' => 0, 'closes_on' => now()->subDay()->toDateString(),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['title', 'replaced_employee', 'salary_max', 'positions_count', 'closes_on']);
    }

    public function test_department_manager_cannot_request_for_another_department(): void
    {
        $this->actingAsRole(Role::DepartmentManager, ['department_id' => Department::factory()->create()->id]);

        $this->postJson('/api/v1/jobs', $this->payload(Department::factory()->create()))
            ->assertUnprocessable()->assertJsonValidationErrors('department_id');
    }

    public function test_full_lifecycle_with_approval_by_department_manager(): void
    {
        $department = Department::factory()->create();
        $analyst = $this->actingAsRole(Role::HrAnalyst);
        $id = $this->postJson('/api/v1/jobs', $this->payload($department))->json('data.id');

        $this->postJson("/api/v1/jobs/$id/transition", ['action' => 'approve'])->assertForbidden();
        $this->postJson("/api/v1/jobs/$id/transition", ['action' => 'submit'])->assertOk()->assertJsonPath('data.status', 'pending_approval');

        $manager = $this->actingAsRole(Role::DepartmentManager, ['department_id' => $department->id]);
        $this->getJson("/api/v1/jobs/$id")->assertJsonPath('data.abilities.actions.0.action', 'approve');
        $this->postJson("/api/v1/jobs/$id/transition", ['action' => 'reject'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/jobs/$id/transition", ['action' => 'reject', 'reason' => 'Ajustar a faixa salarial'])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->actingAs($analyst);
        $this->putJson("/api/v1/jobs/$id", $this->payload($department, ['salary_max' => 3500]))->assertOk();
        $this->postJson("/api/v1/jobs/$id/transition", ['action' => 'submit'])->assertOk();

        $this->actingAs($manager);
        $this->postJson("/api/v1/jobs/$id/transition", ['action' => 'approve'])->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approver.id', $manager->uuid);
        $this->postJson("/api/v1/jobs/$id/transition", ['action' => 'publish'])->assertForbidden();

        $this->actingAs($analyst);
        $this->putJson("/api/v1/jobs/$id", $this->payload($department))->assertForbidden();

        $this->actingAsRole(Role::HrManager);
        $this->postJson("/api/v1/jobs/$id/transition", ['action' => 'publish'])->assertOk()->assertJsonPath('data.status', 'published');
        $this->postJson("/api/v1/jobs/$id/transition", ['action' => 'suspend', 'reason' => 'Revisão do orçamento'])->assertOk()->assertJsonPath('data.status', 'on_hold');
        $this->postJson("/api/v1/jobs/$id/transition", ['action' => 'publish'])->assertOk();
        $this->postJson("/api/v1/jobs/$id/transition", ['action' => 'close', 'reason' => 'Processo concluído'])->assertOk()->assertJsonPath('data.status', 'closed');
        $this->putJson("/api/v1/jobs/$id", $this->payload($department))->assertForbidden();
        $this->postJson("/api/v1/jobs/$id/transition", ['action' => 'reopen', 'reason' => 'Nova demanda'])->assertOk()->assertJsonPath('data.status', 'published');

        $job = JobOpening::where('uuid', $id)->first();
        $this->assertSame(
            ['draft', 'pending_approval', 'rejected', 'pending_approval', 'approved', 'published', 'on_hold', 'published', 'closed', 'published'],
            $job->statusHistory()->reorder('id')->pluck('to_status')->map->value->all(),
        );

        $history = $this->getJson("/api/v1/jobs/$id/history")->assertOk();
        $this->assertContains('Devolvida sem aprovação', collect($history->json('data'))->pluck('event_label')->all());
    }

    public function test_requester_cannot_approve_own_job(): void
    {
        $department = Department::factory()->create();
        $this->actingAsRole(Role::DepartmentManager, ['department_id' => $department->id]);
        $id = $this->postJson('/api/v1/jobs', $this->payload($department))->json('data.id');
        $this->postJson("/api/v1/jobs/$id/transition", ['action' => 'submit'])->assertOk();

        $this->postJson("/api/v1/jobs/$id/transition", ['action' => 'approve'])->assertForbidden();
        $this->postJson("/api/v1/jobs/$id/transition", ['action' => 'cancel', 'reason' => 'Não é mais necessária'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_status_history_is_immutable(): void
    {
        $job = JobOpening::factory()->create();
        $entry = $job->statusHistory()->create(['to_status' => JobStatus::Draft]);

        DB::table('job_status_history')->where('id', $entry->id)->update(['to_status' => 'published']);
        $this->assertSame('draft', DB::table('job_status_history')->where('id', $entry->id)->value('to_status'));
    }

    public function test_department_manager_sees_only_own_department_jobs(): void
    {
        $mine = Department::factory()->create();
        JobOpening::factory()->create(['department_id' => $mine->id, 'title' => 'Minha vaga']);
        $other = JobOpening::factory()->create();
        $this->actingAsRole(Role::DepartmentManager, ['department_id' => $mine->id]);

        $this->getJson('/api/v1/jobs')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Minha vaga');
        $this->getJson("/api/v1/jobs/{$other->uuid}")->assertForbidden();
    }

    public function test_listing_filters(): void
    {
        JobOpening::factory()->published()->create(['title' => 'Enfermeiro Assistencial']);
        JobOpening::factory()->create(['title' => 'Analista Financeiro']);
        $this->actingAsRole(Role::Recruiter);

        $this->getJson('/api/v1/jobs?search=enfermeiro')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/jobs?status[]=draft')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Analista Financeiro');
        $this->postJson('/api/v1/jobs', [])->assertForbidden();
    }

    public function test_interviewer_has_no_access(): void
    {
        $this->actingAsRole(Role::Interviewer);
        $this->getJson('/api/v1/jobs')->assertForbidden();
    }

    public function test_publish_blocked_when_deadline_passed(): void
    {
        $job = JobOpening::factory()->status(JobStatus::Approved)->create(['closes_on' => now()->subDays(2)]);
        $this->actingAsRole(Role::HrManager);

        $this->postJson("/api/v1/jobs/{$job->uuid}/transition", ['action' => 'publish'])->assertUnprocessable();
    }

    public function test_options_and_filters_use_public_ids(): void
    {
        $department = Department::factory()->create(['name' => 'Centro Cirúrgico']);
        $position = Position::factory()->create(['title' => 'Instrumentador']);
        JobOpening::factory()->create(['department_id' => $department->id, 'position_id' => $position->id]);
        JobOpening::factory()->create();
        $this->actingAsRole(Role::HrManager);

        $options = $this->getJson('/api/v1/jobs/options')->assertOk()->json('data');
        $this->assertContains(['id' => $position->uuid, 'name' => 'Instrumentador'], $options['positions']);
        $this->assertContains(['id' => $department->uuid, 'name' => 'Centro Cirúrgico'], $options['departments']);

        $this->getJson("/api/v1/jobs?department_id={$department->uuid}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.department.id', $department->uuid);
        $id = $this->getJson("/api/v1/jobs?department_id={$department->uuid}")->json('data.0.id');
        $this->getJson("/api/v1/jobs/$id")->assertJsonPath('data.position.name', 'Instrumentador');
    }
}

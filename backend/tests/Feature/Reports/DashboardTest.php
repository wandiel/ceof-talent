<?php

namespace Tests\Feature\Reports;

use App\Domain\Candidates\Enums\CandidateSource;
use App\Domain\Identity\Enums\Role;
use App\Domain\Recruitment\Enums\JobStatus;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\Department;
use App\Models\JobOpening;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use InteractsWithIdentity;

    private function move(Application $application, string $stage): void
    {
        $this->postJson("/api/v1/applications/{$application->uuid}/move", ['stage' => $stage])->assertOk();
    }

    /** Cenário: dois departamentos, vagas abertas e encerradas, candidatos em várias etapas. */
    private function scenario(): array
    {
        $nursing = Department::factory()->create(['name' => 'Enfermagem']);
        $admin = Department::factory()->create(['name' => 'Administrativo']);
        $jobA = JobOpening::factory()->published()->create(['department_id' => $nursing->id, 'positions_count' => 3, 'professional_area' => 'nursing']);
        $jobB = JobOpening::factory()->published()->create(['department_id' => $admin->id, 'professional_area' => 'administrative']);
        JobOpening::factory()->status(JobStatus::Closed)->create(['department_id' => $nursing->id]);
        JobOpening::factory()->status(JobStatus::Draft)->create(['department_id' => $admin->id]);

        $apps = collect([
            Application::factory()->create(['job_opening_id' => $jobA->id, 'candidate_id' => Candidate::factory()->create(['professional_area' => 'nursing', 'source' => CandidateSource::Portal])->id]),
            Application::factory()->create(['job_opening_id' => $jobA->id, 'candidate_id' => Candidate::factory()->create(['professional_area' => 'nursing'])->id]),
            Application::factory()->create(['job_opening_id' => $jobA->id, 'candidate_id' => Candidate::factory()->create(['professional_area' => 'nursing'])->id]),
            Application::factory()->create(['job_opening_id' => $jobB->id, 'candidate_id' => Candidate::factory()->create(['professional_area' => 'administrative'])->id]),
        ]);
        Candidate::factory()->inactive()->create(['professional_area' => 'administrative']);
        Candidate::factory()->create(['professional_area' => 'nursing', 'created_at' => now()->subYears(2)]);

        return compact('nursing', 'admin', 'jobA', 'jobB', 'apps');
    }

    public function test_dashboard_kpis_funnel_and_breakdowns(): void
    {
        $this->actingAsRole(Role::HrManager);
        ['apps' => $apps, 'nursing' => $nursing] = $this->scenario();
        $this->move($apps[0], 'screening');
        $this->move($apps[0], 'interview');
        $this->move($apps[0], 'approved');
        $this->move($apps[0], 'hired');
        $this->move($apps[1], 'screening');
        $this->postJson("/api/v1/applications/{$apps[2]->uuid}/move", ['stage' => 'rejected', 'reason' => 'profile_mismatch'])->assertOk();

        $from = now()->subMonths(11)->startOfMonth()->toDateString();
        $r = $this->getJson("/api/v1/dashboard?from={$from}&to=".now()->toDateString())->assertOk();

        $r->assertJsonPath('data.kpis.total_candidates', 6)
            ->assertJsonPath('data.kpis.new_candidates', 5)
            ->assertJsonPath('data.kpis.active_candidates', 5)
            ->assertJsonPath('data.kpis.open_jobs', 2)
            ->assertJsonPath('data.kpis.open_positions', 4)
            ->assertJsonPath('data.kpis.active_processes', 2)
            ->assertJsonPath('data.kpis.approvals', 1)
            ->assertJsonPath('data.kpis.hires', 1)
            ->assertJsonCount(12, 'data.monthly');

        $this->assertSame(4, collect($r->json('data.monthly'))->sum('applications'));
        $this->assertSame(1, collect($r->json('data.monthly'))->sum('hires'));

        $funnel = collect($r->json('data.funnel.stages'))->pluck('count', 'stage');
        $this->assertSame(['applied' => 4, 'screening' => 2, 'shortlisted' => 1, 'interview' => 1, 'technical' => 1, 'approved' => 1, 'hired' => 1], $funnel->all());
        $r->assertJsonPath('data.funnel.rejected', 1);

        $dept = collect($r->json('data.by_department'))->keyBy('name');
        $this->assertSame(['id' => $nursing->uuid, 'name' => 'Enfermagem', 'open_jobs' => 1, 'applications' => 3, 'hires' => 1], $dept['Enfermagem']);
        $this->assertSame(4, collect($r->json('data.by_area'))->firstWhere('area', 'nursing')['count']);

        // Filtro por departamento
        $this->getJson("/api/v1/dashboard?department={$nursing->uuid}")->assertOk()
            ->assertJsonPath('data.kpis.open_jobs', 1)
            ->assertJsonPath('data.kpis.total_candidates', 3);
    }

    public function test_department_manager_is_locked_to_own_department(): void
    {
        ['nursing' => $nursing, 'admin' => $admin] = $this->scenario();
        $this->actingAsRole(Role::DepartmentManager, ['department_id' => $admin->id]);

        $this->getJson("/api/v1/dashboard?department={$nursing->uuid}")->assertOk()
            ->assertJsonPath('meta.department_locked', true)
            ->assertJsonPath('meta.filters.department', $admin->uuid)
            ->assertJsonPath('data.kpis.open_jobs', 1)
            ->assertJsonPath('data.kpis.total_candidates', 1);
    }

    public function test_requires_dashboard_permission_and_valid_filters(): void
    {
        $this->actingAsRole(Role::Interviewer);
        $this->getJson('/api/v1/dashboard')->assertForbidden();

        $this->actingAsRole(Role::HrAnalyst);
        $this->getJson('/api/v1/dashboard?from=2026-05-10&to=2026-01-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/api/v1/dashboard?area=astronauta')->assertUnprocessable()->assertJsonValidationErrors('area');
    }
}

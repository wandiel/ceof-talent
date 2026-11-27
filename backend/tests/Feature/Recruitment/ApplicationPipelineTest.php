<?php

namespace Tests\Feature\Recruitment;

use App\Domain\Identity\Enums\Role;
use App\Domain\Recruitment\Enums\ApplicationStage;
use App\Domain\Recruitment\Enums\JobStatus;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\Department;
use App\Models\JobOpening;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class ApplicationPipelineTest extends TestCase
{
    use InteractsWithIdentity;

    public function test_recruiter_adds_candidates_skipping_duplicates_and_inactive(): void
    {
        $job = JobOpening::factory()->published()->create();
        [$a, $b] = Candidate::factory()->count(2)->create();
        $inactive = Candidate::factory()->inactive()->create();
        $this->actingAsRole(Role::Recruiter);

        $this->postJson("/api/v1/jobs/{$job->uuid}/applications", ['candidates' => [$a->uuid, $b->uuid, $inactive->uuid]])
            ->assertCreated()->assertJsonPath('meta.added', 2)->assertJsonPath('meta.inactive.0', $inactive->full_name);
        $this->postJson("/api/v1/jobs/{$job->uuid}/applications", ['candidates' => [$a->uuid]])
            ->assertOk()->assertJsonPath('meta.added', 0)->assertJsonPath('meta.already.0', $a->full_name);

        $this->getJson("/api/v1/jobs/{$job->uuid}/applications")->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.counts.applied', 2);
        $this->getJson("/api/v1/candidates/{$a->uuid}/history")->assertJsonPath('data.0.event_label', 'Inscrito em vaga');
    }

    public function test_cannot_add_candidates_to_job_not_yet_approved(): void
    {
        $job = JobOpening::factory()->status(JobStatus::PendingApproval)->create();
        $this->actingAsRole(Role::Recruiter);

        $this->postJson("/api/v1/jobs/{$job->uuid}/applications", ['candidates' => [Candidate::factory()->create()->uuid]])->assertForbidden();
    }

    public function test_moves_through_the_pipeline_with_history(): void
    {
        $application = Application::factory()->create();
        $this->actingAsRole(Role::Recruiter);

        foreach (['screening', 'shortlisted', 'interview', 'technical'] as $stage) {
            $this->postJson("/api/v1/applications/{$application->uuid}/move", ['stage' => $stage])->assertOk()->assertJsonPath('data.stage', $stage);
        }
        $this->postJson("/api/v1/applications/{$application->uuid}/move", ['stage' => 'technical'])->assertUnprocessable();

        // Recrutador não decide: aprovar exige recruitment.decide.
        $this->postJson("/api/v1/applications/{$application->uuid}/move", ['stage' => 'approved'])->assertForbidden();

        $this->getJson("/api/v1/applications/{$application->uuid}")->assertOk()
            ->assertJsonCount(4, 'data.history')
            ->assertJsonPath('data.history.0.to_stage', 'technical')
            ->assertJsonPath('data.history.0.from_stage', 'interview');
    }

    public function test_rejection_requires_a_reason(): void
    {
        $application = Application::factory()->stage(ApplicationStage::Interview)->create();
        $this->actingAsRole(Role::Recruiter);

        $this->postJson("/api/v1/applications/{$application->uuid}/move", ['stage' => 'rejected'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/applications/{$application->uuid}/move", ['stage' => 'rejected', 'reason' => 'salary', 'note' => 'Pretensão acima da faixa'])
            ->assertOk()->assertJsonPath('data.rejection_reason_label', 'Pretensão salarial')
            ->assertJsonPath('data.history.0.note', 'Pretensão acima da faixa');

        // Reabrir uma inscrição reprovada é decisão do RH/gestor.
        $this->postJson("/api/v1/applications/{$application->uuid}/move", ['stage' => 'screening'])->assertForbidden();
        $this->actingAsRole(Role::HrManager);
        $this->postJson("/api/v1/applications/{$application->uuid}/move", ['stage' => 'screening'])->assertOk()->assertJsonPath('data.rejection_reason', null);
    }

    public function test_withdrawal_needs_no_reason(): void
    {
        $application = Application::factory()->stage(ApplicationStage::Screening)->create();
        $this->actingAsRole(Role::Recruiter);

        $this->postJson("/api/v1/applications/{$application->uuid}/move", ['stage' => 'withdrawn'])->assertOk();
    }

    public function test_hiring_fills_positions_and_closes_the_job(): void
    {
        $job = JobOpening::factory()->published()->create(['positions_count' => 2]);
        [$first, $second, $third] = Application::factory()->count(3)->stage(ApplicationStage::Approved)->create(['job_opening_id' => $job->id]);
        $technical = Application::factory()->stage(ApplicationStage::Technical)->create(['job_opening_id' => $job->id]);
        $this->actingAsRole(Role::HrManager);

        $this->postJson("/api/v1/applications/{$technical->uuid}/move", ['stage' => 'hired'])->assertUnprocessable();

        $this->postJson("/api/v1/applications/{$first->uuid}/move", ['stage' => 'hired'])->assertOk()->assertJsonPath('meta.job_closed', false);
        $this->postJson("/api/v1/applications/{$second->uuid}/move", ['stage' => 'hired'])->assertOk()->assertJsonPath('meta.job_closed', true);

        $job->refresh();
        $this->assertSame(JobStatus::Closed, $job->status);
        $this->assertSame('Todas as posições foram preenchidas.', $job->status_reason);
        $this->assertNotNull($first->fresh()->hired_at);

        // Vaga encerrada congela as inscrições.
        $this->postJson("/api/v1/applications/{$third->uuid}/move", ['stage' => 'rejected', 'reason' => 'other'])->assertUnprocessable();
        // E não pode ser reaberta, pois todas as posições estão preenchidas.
        $this->postJson("/api/v1/jobs/{$job->uuid}/transition", ['action' => 'reopen', 'reason' => 'Teste'])->assertForbidden();
    }

    public function test_positions_cannot_go_below_hires(): void
    {
        $job = JobOpening::factory()->published()->create(['positions_count' => 3]);
        Application::factory()->count(2)->stage(ApplicationStage::Hired)->create(['job_opening_id' => $job->id]);
        $this->actingAsRole(Role::HrManager);

        $data = $this->getJson("/api/v1/jobs/{$job->uuid}")->json('data');
        $payload = [
            'title' => $data['title'], 'department_id' => $data['department']['id'], 'professional_area' => $data['professional_area'],
            'employment_type' => $data['employment_type'], 'reason' => $data['reason'], 'description' => $data['description'], 'positions_count' => 1,
        ];
        $this->putJson("/api/v1/jobs/{$job->uuid}", $payload)->assertUnprocessable()->assertJsonValidationErrors('positions_count');
    }

    public function test_department_manager_decides_only_on_own_department(): void
    {
        $department = Department::factory()->create();
        $own = Application::factory()->stage(ApplicationStage::Technical)->create(['job_opening_id' => JobOpening::factory()->published()->create(['department_id' => $department->id])->id]);
        $foreign = Application::factory()->stage(ApplicationStage::Technical)->create();
        $this->actingAsRole(Role::DepartmentManager, ['department_id' => $department->id]);

        $this->postJson("/api/v1/applications/{$own->uuid}/move", ['stage' => 'approved'])->assertOk();
        $this->postJson("/api/v1/applications/{$own->uuid}/move", ['stage' => 'interview'])->assertForbidden(); // não movimenta etapas intermediárias
        $this->postJson("/api/v1/applications/{$foreign->uuid}/move", ['stage' => 'approved'])->assertForbidden();

        // Agora o gestor enxerga o candidato inscrito na vaga do seu departamento.
        $this->getJson("/api/v1/candidates/{$own->candidate->uuid}")->assertOk();
        $this->getJson("/api/v1/candidates/{$foreign->candidate->uuid}")->assertForbidden();
        $this->getJson("/api/v1/candidates/{$own->candidate->uuid}/applications")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_stage_history_is_immutable(): void
    {
        $application = Application::factory()->create();
        $entry = $application->history()->create(['to_stage' => ApplicationStage::Applied]);

        DB::table('application_stage_history')->where('id', $entry->id)->update(['to_stage' => 'hired']);
        $this->assertSame('applied', DB::table('application_stage_history')->where('id', $entry->id)->value('to_stage'));
    }
}

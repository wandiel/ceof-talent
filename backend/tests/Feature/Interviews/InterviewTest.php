<?php

namespace Tests\Feature\Interviews;

use App\Domain\Identity\Enums\Role;
use App\Domain\Recruitment\Enums\ApplicationStage;
use App\Domain\Recruitment\Enums\JobStatus;
use App\Models\Application;
use App\Models\Department;
use App\Models\EvaluationCriterion;
use App\Models\Interview;
use App\Models\JobOpening;
use App\Models\User;
use App\Notifications\InterviewNotification;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class InterviewTest extends TestCase
{
    use InteractsWithIdentity;

    private function payload(array $interviewers, array $overrides = []): array
    {
        return array_merge([
            'type' => 'hr',
            'mode' => 'in_person',
            'scheduled_at' => now()->addDays(2)->setTime(10, 0)->toIso8601String(),
            'duration_minutes' => 60,
            'location' => 'Sala de reuniões do RH',
            'interviewers' => array_map(fn (User $u) => $u->uuid, $interviewers),
            'move_to_stage' => true,
        ], $overrides);
    }

    private function schedule(Application $application, array $interviewers, array $overrides = [])
    {
        return $this->postJson("/api/v1/applications/{$application->uuid}/interviews", $this->payload($interviewers, $overrides));
    }

    public function test_schedules_interview_moves_stage_and_notifies(): void
    {
        Notification::fake();
        $application = Application::factory()->stage(ApplicationStage::Shortlisted)->create();
        $interviewer = $this->userWithRole(Role::Interviewer);
        $recruiter = $this->userWithRole(Role::Recruiter);
        $this->actingAsRole(Role::HrAnalyst);

        $this->schedule($application, [$interviewer, $recruiter])->assertCreated()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.application.stage', 'interview')
            ->assertJsonCount(2, 'data.interviewers')
            ->assertJsonCount(EvaluationCriterion::where('is_active', true)->count(), 'data.criteria');

        Notification::assertSentTo([$interviewer, $recruiter], InterviewNotification::class, fn ($n) => $n->kind === 'scheduled');
        $this->getJson("/api/v1/candidates/{$application->candidate->uuid}/history")->assertJsonPath('data.0.event_label', 'Etapa do processo alterada')
            ->assertJsonPath('data.1.event_label', 'Entrevista agendada');
    }

    public function test_utc_time_from_browser_is_stored_as_the_same_instant(): void
    {
        $application = Application::factory()->create();
        $this->actingAsRole(Role::HrAnalyst);
        $utc = now('UTC')->addDays(3)->setTime(13, 0);

        $this->schedule($application, [$this->userWithRole(Role::Interviewer)], ['scheduled_at' => $utc->format('Y-m-d\\TH:i:s.v\\Z')])->assertCreated();

        $this->assertTrue(Interview::sole()->scheduled_at->equalTo($utc));
        $this->assertSame('09:00', Interview::sole()->scheduled_at->timezone('America/Manaus')->format('H:i'));
    }

    public function test_validation_and_eligibility(): void
    {
        $application = Application::factory()->create();
        $inactive = $this->userWithRole(Role::Interviewer, ['is_active' => false]);
        $this->actingAsRole(Role::HrAnalyst);

        $this->schedule($application, [$inactive])->assertUnprocessable()->assertJsonValidationErrors('interviewers');
        $this->schedule($application, [$this->userWithRole(Role::Interviewer)], ['scheduled_at' => now()->subHour()->toIso8601String()])
            ->assertUnprocessable()->assertJsonValidationErrors('scheduled_at');
        $this->schedule($application, [$this->userWithRole(Role::Interviewer)], ['location' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('location');
        $this->schedule($application, [$this->userWithRole(Role::Interviewer)], ['mode' => 'video', 'location' => null, 'meeting_url' => 'javascript:alert(1)'])
            ->assertUnprocessable()->assertJsonValidationErrors('meeting_url');
    }

    public function test_cannot_schedule_for_closed_job_or_finished_application(): void
    {
        $interviewer = $this->userWithRole(Role::Interviewer);
        $closed = Application::factory()->create(['job_opening_id' => JobOpening::factory()->status(JobStatus::Closed)->create()->id]);
        $rejected = Application::factory()->stage(ApplicationStage::Rejected)->create();
        $this->actingAsRole(Role::HrManager);

        $this->schedule($closed, [$interviewer])->assertUnprocessable()->assertJsonValidationErrors('application');
        $this->schedule($rejected, [$interviewer])->assertUnprocessable()->assertJsonValidationErrors('application');
    }

    public function test_conflicting_schedule_requires_confirmation(): void
    {
        Notification::fake();
        $interviewer = $this->userWithRole(Role::Interviewer, ['name' => 'Paula Reis']);
        [$first, $second] = Application::factory()->count(2)->create();
        $this->actingAsRole(Role::HrManager);

        $this->schedule($first, [$interviewer])->assertCreated();
        $response = $this->schedule($second, [$interviewer], ['scheduled_at' => now()->addDays(2)->setTime(10, 30)->toIso8601String()])
            ->assertUnprocessable()->assertJsonValidationErrors('conflicts');
        $this->assertStringContainsString('Paula Reis já tem entrevista', $response->json('errors.conflicts.0'));

        // Logo depois do término não é conflito.
        $this->schedule($second, [$interviewer], ['scheduled_at' => now()->addDays(2)->setTime(11, 0)->toIso8601String()])->assertCreated();
        $this->schedule(Application::factory()->create(), [$interviewer], ['scheduled_at' => now()->addDays(2)->setTime(10, 15)->toIso8601String(), 'force' => true])->assertCreated();
    }

    public function test_interviewer_sees_only_own_interviews_and_their_candidates(): void
    {
        Notification::fake();
        $interviewer = $this->userWithRole(Role::Interviewer);
        $mine = Application::factory()->create();
        $other = Application::factory()->create();
        $this->actingAsRole(Role::HrManager);
        $this->schedule($mine, [$interviewer])->assertCreated();
        $this->schedule($other, [$this->userWithRole(Role::Recruiter)])->assertCreated();

        $this->actingAs($interviewer);
        $this->getJson('/api/v1/interviews')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.candidate.id', $mine->candidate->uuid)
            ->assertJsonPath('data.0.my_evaluation_pending', true);
        $this->getJson("/api/v1/candidates/{$mine->candidate->uuid}")->assertOk();
        $this->getJson("/api/v1/candidates/{$other->candidate->uuid}")->assertForbidden();
        $this->getJson('/api/v1/candidates')->assertOk()->assertJsonCount(1, 'data');
        $this->schedule($mine, [$interviewer])->assertForbidden();
        $this->getJson("/api/v1/interviews/{$other->interviews()->first()->uuid}")->assertForbidden();
    }

    public function test_evaluation_flow_and_blind_review(): void
    {
        Notification::fake();
        $a = $this->userWithRole(Role::Interviewer);
        $b = $this->userWithRole(Role::Recruiter);
        $application = Application::factory()->create();
        $this->actingAsRole(Role::HrManager);
        $id = $this->schedule($application, [$a, $b])->json('data.id');
        $criteria = Interview::where('uuid', $id)->first()->criteria;
        $scores = fn (int $score) => array_map(fn ($c) => ['criterion' => $c['id'], 'score' => $score], $criteria);
        $evaluation = fn (int $score) => ['recommendation' => 'yes', 'summary' => 'Boa comunicação e experiência compatível.', 'scores' => $scores($score)];

        $this->actingAs($a);
        $this->putJson("/api/v1/interviews/$id/evaluation", $evaluation(4))->assertUnprocessable()->assertJsonValidationErrors('status'); // ainda não aconteceu

        $this->travel(3)->days();
        $this->actingAs($this->userWithRole(Role::Interviewer));
        $this->putJson("/api/v1/interviews/$id/evaluation", $evaluation(4))->assertForbidden(); // não é entrevistador desta entrevista

        $this->actingAs($a);
        $partial = $evaluation(4);
        array_pop($partial['scores']);
        $this->putJson("/api/v1/interviews/$id/evaluation", $partial)->assertUnprocessable()->assertJsonValidationErrors('scores');
        $this->putJson("/api/v1/interviews/$id/evaluation", array_merge($evaluation(4), ['scores' => array_map(fn ($s) => ['score' => 6] + $s, $scores(4))]))
            ->assertUnprocessable()->assertJsonValidationErrors('scores.0.score');

        $this->putJson("/api/v1/interviews/$id/evaluation", $evaluation(4))->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.evaluations.0.average_score', '4.00')
            ->assertJsonPath('data.evaluations.0.is_mine', true);

        // B ainda não registrou: não vê o parecer de A.
        $this->actingAs($b);
        $this->getJson("/api/v1/interviews/$id")->assertJsonCount(0, 'data.evaluations')->assertJsonPath('data.evaluations_hidden', true);
        $this->putJson("/api/v1/interviews/$id/evaluation", array_merge($evaluation(2), ['recommendation' => 'no']))->assertOk()->assertJsonCount(2, 'data.evaluations');

        // A corrige o próprio parecer.
        $this->actingAs($a);
        $this->putJson("/api/v1/interviews/$id/evaluation", $evaluation(5))->assertOk();

        $this->actingAsRole(Role::HrAnalyst);
        $this->getJson("/api/v1/interviews/$id")->assertJsonCount(2, 'data.evaluations')->assertJsonPath('data.evaluations_hidden', false);
        $labels = collect($this->getJson("/api/v1/interviews/$id/history")->json('data'))->pluck('event_label')->all();
        $this->assertSame(['Parecer corrigido', 'Parecer registrado', 'Parecer registrado', 'Entrevista agendada'], $labels);
    }

    public function test_reschedule_notifies_and_resets_reminder(): void
    {
        Notification::fake();
        [$keep, $remove, $add] = [$this->userWithRole(Role::Interviewer), $this->userWithRole(Role::Interviewer), $this->userWithRole(Role::Interviewer)];
        $application = Application::factory()->create();
        $this->actingAsRole(Role::HrManager);
        $id = $this->schedule($application, [$keep, $remove])->json('data.id');
        Interview::where('uuid', $id)->update(['reminded_at' => now()]);

        $this->putJson("/api/v1/interviews/$id", $this->payload([$keep, $add], ['scheduled_at' => now()->addDays(4)->setTime(14, 0)->toIso8601String()]))
            ->assertUnprocessable()->assertJsonValidationErrors('move_to_stage'); // só no agendamento
        $payload = $this->payload([$keep, $add], ['scheduled_at' => now()->addDays(4)->setTime(14, 0)->toIso8601String()]);
        unset($payload['move_to_stage']);
        $this->putJson("/api/v1/interviews/$id", $payload)->assertOk()->assertJsonCount(2, 'data.interviewers');

        Notification::assertSentTo($keep, InterviewNotification::class, fn ($n) => $n->kind === 'rescheduled');
        Notification::assertSentTo($add, InterviewNotification::class, fn ($n) => $n->kind === 'scheduled');
        Notification::assertSentTo($remove, InterviewNotification::class, fn ($n) => $n->kind === 'cancelled');
        $this->assertNull(Interview::where('uuid', $id)->value('reminded_at'));
    }

    public function test_cancel_and_no_show(): void
    {
        Notification::fake();
        $interviewer = $this->userWithRole(Role::Interviewer);
        $this->actingAsRole(Role::HrManager);
        $first = $this->schedule(Application::factory()->create(), [$interviewer])->json('data.id');
        $second = $this->schedule(Application::factory()->create(), [$interviewer], ['scheduled_at' => now()->addDays(3)->toIso8601String()])->json('data.id');

        $this->postJson("/api/v1/interviews/$first/cancel")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/interviews/$first/cancel", ['reason' => 'Candidato pediu para remarcar'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/v1/interviews/$first/cancel", ['reason' => 'De novo'])->assertUnprocessable();
        Notification::assertSentTo($interviewer, InterviewNotification::class, fn ($n) => $n->kind === 'cancelled');

        $this->postJson("/api/v1/interviews/$second/no-show")->assertUnprocessable(); // ainda não aconteceu
        $this->travel(4)->days();
        $this->postJson("/api/v1/interviews/$second/no-show", ['note' => 'Não atendeu o telefone'])->assertOk()->assertJsonPath('data.status', 'no_show');

        $this->actingAs($interviewer);
        $this->putJson("/api/v1/interviews/$second/evaluation", ['recommendation' => 'no', 'summary' => 'Não compareceu à entrevista.', 'scores' => []])
            ->assertUnprocessable();
    }

    public function test_reminders_are_sent_once_for_next_day(): void
    {
        Notification::fake();
        $interviewer = $this->userWithRole(Role::Interviewer);
        $this->actingAsRole(Role::HrManager);
        $this->schedule(Application::factory()->create(), [$interviewer], ['scheduled_at' => now()->addHours(10)->toIso8601String()])->assertCreated();
        $this->schedule(Application::factory()->create(), [$interviewer], ['scheduled_at' => now()->addDays(3)->toIso8601String()])->assertCreated();

        $this->artisan('interviews:send-reminders')->expectsOutput('Lembretes enviados: 1.')->assertSuccessful();
        $this->artisan('interviews:send-reminders')->expectsOutput('Lembretes enviados: 0.')->assertSuccessful();
        Notification::assertSentToTimes($interviewer, InterviewNotification::class, 3); // 2 agendamentos + 1 lembrete
    }

    public function test_department_manager_schedules_only_in_own_department(): void
    {
        Notification::fake();
        $department = Department::factory()->create();
        $own = Application::factory()->create(['job_opening_id' => JobOpening::factory()->published()->create(['department_id' => $department->id])->id]);
        $foreign = Application::factory()->create();
        $interviewer = $this->userWithRole(Role::Interviewer);
        $this->actingAsRole(Role::DepartmentManager, ['department_id' => $department->id]);

        // O gestor não movimenta etapas: agenda, mas a inscrição continua onde estava.
        $this->schedule($own, [$interviewer])->assertCreated()->assertJsonPath('data.application.stage', 'applied');
        $this->schedule($foreign, [$interviewer])->assertForbidden();
        $this->getJson('/api/v1/interviews')->assertJsonCount(1, 'data');
    }

    public function test_criteria_catalog_and_snapshot(): void
    {
        Notification::fake();
        $this->actingAsRole(Role::HrAnalyst);
        $this->postJson('/api/v1/evaluation-criteria', ['name' => 'Proatividade'])->assertForbidden();
        $this->getJson('/api/v1/evaluation-criteria')->assertOk();

        $this->actingAsRole(Role::HrManager);
        $this->postJson('/api/v1/evaluation-criteria', ['name' => 'comunicação'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $created = $this->postJson('/api/v1/evaluation-criteria', ['name' => 'Proatividade', 'description' => 'Iniciativa'])->assertCreated()->json('data.id');

        $application = Application::factory()->create();
        $id = $this->schedule($application, [$this->userWithRole(Role::Interviewer)], ['criteria' => [$created]])
            ->assertCreated()->assertJsonCount(1, 'data.criteria')->json('data.id');

        $this->putJson("/api/v1/evaluation-criteria/$created", ['name' => 'Iniciativa', 'is_active' => false])->assertOk();
        $this->getJson("/api/v1/interviews/$id")->assertJsonPath('data.criteria.0.name', 'Proatividade');
        $this->getJson('/api/v1/interviews/options')->assertJsonMissing(['name' => 'Iniciativa']);
    }
}

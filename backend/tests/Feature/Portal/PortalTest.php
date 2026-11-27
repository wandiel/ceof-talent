<?php

namespace Tests\Feature\Portal;

use App\Domain\Identity\Enums\Role;
use App\Domain\Recruitment\Enums\JobStatus;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateConsent;
use App\Models\JobOpening;
use App\Models\PortalSubmission;
use App\Notifications\PortalSubmissionReceived;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Support\Files;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use InteractsWithIdentity;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        if ($this->name() !== 'test_rate_limit') {
            $this->withoutMiddleware(ThrottleRequests::class);
        }
    }

    private function resume(string $name = 'curriculo.pdf', string $text = 'Técnica de enfermagem com experiência em centro cirúrgico'): UploadedFile
    {
        return Files::pdfWithText([$text], $name);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Maria  Clara Andrade',
            'email' => 'Maria@Exemplo.com',
            'phone' => '(92) 98888-7777',
            'city' => 'Manaus',
            'state' => 'am',
            'professional_area' => 'nursing',
            'education_level' => 'technical',
            'desired_position' => 'Técnica de enfermagem',
            'privacy_accepted' => '1',
            'talent_pool_consent' => '1',
            'resume' => $this->resume(),
        ], $overrides);
    }

    public function test_lists_only_open_public_jobs_without_internal_data(): void
    {
        $open = JobOpening::factory()->published()->create(['title' => 'Técnico de Enfermagem', 'salary_min' => 2500, 'show_salary' => false, 'replaced_employee' => 'Fulano']);
        JobOpening::factory()->published()->create(['is_public' => false]);
        JobOpening::factory()->published()->create(['closes_on' => now()->subDay()]);
        JobOpening::factory()->status(JobStatus::OnHold)->create();
        JobOpening::factory()->status(JobStatus::Draft)->create();

        $r = $this->getJson('/api/v1/public/jobs')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $open->uuid)->assertJsonPath('data.0.salary', null);
        $this->assertStringNotContainsString('Fulano', $r->getContent());
        $this->assertStringNotContainsString('2500', $r->getContent());

        $this->getJson('/api/v1/public/jobs?search=tecnico')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/public/jobs?search=medico')->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/public/jobs/{$open->uuid}")->assertOk()->assertJsonStructure(['data' => ['description', 'requirements']]);
        $this->getJson('/api/v1/public/jobs/'.JobOpening::where('is_public', false)->first()->uuid)->assertNotFound();
        $this->getJson('/api/v1/public/options')->assertOk()->assertJsonPath('data.privacy.version', config('privacy.notice_version'));
    }

    public function test_new_candidate_applies_to_job(): void
    {
        $job = JobOpening::factory()->published()->create();

        $r = $this->post('/api/v1/public/applications', $this->payload(['job' => $job->uuid]), ['Accept' => 'application/json'])->assertCreated();
        $protocol = $r->json('data.protocol');
        $this->assertMatchesRegularExpression('/^TC-'.now()->format('ymd').'-[A-Z2-9]{6}$/', $protocol);

        $candidate = Candidate::sole();
        $this->assertSame('Maria Clara Andrade', $candidate->full_name);
        $this->assertSame('maria@exemplo.com', $candidate->email);
        $this->assertSame('92988887777', $candidate->phone);
        $this->assertSame('portal', $candidate->source->value);
        $this->assertNull($candidate->created_by);
        $this->assertCount(1, $candidate->documents);
        $this->assertSame('resume', $candidate->documents->first()->type->value);

        $application = Application::sole();
        $this->assertSame($job->id, $application->job_opening_id);
        $this->assertSame('portal', $application->source);

        $this->assertEqualsCanonicalizing(['privacy_notice', 'talent_pool'], CandidateConsent::pluck('purpose')->map->value->all());
        $submission = PortalSubmission::sole();
        $this->assertTrue($submission->is_new_candidate);
        $this->assertSame('created', $submission->outcome);
        $this->assertArrayNotHasKey('cpf', $submission->payload);

        Notification::assertSentTo(new AnonymousNotifiable, PortalSubmissionReceived::class, fn ($n, $ch, $notifiable) => $notifiable->routes['mail'] === ['maria@exemplo.com' => 'Maria Clara Andrade'] && $n->protocol === $protocol);

        // RH vê o envio no histórico
        $this->actingAsRole(Role::HrAnalyst);
        $this->getJson("/api/v1/candidates/{$candidate->uuid}/history")->assertOk()
            ->assertJsonFragment(['event_label' => 'Envio pelo portal Trabalhe conosco']);
    }

    public function test_spontaneous_submission_without_job_and_without_talent_pool_consent(): void
    {
        $this->post('/api/v1/public/applications', $this->payload(['talent_pool_consent' => '0']), ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.job', null);
        $this->assertSame(0, Application::count());
        $this->assertSame(['privacy_notice'], CandidateConsent::pluck('purpose')->map->value->all());
    }

    public function test_existing_candidate_is_not_overwritten_and_response_is_the_same(): void
    {
        $existing = Candidate::factory()->create(['email' => 'maria@exemplo.com', 'full_name' => 'Maria Andrade', 'city' => 'Parintins', 'phone' => '92911112222']);
        $job = JobOpening::factory()->published()->create();

        $this->post('/api/v1/public/applications', $this->payload(['job' => $job->uuid]), ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonStructure(['data' => ['protocol', 'received_at', 'job', 'email']]);

        $this->assertSame(1, Candidate::count());
        $existing->refresh();
        $this->assertSame('Maria Andrade', $existing->full_name);
        $this->assertSame('Parintins', $existing->city);
        $this->assertSame(1, $existing->applications()->count());
        $this->assertFalse(PortalSubmission::sole()->is_new_candidate);

        $log = AuditLog::where('event', 'candidate.portal_submission')->sole();
        $this->assertStringContainsString('Dados informados diferentes do cadastro: nome, telefone, cidade', $log->description);

        // Mesmo arquivo enviado de novo para a mesma vaga: sem erro e sem duplicar.
        $this->post('/api/v1/public/applications', $this->payload(['job' => $job->uuid]), ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame(1, $existing->applications()->count());
        $this->assertSame(1, $existing->documents()->count());
        $this->assertSame('already_applied', PortalSubmission::latest('id')->first()->outcome);
    }

    public function test_inactive_candidate_is_not_auto_applied(): void
    {
        Candidate::factory()->inactive()->create(['email' => 'maria@exemplo.com']);
        $job = JobOpening::factory()->published()->create();
        $this->post('/api/v1/public/applications', $this->payload(['job' => $job->uuid]), ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame(0, Application::count());
        $this->assertSame('candidate_inactive', PortalSubmission::sole()->outcome);
    }

    public function test_validation_privacy_honeypot_files_and_closed_job(): void
    {
        $post = fn (array $o) => $this->post('/api/v1/public/applications', $this->payload($o), ['Accept' => 'application/json']);

        $post(['privacy_accepted' => '0'])->assertUnprocessable()->assertJsonValidationErrors('privacy_accepted');
        $post(['website' => 'http://spam'])->assertUnprocessable()->assertJsonValidationErrors('website');
        $post(['resume' => null])->assertUnprocessable()->assertJsonValidationErrors('resume');
        $post(['cpf' => '111.111.111-11'])->assertUnprocessable()->assertJsonValidationErrors('cpf');
        $post(['resume' => UploadedFile::fake()->createWithContent('virus.exe', 'MZ...')])->assertUnprocessable()->assertJsonValidationErrors('resume');
        $post(['job' => JobOpening::factory()->status(JobStatus::Closed)->create()->uuid])->assertUnprocessable()->assertJsonValidationErrors('job');

        $this->assertSame(0, Candidate::count());
        $this->assertSame(0, PortalSubmission::count());
    }

    public function test_rate_limit(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post('/api/v1/public/applications', $this->payload(['privacy_accepted' => '0']), ['Accept' => 'application/json'])->assertUnprocessable();
        }
        $this->post('/api/v1/public/applications', $this->payload(), ['Accept' => 'application/json'])->assertStatus(429);
    }
}

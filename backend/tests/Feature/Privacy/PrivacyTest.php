<?php

namespace Tests\Feature\Privacy;

use App\Domain\Identity\Enums\Role;
use App\Domain\Portal\Enums\ConsentPurpose;
use App\Domain\Recruitment\Enums\ApplicationStage;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\PortalSubmission;
use App\Models\PrivacyRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Support\Files;
use Tests\TestCase;

class PrivacyTest extends TestCase
{
    use InteractsWithIdentity;

    private function consent(Candidate $c, ConsentPurpose $p, ?string $revokedAt = null, ?string $grantedAt = null): void
    {
        $c->consents()->create(['purpose' => $p, 'version' => '2026.1', 'channel' => 'portal', 'granted_at' => $grantedAt ?? now(), 'revoked_at' => $revokedAt, 'ip_address' => '10.0.0.1']);
    }

    public function test_privacy_requests_lifecycle_with_deadline(): void
    {
        $this->actingAsRole(Role::HrManager);
        $candidate = Candidate::factory()->create();

        $r = $this->postJson('/api/v1/privacy/requests', [
            'requester_name' => 'Maria Andrade', 'requester_email' => 'maria@exemplo.com', 'type' => 'access', 'channel' => 'email',
            'description' => 'Quero saber quais dados vocês têm.', 'candidate' => $candidate->uuid,
        ])->assertCreated()->assertJsonPath('data.status', 'open')->assertJsonPath('data.due_on', now()->addDays(15)->toDateString());
        $id = $r->json('data.id');
        $this->assertMatchesRegularExpression('/^LGPD-\d{6}-[A-Z2-9]{5}$/', $r->json('data.protocol'));

        $this->putJson("/api/v1/privacy/requests/{$id}", ['status' => 'completed'])->assertUnprocessable()->assertJsonValidationErrors('response');
        $this->putJson("/api/v1/privacy/requests/{$id}", ['status' => 'completed', 'response' => 'Enviado relatório em JSON por e-mail.'])->assertOk()
            ->assertJsonPath('data.status', 'completed');
        $this->assertNotNull(PrivacyRequest::sole()->completed_at);
        $this->getJson("/api/v1/privacy/requests/{$id}")->assertOk()->assertJsonCount(2, 'data.history');

        // Atrasada
        PrivacyRequest::query()->update(['status' => 'open', 'due_on' => now()->subDay()->toDateString()]);
        $this->getJson('/api/v1/privacy/requests?overdue=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.overdue', true);
        $this->getJson('/api/v1/privacy/overview')->assertOk()->assertJsonPath('data.requests.overdue', 1);
    }

    public function test_only_privacy_managers_access(): void
    {
        $candidate = Candidate::factory()->create();
        foreach ([Role::HrAnalyst, Role::Recruiter, Role::DepartmentManager] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/v1/privacy/requests')->assertForbidden();
            $this->getJson('/api/v1/privacy/overview')->assertForbidden();
            $this->getJson("/api/v1/candidates/{$candidate->uuid}/privacy")->assertForbidden();
        }
        $this->actingAsRole(Role::HrAnalyst);
        $this->getJson('/api/v1/audit-logs')->assertForbidden();
        $this->actingAsRole(Role::HrManager);
        $this->getJson('/api/v1/audit-logs')->assertOk();
    }

    public function test_consents_record_and_revoke(): void
    {
        $this->actingAsRole(Role::HrManager);
        $candidate = Candidate::factory()->create();

        $this->postJson("/api/v1/candidates/{$candidate->uuid}/consents", ['purpose' => 'talent_pool', 'evidence' => 'Termo assinado na recepção'])->assertCreated();
        $show = $this->getJson("/api/v1/candidates/{$candidate->uuid}/privacy")->assertOk()->assertJsonCount(1, 'data.consents');
        $consent = $show->json('data.consents.0.id');
        $this->assertSame('internal', $show->json('data.consents.0.channel'));

        $this->postJson("/api/v1/candidates/{$candidate->uuid}/consents/{$consent}/revoke", ['reason' => 'Pedido do titular por e-mail'])->assertOk();
        $this->postJson("/api/v1/candidates/{$candidate->uuid}/consents/{$consent}/revoke", ['reason' => 'de novo'])->assertUnprocessable();
        $this->getJson("/api/v1/candidates/{$candidate->uuid}/history")->assertJsonFragment(['event_label' => 'Consentimento revogado']);
    }

    public function test_export_personal_data(): void
    {
        $this->actingAsRole(Role::HrManager);
        $candidate = Candidate::factory()->create(['full_name' => 'Maria Clara', 'email' => 'maria@exemplo.com']);
        $candidate->notes()->create(['body' => 'Anotação interna sigilosa', 'user_id' => auth()->id()]);
        Application::factory()->create(['candidate_id' => $candidate->id]);

        $r = $this->get("/api/v1/candidates/{$candidate->uuid}/privacy/export")->assertOk();
        $this->assertStringContainsString('attachment; filename="dados-titular-', $r->headers->get('content-disposition'));
        $data = json_decode($r->getContent(), true);
        $this->assertSame('Maria Clara', $data['titular']['nome']);
        $this->assertCount(1, $data['processos_seletivos']);
        $this->assertStringNotContainsString('sigilosa', $r->getContent());
        $this->assertSame(1, AuditLog::where('event', 'candidate.data_exported')->count());
    }

    public function test_anonymize_removes_personal_data_and_keeps_statistics(): void
    {
        Storage::fake('local');
        $actor = $this->actingAsRole(Role::HrManager);
        $candidate = Candidate::factory()->withCpf('52998224725')->create(['full_name' => 'Maria Clara', 'email' => 'maria@exemplo.com', 'city' => 'Manaus', 'professional_area' => 'nursing']);
        $this->postJson("/api/v1/candidates/{$candidate->uuid}/documents", ['type' => 'resume', 'file' => Files::pdfWithText(['Maria Clara técnica de enfermagem'])])->assertCreated();
        $path = CandidateDocument::sole()->path;
        $candidate->notes()->create(['body' => 'Nota', 'user_id' => $actor->id]);
        $this->consent($candidate, ConsentPurpose::TalentPool);
        $app = Application::factory()->stage(ApplicationStage::Interview)->create(['candidate_id' => $candidate->id]);
        PortalSubmission::create(['protocol' => 'TC-1', 'candidate_id' => $candidate->id, 'is_new_candidate' => true, 'outcome' => 'created', 'payload' => ['full_name' => 'Maria Clara'], 'ip_address' => '1.2.3.4']);
        $this->putJson("/api/v1/candidates/{$candidate->uuid}", ['full_name' => 'Maria Clara Andrade', 'email' => 'maria@exemplo.com', 'professional_area' => 'nursing', 'education_level' => 'technical', 'source' => 'internal'])->assertOk();

        // Processo em andamento bloqueia
        $this->postJson("/api/v1/candidates/{$candidate->uuid}/anonymize", ['reason' => 'Pedido do titular', 'confirm' => 'ANONIMIZAR'])
            ->assertUnprocessable()->assertJsonValidationErrors('candidate');
        $app->forceFill(['stage' => ApplicationStage::Withdrawn])->save();

        $this->postJson("/api/v1/candidates/{$candidate->uuid}/anonymize", ['reason' => 'Pedido do titular', 'confirm' => 'sim'])->assertUnprocessable()->assertJsonValidationErrors('confirm');
        $this->postJson("/api/v1/candidates/{$candidate->uuid}/anonymize", ['reason' => 'Pedido do titular', 'confirm' => 'ANONIMIZAR'])->assertOk();

        $c = Candidate::find($candidate->id);
        $this->assertSame('Titular anonimizado #'.$c->id, $c->full_name);
        $this->assertNull($c->email);
        $this->assertNull($c->cpf_hash);
        $this->assertNotNull($c->anonymized_at);
        $this->assertSame('Manaus', $c->city);
        $this->assertSame('nursing', $c->professional_area->value);
        $this->assertSame(0, $c->documents()->count());
        $this->assertSame(0, $c->notes()->count());
        Storage::disk('local')->assertMissing($path);
        $this->assertSame(1, $c->applications()->count());
        $this->assertNull(DB::table('candidate_consents')->value('ip_address'));
        $this->assertSame(['anonymized' => true], PortalSubmission::sole()->payload);
        $this->assertTrue(AuditLog::where('event', 'candidate.updated')->where('auditable_id', $c->id)->exists());
        $this->assertNull(AuditLog::where('event', 'candidate.updated')->where('auditable_id', $c->id)->value('old_values'));
        $this->assertStringNotContainsString('Maria', (string) DB::table('candidates')->where('id', $c->id)->value('search_vector'));

        // Some do banco de talentos e não pode mais ser editado
        $this->getJson('/api/v1/candidates')->assertJsonCount(0, 'data');
        $this->putJson("/api/v1/candidates/{$c->uuid}", ['full_name' => 'X', 'email' => 'x@x.com', 'professional_area' => 'nursing', 'education_level' => 'technical', 'source' => 'internal'])->assertForbidden();
        $this->postJson("/api/v1/candidates/{$c->uuid}/anonymize", ['reason' => 'De novo', 'confirm' => 'ANONIMIZAR'])->assertUnprocessable();
    }

    public function test_retention_policy(): void
    {
        $this->userWithRole(Role::Admin);
        $old = now()->subMonths(25);
        // Banco de talentos vencido (24 meses)
        $expired = Candidate::factory()->create(['created_at' => $old, 'updated_at' => $old]);
        $this->consent($expired, ConsentPurpose::TalentPool, grantedAt: $old);
        // Recente: fica
        $recent = Candidate::factory()->create();
        // Recusou o banco de talentos há 7 meses (prazo curto de 6)
        $declined = Candidate::factory()->create(['created_at' => now()->subMonths(7), 'updated_at' => now()->subMonths(7)]);
        $this->consent($declined, ConsentPurpose::PrivacyNotice, grantedAt: now()->subMonths(7));
        // Antigo, mas com processo em andamento: fica
        $busy = Candidate::factory()->create(['created_at' => $old, 'updated_at' => $old]);
        Application::factory()->create(['candidate_id' => $busy->id, 'created_at' => $old, 'updated_at' => $old]);
        // Antigo e contratado: fica (keep_hired)
        $hired = Candidate::factory()->create(['created_at' => $old, 'updated_at' => $old]);
        Application::factory()->stage(ApplicationStage::Hired)->create(['candidate_id' => $hired->id, 'created_at' => $old, 'updated_at' => $old]);
        // Excluído há 40 dias
        $deleted = Candidate::factory()->create();
        $deleted->delete();
        Candidate::withTrashed()->whereKey($deleted->id)->update(['deleted_at' => now()->subDays(40)]);
        // Vence em 10 dias
        $soon = Candidate::factory()->create(['created_at' => now()->subMonths(24)->addDays(10), 'updated_at' => now()->subMonths(24)->addDays(10)]);
        // Auditoria antiga
        DB::table('audit_logs')->insert(['event' => 'auth.login', 'created_at' => now()->subMonths(61)]);

        $this->artisan('privacy:apply-retention --dry-run')->expectsOutputToContain('Cadastros com prazo vencido: 3')->assertSuccessful();
        $this->assertSame(0, Candidate::withTrashed()->whereNotNull('anonymized_at')->count());

        $this->actingAsRole(Role::HrManager);
        $overview = $this->getJson('/api/v1/privacy/overview')->assertOk();
        $overview->assertJsonPath('data.retention.due_now', 3)->assertJsonPath('data.retention.audit_purgeable', 1);
        $this->assertSame([$soon->uuid], collect($overview->json('data.retention.upcoming'))->pluck('id')->all());

        $this->artisan('privacy:apply-retention')->assertSuccessful();
        $anonymized = Candidate::withTrashed()->whereNotNull('anonymized_at')->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$expired->id, $declined->id, $deleted->id])->sort()->values()->all(), $anonymized);
        $this->assertSame(0, DB::table('audit_logs')->where('created_at', '<', now()->subMonths(60))->count());
        $this->assertSame(1, AuditLog::where('event', 'privacy.retention_applied')->count());
    }

    public function test_audit_log_filters(): void
    {
        $this->actingAsRole(Role::Admin);
        $this->postJson('/api/v1/privacy/requests', ['requester_name' => 'Fulano de Tal', 'type' => 'other', 'channel' => 'phone'])->assertCreated();

        $this->getJson('/api/v1/audit-logs?group=privacy')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', 'privacy.request_created')
            ->assertJsonPath('data.0.subject.type', 'Solicitação LGPD');
        $this->getJson('/api/v1/audit-logs?search=Fulano')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/audit-logs?from='.now()->addDay()->toDateString())->assertJsonCount(0, 'data');
    }
}

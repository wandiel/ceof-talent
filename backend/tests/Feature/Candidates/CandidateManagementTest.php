<?php

namespace Tests\Feature\Candidates;

use App\Domain\Identity\Enums\Role;
use App\Models\Candidate;
use App\Models\Skill;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class CandidateManagementTest extends TestCase
{
    use InteractsWithIdentity;

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'full_name' => '  Maria   da Silva ',
            'email' => 'Maria.Silva@Exemplo.com',
            'phone' => '(92) 98888-7777',
            'cpf' => '529.982.247-25',
            'birth_date' => '1994-05-10',
            'zip_code' => '69050-000',
            'city' => 'Manaus',
            'state' => 'am',
            'professional_area' => 'nursing',
            'education_level' => 'higher',
            'desired_position' => 'Enfermeira',
            'salary_expectation' => 4500,
            'availability' => 'immediate',
            'work_shifts' => ['morning', 'on_call'],
            'willing_to_relocate' => false,
            'source' => 'referral',
            'source_detail' => 'Indicada pela Dra. Ana',
            'educations' => [
                ['level' => 'higher', 'institution' => 'UFAM', 'course' => 'Enfermagem', 'status' => 'completed', 'start_year' => 2012, 'end_year' => 2016],
            ],
            'experiences' => [
                ['company' => 'Hospital X', 'job_title' => 'Enfermeira', 'start_date' => '2017-02', 'is_current' => true],
                ['company' => 'Clínica Y', 'job_title' => 'Técnica de enfermagem', 'start_date' => '2014-01', 'end_date' => '2016-12', 'description' => 'Triagem.'],
            ],
            'courses' => [
                ['type' => 'certification', 'name' => 'BLS', 'institution' => 'AHA', 'year' => 2023, 'workload_hours' => 8],
            ],
            'skills' => [
                ['name' => 'Oftalmologia', 'level' => 'advanced'],
                ['name' => ' Atendimento  humanizado ', 'level' => null],
            ],
        ], $overrides);
    }

    public function test_recruiter_creates_complete_candidate(): void
    {
        $this->actingAsRole(Role::Recruiter);

        $response = $this->postJson('/api/v1/candidates', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.full_name', 'Maria da Silva')
            ->assertJsonPath('data.email', 'maria.silva@exemplo.com')
            ->assertJsonPath('data.phone', '92988887777')
            ->assertJsonPath('data.cpf', '529.982.247-25')
            ->assertJsonPath('data.state', 'AM')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.professional_area_label', 'Enfermagem')
            ->assertJsonPath('data.work_shifts_labels', ['Manhã', 'Plantão 12x36'])
            ->assertJsonCount(1, 'data.educations')
            ->assertJsonCount(2, 'data.experiences')
            ->assertJsonPath('data.experiences.0.company', 'Hospital X')
            ->assertJsonPath('data.experiences.0.end_date', null)
            ->assertJsonPath('data.skills.0.name', 'Atendimento humanizado');

        $uuid = $response->json('data.id');
        $raw = DB::table('candidates')->where('uuid', $uuid)->first();

        // CPF nunca é gravado em claro.
        $this->assertNotNull($raw->cpf);
        $this->assertStringNotContainsString('52998224725', $raw->cpf);
        $this->assertSame(64, strlen($raw->cpf_hash));

        $log = DB::table('audit_logs')->where('event', 'candidate.created')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('52998224725', (string) $log->new_values);
    }

    public function test_validation_messages_in_portuguese(): void
    {
        $this->actingAsRole(Role::HrAnalyst);

        $this->postJson('/api/v1/candidates', $this->payload([
            'email' => null, 'phone' => null, 'cpf' => '123.456.789-00', 'birth_date' => now()->subYears(10)->toDateString(),
            'experiences' => [['company' => 'A', 'job_title' => 'B', 'start_date' => '2020-05', 'end_date' => '2019-01']],
            'skills' => [['name' => 'Excel'], ['name' => 'excel']],
        ]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Informe ao menos um contato: e-mail ou telefone.')
            ->assertJsonPath('errors.cpf.0', 'O CPF informado não é válido.')
            ->assertJsonPath('errors.birth_date.0', 'O candidato precisa ter ao menos 14 anos.')
            ->assertJsonValidationErrors(['experiences.0.end_date' => 'A data de saída não pode ser anterior à data de entrada.'])
            ->assertJsonValidationErrors(['skills.1.name']);

        $this->postJson('/api/v1/candidates', $this->payload(['experiences' => [['company' => 'A', 'job_title' => 'B', 'start_date' => '2020-05']]]))
            ->assertJsonValidationErrors(['experiences.0.end_date' => 'Informe a data de saída ou marque "Emprego atual".']);
    }

    public function test_duplicate_email_and_cpf_are_rejected(): void
    {
        $this->actingAsRole(Role::HrAnalyst);
        $this->postJson('/api/v1/candidates', $this->payload())->assertCreated();

        $this->postJson('/api/v1/candidates', $this->payload(['email' => 'MARIA.SILVA@exemplo.com', 'cpf' => '52998224725']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Já existe um candidato cadastrado com este e-mail.')
            ->assertJsonPath('errors.cpf.0', 'Já existe um candidato cadastrado com este CPF.');
    }

    public function test_deleted_candidate_frees_email(): void
    {
        $this->actingAsRole(Role::HrManager);
        $id = $this->postJson('/api/v1/candidates', $this->payload())->json('data.id');

        $this->deleteJson("/api/v1/candidates/{$id}")->assertNoContent();
        $this->assertSoftDeleted('candidates', ['uuid' => $id]);
        $this->getJson("/api/v1/candidates/{$id}")->assertNotFound();

        $this->postJson('/api/v1/candidates', $this->payload())->assertCreated();
    }

    public function test_update_replaces_sections_and_records_history(): void
    {
        $this->actingAsRole(Role::HrAnalyst);
        $id = $this->postJson('/api/v1/candidates', $this->payload())->json('data.id');

        // Reenviar os mesmos dados não gera registro no histórico.
        $this->putJson("/api/v1/candidates/{$id}", $this->payload())->assertOk();
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'candidate.updated')->count());

        $this->putJson("/api/v1/candidates/{$id}", $this->payload([
            'phone' => '92 3333-4444',
            'experiences' => [],
            'skills' => [['name' => 'oftalmologia', 'level' => 'basic']],
        ]))
            ->assertOk()
            ->assertJsonCount(0, 'data.experiences')
            ->assertJsonPath('data.skills.0.level', 'basic');

        // "oftalmologia" reaproveita a competência existente.
        $this->assertSame(1, Skill::query()->where('name', 'Oftalmologia')->count());

        $history = $this->getJson("/api/v1/candidates/{$id}/history")->assertOk();
        $this->assertSame('Alterou: telefone, experiências, competências.', $history->json('data.0.description'));
        $this->assertSame('Cadastro criado', $history->json('data.1.event_label'));
    }

    public function test_status_change_listing_and_filters(): void
    {
        $this->actingAsRole(Role::HrAnalyst);
        $a = Candidate::factory()->create(['full_name' => 'Ana Paula Souza', 'professional_area' => 'nursing', 'phone' => '92991234567']);
        Candidate::factory()->create(['full_name' => 'Bruno Lima', 'professional_area' => 'information_technology', 'city' => 'Belém', 'state' => 'PA']);

        $this->patchJson("/api/v1/candidates/{$a->uuid}/status", ['status' => 'inactive'])
            ->assertOk()->assertJsonPath('data.status', 'inactive');

        $this->getJson('/api/v1/candidates?status=active')->assertJsonCount(1, 'data')->assertJsonPath('data.0.full_name', 'Bruno Lima');
        $this->getJson('/api/v1/candidates?professional_area=nursing')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/candidates?search=paula')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/candidates?search=91234')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/candidates?state=PA&city=belém')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/candidates?sort=full_name')->assertJsonPath('data.0.full_name', 'Ana Paula Souza');
        $this->getJson('/api/v1/candidates?professional_area=invalid')->assertUnprocessable();
    }

    public function test_viewing_is_logged_but_hidden_from_default_history(): void
    {
        $this->actingAsRole(Role::HrManager);
        $candidate = Candidate::factory()->create();

        $this->getJson("/api/v1/candidates/{$candidate->uuid}")->assertOk()->assertJsonPath('data.abilities.delete', true);
        $this->assertDatabaseHas('audit_logs', ['event' => 'candidate.viewed', 'auditable_id' => $candidate->id]);

        $this->getJson("/api/v1/candidates/{$candidate->uuid}/history")->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/candidates/{$candidate->uuid}/history?include_views=1")->assertJsonCount(1, 'data');
    }

    public function test_notes(): void
    {
        $user = $this->actingAsRole(Role::Recruiter);
        $candidate = Candidate::factory()->create();

        $this->postJson("/api/v1/candidates/{$candidate->uuid}/notes", ['body' => 'Boa comunicação na triagem.'])
            ->assertCreated()->assertJsonPath('data.author', $user->name);
        $this->postJson("/api/v1/candidates/{$candidate->uuid}/notes", ['body' => ''])->assertUnprocessable();

        $this->getJson("/api/v1/candidates/{$candidate->uuid}")->assertJsonPath('data.notes.0.body', 'Boa comunicação na triagem.');
    }

    public function test_options_and_skill_suggestions(): void
    {
        $this->actingAsRole(Role::Recruiter);
        $this->postJson('/api/v1/candidates', $this->payload())->assertCreated();

        $this->getJson('/api/v1/candidates/options')->assertOk()
            ->assertJsonPath('data.education_levels.0.label', 'Fundamental incompleto')
            ->assertJsonPath('data.document_types.0.value', 'resume');
        $this->getJson('/api/v1/skills?search=oftal')->assertOk()->assertJsonPath('data.0.name', 'Oftalmologia');
    }
}

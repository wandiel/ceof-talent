<?php

namespace Tests\Feature\Intelligence;

use App\Domain\Identity\Enums\Role;
use App\Domain\Recruitment\Enums\ApplicationStage;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\CandidateSuggestion;
use App\Models\Department;
use App\Models\JobOpening;
use App\Models\Skill;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Support\Files;
use Tests\TestCase;

class ResumeReadingTest extends TestCase
{
    use InteractsWithIdentity;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private const RESUME = [
        'Maria Clara Andrade - Técnica de Enfermagem',
        'COREN-AM 123456',
        'Experiência: técnica de enfermagem no centro cirúrgico do Hospital Norte (2018-2024).',
        'Atuação em instrumentação cirúrgica e central de material e esterilização.',
        'Cursos: Suporte Básico de Vida (BLS), Biossegurança.',
        'Conhecimentos: Tasy, Excel.',
    ];

    private function upload(Candidate $candidate, UploadedFile $file, string $type = 'resume')
    {
        return $this->post("/api/v1/candidates/{$candidate->uuid}/documents", ['file' => $file, 'type' => $type], ['Accept' => 'application/json']);
    }

    public function test_pdf_resume_is_read_and_generates_suggestions_for_review(): void
    {
        $candidate = Candidate::factory()->create(['professional_area' => 'administrative']);
        $this->actingAsRole(Role::HrAnalyst);

        $this->upload($candidate, Files::pdfWithText(self::RESUME))->assertCreated();

        $document = $candidate->documents()->first();
        $this->assertSame('done', $document->extraction_status);
        $this->assertSame('pdf_text', $document->extraction_method);
        $this->assertStringContainsString('instrumentação cirúrgica', $document->extracted_text);

        $data = $this->getJson("/api/v1/candidates/{$candidate->uuid}/suggestions")->assertOk()->json('data');
        $values = collect($data)->pluck('value')->all();
        foreach (['Instrumentação cirúrgica', 'Centro cirúrgico', 'Biossegurança', 'Tasy', 'Excel', 'BLS', 'nursing'] as $expected) {
            $this->assertContains($expected, $values);
        }
        $area = collect($data)->firstWhere('type', 'professional_area');
        $this->assertSame('Enfermagem', $area['value_label']);
        $this->assertSame('pending', $area['status']);
        $this->assertNotNull($area['evidence']);

        // Nada mudou no cadastro sem revisão humana.
        $this->assertSame('administrative', $candidate->fresh()->professional_area->value);
        $this->assertSame(0, $candidate->skills()->count());

        // O texto do currículo passa a ser encontrado na busca por palavras-chave.
        $this->getJson('/api/v1/candidates?keywords=esterilizacao')->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/candidates/{$candidate->uuid}/history")->assertJsonPath('data.0.event_label', 'Currículo lido automaticamente');
    }

    public function test_docx_resume_is_read(): void
    {
        $candidate = Candidate::factory()->create();
        $this->actingAsRole(Role::Recruiter);

        $this->upload($candidate, Files::docxWithText(['Analista de faturamento hospitalar', 'Experiência com glosas, convênios e TISS.']))->assertCreated()
            ->assertJsonPath('data.extraction_status', 'pending');
        $this->assertSame('docx', $candidate->documents()->first()->extraction_method);
        $this->assertContains('Faturamento hospitalar', CandidateSuggestion::pluck('value')->all());
    }

    public function test_only_resumes_are_read_and_failures_do_not_block_upload(): void
    {
        $candidate = Candidate::factory()->create();
        $this->actingAsRole(Role::HrAnalyst);

        $this->upload($candidate, Files::pdfWithText(['Certificado de conclusão'], 'certificado.pdf'), 'certificate')->assertCreated();
        $this->assertNull($candidate->documents()->first()->extraction_status);

        $this->upload($candidate, UploadedFile::fake()->createWithContent('quebrado.pdf', "%PDF-1.4\nconteúdo inválido"))->assertCreated();
        $this->assertSame('failed', $candidate->documents()->where('original_name', 'quebrado.pdf')->value('extraction_status'));
    }

    public function test_reviewing_suggestions(): void
    {
        $candidate = Candidate::factory()->create(['professional_area' => 'administrative']);
        $this->actingAsRole(Role::HrAnalyst);
        $this->upload($candidate, Files::pdfWithText(self::RESUME))->assertCreated();
        $skill = CandidateSuggestion::where('value', 'Instrumentação cirúrgica')->first();
        $area = CandidateSuggestion::where('type', 'professional_area')->first();
        $tasy = CandidateSuggestion::where('value', 'Tasy')->first();
        $base = "/api/v1/candidates/{$candidate->uuid}/suggestions";

        $this->postJson("$base/{$skill->uuid}/accept")->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->postJson("$base/{$area->uuid}/accept")->assertOk();
        $this->postJson("$base/{$tasy->uuid}/dismiss")->assertOk()->assertJsonPath('data.status', 'dismissed');
        $this->postJson("$base/{$tasy->uuid}/accept")->assertStatus(422);

        $candidate->refresh();
        $this->assertSame('nursing', $candidate->professional_area->value);
        $this->assertSame(['Instrumentação cirúrgica'], $candidate->skills()->pluck('name')->all());
        $this->assertTrue(Skill::where('name', 'Instrumentação cirúrgica')->exists());

        // Ler de novo não traz de volta o que já foi revisado.
        $document = $candidate->documents()->first();
        $this->postJson("/api/v1/candidates/{$candidate->uuid}/documents/{$document->uuid}/read")->assertStatus(202);
        $this->assertSame('dismissed', $tasy->fresh()->status->value);
        $this->assertSame(1, CandidateSuggestion::where('value', 'Tasy')->count());

        // Quem só vê não revisa.
        $this->actingAsRole(Role::DepartmentManager, ['department_id' => Department::factory()->create()->id]);
        $this->postJson("$base/{$skill->uuid}/accept")->assertForbidden();
    }

    public function test_job_match_compares_requirements(): void
    {
        $job = JobOpening::factory()->published()->create(['professional_area' => 'nursing', 'education_level_min' => 'technical']);
        $job->requirements()->createMany([
            ['description' => 'Experiência em centro cirúrgico', 'is_mandatory' => true, 'sort' => 0],
            ['description' => 'Curso de instrumentação cirúrgica', 'is_mandatory' => true, 'sort' => 1],
            ['description' => 'Inglês avançado', 'is_mandatory' => false, 'sort' => 2],
        ]);
        $candidate = Candidate::factory()->create(['professional_area' => 'nursing', 'education_level' => 'technical', 'summary' => 'Técnica com experiência em centro cirúrgico oftalmológico.']);
        $candidate->skills()->attach(Skill::create(['name' => 'Instrumentação cirúrgica'])->id);
        $application = Application::factory()->stage(ApplicationStage::Screening)->create(['job_opening_id' => $job->id, 'candidate_id' => $candidate->id]);
        $this->actingAsRole(Role::Recruiter);

        $result = $this->getJson("/api/v1/applications/{$application->uuid}/match")->assertOk()->json('data');
        $this->assertSame([true, true, false, true, true], array_column($result['items'], 'matched'));
        $this->assertSame(0, $result['mandatory_missing']);
        $this->assertSame(88, $result['score']); // (2+2+0+2+1) / (2+2+1+2+1) = 7/8
        $this->assertStringContainsString('decisão é sempre da equipe', $result['notice']);

        $this->actingAsRole(Role::Interviewer);
        $this->getJson("/api/v1/applications/{$application->uuid}/match")->assertForbidden();
    }
}

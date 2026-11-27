<?php

namespace Tests\Feature\Reports;

use App\Domain\Identity\Enums\Role;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\Department;
use App\Models\JobOpening;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use InteractsWithIdentity;

    private const KEYS = [
        'candidates_general', 'candidates_by_area', 'candidates_by_education', 'candidates_by_city', 'new_candidates', 'sources',
        'jobs_open', 'jobs_closed', 'processes', 'candidates_by_stage', 'interviews', 'approvals', 'hires', 'time_to_hire',
    ];

    private function seedData(): Department
    {
        $dept = Department::factory()->create(['name' => 'Enfermagem']);
        $job = JobOpening::factory()->published()->create(['department_id' => $dept->id, 'positions_count' => 2]);
        foreach (['Manaus', 'Manaus', 'Parintins'] as $i => $city) {
            Application::factory()->create(['job_opening_id' => $job->id, 'candidate_id' => Candidate::factory()->create(['city' => $city, 'state' => 'AM', 'full_name' => "Pessoa {$i}"])->id]);
        }
        $app = Application::first();
        foreach (['screening', 'approved', 'hired'] as $stage) {
            $this->postJson("/api/v1/applications/{$app->uuid}/move", ['stage' => $stage])->assertOk();
        }

        return $dept;
    }

    public function test_catalog_lists_every_report_and_each_one_runs(): void
    {
        $this->actingAsRole(Role::HrManager);
        $this->seedData();

        $this->getJson('/api/v1/reports')->assertOk()
            ->assertJsonPath('meta.can_export', true)
            ->assertJsonCount(count(self::KEYS), 'data');

        foreach (self::KEYS as $key) {
            $this->getJson("/api/v1/reports/{$key}")->assertOk()->assertJsonPath('data.key', $key);
        }
        $this->getJson('/api/v1/reports/nao_existe')->assertNotFound();
    }

    public function test_report_contents(): void
    {
        $this->actingAsRole(Role::HrManager);
        $this->seedData();

        $city = $this->getJson('/api/v1/reports/candidates_by_city')->json('data.rows');
        $this->assertSame(['group' => 'Manaus/AM', 'total' => 2, 'active' => 2, 'share' => 66.7], $city[0]);

        $this->getJson('/api/v1/reports/hires')->assertJsonPath('meta.total', 1)->assertJsonPath('data.rows.0.candidate', 'Pessoa 0');
        $this->getJson('/api/v1/reports/approvals')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/reports/processes')->assertJsonPath('data.rows.0.total', 3)->assertJsonPath('data.rows.0.hired', 1);
        $this->getJson('/api/v1/reports/jobs_open')->assertJsonPath('data.rows.0.hired', 1)->assertJsonPath('data.rows.0.in_process', 2);
        $this->getJson('/api/v1/reports/time_to_hire')->assertJsonPath('data.rows.0.hires', 1);
        $stages = collect($this->getJson('/api/v1/reports/candidates_by_stage')->json('data.rows'))->pluck('total', 'stage');
        $this->assertSame(2, $stages['Inscrição']);
        $this->assertSame(1, $stages['Contratação']);

        // Período sem dados
        $this->getJson('/api/v1/reports/candidates_general?from=2020-01-01&to=2020-12-31')->assertJsonPath('meta.total', 0);
    }

    public function test_exports_csv_xlsx_and_pdf_with_audit(): void
    {
        $this->actingAsRole(Role::HrManager);
        $this->seedData();
        Candidate::factory()->create(['full_name' => '=HYPERLINK("http://x")', 'city' => 'Manaus']);

        $csv = $this->get('/api/v1/reports/candidates_general/export/csv')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $csv->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFCandidato;E-mail", $content);
        $this->assertStringContainsString("\"'=HYPERLINK", $content);

        $xlsx = $this->get('/api/v1/reports/processes/export/xlsx')->assertOk();
        $this->assertStringStartsWith('PK', $xlsx->streamedContent());

        $pdf = $this->get('/api/v1/reports/candidates_by_area/export/pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->streamedContent());
        $this->assertStringContainsString('relatorio-candidates-by-area-', $pdf->headers->get('content-disposition'));

        $log = AuditLog::where('event', 'report.exported')->latest('id')->first();
        $this->assertSame('pdf', $log->context['format']);
        $this->assertSame(3, AuditLog::where('event', 'report.exported')->count());
    }

    public function test_permissions(): void
    {
        $this->actingAsRole(Role::HrManager);
        $dept = $this->seedData();
        // Recrutador vê relatórios, mas não exporta.
        $this->actingAsRole(Role::Recruiter);
        $this->getJson('/api/v1/reports/sources')->assertOk();
        $this->get('/api/v1/reports/sources/export/csv')->assertForbidden();
        $this->getJson('/api/v1/reports')->assertJsonPath('meta.can_export', false);

        // Entrevistador não vê relatórios.
        $this->actingAsRole(Role::Interviewer);
        $this->getJson('/api/v1/reports')->assertForbidden();
        $this->getJson('/api/v1/reports/sources')->assertForbidden();

        // Gestor de outro departamento não vê os candidatos deste.
        $this->actingAsRole(Role::DepartmentManager, ['department_id' => Department::factory()->create()->id]);
        $this->getJson("/api/v1/reports/candidates_general?department={$dept->uuid}")->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/reports')->assertJsonCount(1, 'meta.departments');
    }
}

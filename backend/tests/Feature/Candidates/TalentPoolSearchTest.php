<?php

namespace Tests\Feature\Candidates;

use App\Domain\Identity\Enums\Role;
use App\Models\Candidate;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class TalentPoolSearchTest extends TestCase
{
    use InteractsWithIdentity;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->actingAsRole(Role::Recruiter);

        $this->create('Ana Beatriz Souza', [
            'professional_area' => 'nursing', 'education_level' => 'postgraduate', 'birth_date' => '1990-01-10',
            'summary' => 'Experiência em centro cirúrgico oftalmológico e atendimento humanizado.',
            'salary_expectation' => 5000, 'work_shifts' => ['night', 'on_call'], 'willing_to_relocate' => true,
            'educations' => [['level' => 'higher', 'institution' => 'UFAM', 'course' => 'Enfermagem', 'status' => 'completed', 'end_year' => 2013]],
            'experiences' => [
                ['company' => 'Hospital de Olhos Norte', 'job_title' => 'Enfermeira assistencial', 'start_date' => '2014-01', 'end_date' => '2018-12'],
                // Período sobreposto: não pode contar em dobro.
                ['company' => 'Clínica Visão', 'job_title' => 'Enfermeira plantonista', 'start_date' => '2016-01', 'end_date' => '2017-12'],
                ['company' => 'Hospital Central', 'job_title' => 'Coordenadora de enfermagem', 'start_date' => '2019-01', 'end_date' => '2023-12'],
            ],
            'skills' => [['name' => 'Oftalmologia'], ['name' => 'Instrumentação cirúrgica']],
        ]);
        $this->create('Bruno Costa', [
            'professional_area' => 'information_technology', 'education_level' => 'higher', 'birth_date' => '2000-06-01',
            'summary' => 'Suporte técnico e redes.', 'salary_expectation' => 3000, 'city' => 'Belém', 'state' => 'PA',
            'experiences' => [['company' => 'TechNorte', 'job_title' => 'Analista de suporte', 'start_date' => '2021-01', 'end_date' => '2022-12']],
            'skills' => [['name' => 'Redes'], ['name' => 'Oftalmologia']],
        ]);
        $this->create('Carla Mendes', [
            'professional_area' => 'patient_service', 'education_level' => 'high_school',
            'summary' => 'Recepcionista com foco em atendimento ao paciente.',
            'skills' => [['name' => 'Atendimento ao público']],
        ]);
    }

    private function create(string $name, array $data): string
    {
        return $this->postJson('/api/v1/candidates', array_replace([
            'full_name' => $name, 'email' => str($name)->slug('.').'@exemplo.com', 'city' => 'Manaus', 'state' => 'AM',
            'source' => 'internal', 'professional_area' => 'other', 'education_level' => 'high_school',
        ], $data))->assertCreated()->json('data.id');
    }

    /** @return list<string> */
    private function names(TestResponse $response): array
    {
        return collect($response->assertOk()->json('data'))->pluck('full_name')->sort()->values()->all();
    }

    public function test_keyword_search_ignores_accents_and_plurals(): void
    {
        $this->assertSame(['Ana Beatriz Souza'], $this->names($this->getJson('/api/v1/candidates?keywords=cirurgico')));
        $this->assertSame(['Ana Beatriz Souza'], $this->names($this->getJson('/api/v1/candidates?keywords=enfermeiras')));
        $this->assertSame(['Bruno Costa'], $this->names($this->getJson('/api/v1/candidates?keywords=technorte')));
        $this->assertSame(['Ana Beatriz Souza', 'Carla Mendes'], $this->names($this->getJson('/api/v1/candidates?keywords=atendimento -redes')));
        $this->assertSame([], $this->names($this->getJson('/api/v1/candidates?keywords="atendimento ao paciente" enfermagem')));
    }

    public function test_keyword_results_are_ranked_by_relevance(): void
    {
        // "Oftalmologia" é competência dos dois, mas Ana também tem o termo no resumo.
        $this->getJson('/api/v1/candidates?keywords=oftalmologia')
            ->assertJsonPath('data.0.full_name', 'Ana Beatriz Souza')
            ->assertJsonPath('data.1.full_name', 'Bruno Costa');
    }

    public function test_experience_education_and_skill_filters(): void
    {
        // Ana: 2014–2018 + 2019–2023 = 10 anos (o período de 2016–2017 está sobreposto).
        $this->getJson('/api/v1/candidates?keywords=enfermagem')->assertJsonPath('data.0.experience_months', 120);
        $this->assertSame(['Ana Beatriz Souza'], $this->names($this->getJson('/api/v1/candidates?min_experience_years=9')));
        $this->assertSame([], $this->names($this->getJson('/api/v1/candidates?min_experience_years=11')));
        $this->assertSame(['Ana Beatriz Souza'], $this->names($this->getJson('/api/v1/candidates?experience_title=coordenadora')));
        $this->assertSame(['Bruno Costa'], $this->names($this->getJson('/api/v1/candidates?experience_company=technorte')));
        $this->assertSame(['Ana Beatriz Souza', 'Bruno Costa'], $this->names($this->getJson('/api/v1/candidates?education_level_min=higher')));
        $this->assertSame(['Ana Beatriz Souza'], $this->names($this->getJson('/api/v1/candidates?education_course=enfermagem')));
        $this->assertSame(['Ana Beatriz Souza', 'Bruno Costa'], $this->names($this->getJson('/api/v1/candidates?skills[]=oftalmologia')));
        $this->assertSame(['Bruno Costa'], $this->names($this->getJson('/api/v1/candidates?skills[]=oftalmologia&skills[]=Redes')));
    }

    public function test_combined_profile_filters(): void
    {
        $this->assertSame(['Ana Beatriz Souza'], $this->names($this->getJson('/api/v1/candidates?work_shift=on_call&willing_to_relocate=1')));
        $this->assertSame(['Bruno Costa', 'Carla Mendes'], $this->names($this->getJson('/api/v1/candidates?salary_max=4000')));
        $this->assertSame(['Bruno Costa'], $this->names($this->getJson('/api/v1/candidates?age_max=30')));
        $this->assertSame(['Ana Beatriz Souza'], $this->names($this->getJson('/api/v1/candidates?age_min=30&age_max=40')));
        $this->assertSame(['Bruno Costa'], $this->names($this->getJson('/api/v1/candidates?city=belem')));
        $this->assertSame(['Ana Beatriz Souza'], $this->names($this->getJson('/api/v1/candidates?keywords=oftalmologia&professional_area=nursing')));
        $this->getJson('/api/v1/candidates?age_min=40&age_max=30')->assertUnprocessable()->assertJsonValidationErrors('age_max');
        $this->getJson('/api/v1/candidates?sort=password')->assertUnprocessable();
        $this->getJson('/api/v1/candidates?sort=-experience')->assertJsonPath('data.0.full_name', 'Ana Beatriz Souza');
    }

    public function test_has_resume_filter(): void
    {
        $candidate = Candidate::query()->where('full_name', 'Carla Mendes')->firstOrFail();
        $candidate->documents()->create(['type' => 'resume', 'original_name' => 'cv.pdf', 'path' => 'x/cv.pdf', 'mime_type' => 'application/pdf', 'size' => 1, 'sha256' => str_repeat('b', 64)]);

        $this->assertSame(['Carla Mendes'], $this->names($this->getJson('/api/v1/candidates?has_resume=1')));
    }

    public function test_favorites_are_personal(): void
    {
        $id = Candidate::query()->where('full_name', 'Bruno Costa')->value('uuid');

        $this->putJson("/api/v1/candidates/{$id}/favorite")->assertOk()->assertJsonPath('data.is_favorite', true);
        $this->putJson("/api/v1/candidates/{$id}/favorite")->assertOk();
        $this->assertSame(['Bruno Costa'], $this->names($this->getJson('/api/v1/candidates?favorites=1')));
        $this->getJson("/api/v1/candidates/{$id}")->assertJsonPath('data.is_favorite', true);
        $this->getJson('/api/v1/candidates?search=bruno')->assertJsonPath('data.0.is_favorite', true);

        $this->deleteJson("/api/v1/candidates/{$id}/favorite")->assertOk();
        $this->assertSame([], $this->names($this->getJson('/api/v1/candidates?favorites=1')));
    }

    public function test_tags_catalog_assignment_search_and_history(): void
    {
        $reserva = $this->postJson('/api/v1/tags', ['name' => ' Banco  de reserva ', 'color' => 'green'])
            ->assertCreated()->assertJsonPath('data.name', 'Banco de reserva')->json('data.id');
        $lideranca = $this->postJson('/api/v1/tags', ['name' => 'Perfil liderança', 'color' => 'purple'])->json('data.id');
        $this->postJson('/api/v1/tags', ['name' => 'banco de RESERVA', 'color' => 'blue'])
            ->assertUnprocessable()->assertJsonPath('errors.name.0', 'Já existe uma etiqueta com este nome.');
        $this->postJson('/api/v1/tags', ['name' => 'X1', 'color' => 'black'])->assertJsonValidationErrors('color');

        $ana = Candidate::query()->where('full_name', 'Ana Beatriz Souza')->value('uuid');
        $carla = Candidate::query()->where('full_name', 'Carla Mendes')->value('uuid');

        $this->putJson("/api/v1/candidates/{$ana}/tags", ['tags' => [$reserva, $lideranca]])->assertOk()->assertJsonCount(2, 'data');
        $this->putJson("/api/v1/candidates/{$carla}/tags", ['tags' => [$reserva]])->assertOk();

        $this->assertSame(['Ana Beatriz Souza', 'Carla Mendes'], $this->names($this->getJson("/api/v1/candidates?tags[]={$reserva}")));
        $this->assertSame(['Ana Beatriz Souza'], $this->names($this->getJson("/api/v1/candidates?tags[]={$lideranca}")));
        // Etiquetas também entram na busca por palavras-chave.
        $this->assertSame(['Ana Beatriz Souza'], $this->names($this->getJson('/api/v1/candidates?keywords=liderança')));
        $this->getJson('/api/v1/tags')->assertJsonPath('data.0.name', 'Banco de reserva')->assertJsonPath('data.0.candidates_count', 2);

        $this->putJson("/api/v1/candidates/{$ana}/tags", ['tags' => [$reserva]])->assertOk();
        $this->getJson("/api/v1/candidates/{$ana}/history")->assertJsonPath('data.0.description', 'Removeu Perfil liderança.');

        // Renomear e excluir mantém a busca atualizada.
        $this->putJson("/api/v1/tags/{$reserva}", ['name' => 'Reserva técnica', 'color' => 'green'])->assertOk();
        $this->assertSame(['Ana Beatriz Souza', 'Carla Mendes'], $this->names($this->getJson('/api/v1/candidates?keywords=reserva')));
        $this->deleteJson("/api/v1/tags/{$reserva}")->assertNoContent();
        $this->assertSame([], $this->names($this->getJson('/api/v1/candidates?keywords=reserva')));
    }
}

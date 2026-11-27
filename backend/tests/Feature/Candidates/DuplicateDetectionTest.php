<?php

namespace Tests\Feature\Candidates;

use App\Domain\Identity\Enums\Role;
use App\Models\Candidate;
use App\Models\Department;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class DuplicateDetectionTest extends TestCase
{
    use InteractsWithIdentity;

    public function test_check_before_saving_finds_matches(): void
    {
        $this->actingAsRole(Role::HrAnalyst);
        $maria = Candidate::factory()->withCpf('52998224725')->create([
            'full_name' => 'Maria Aparecida dos Santos', 'email' => 'maria@exemplo.com', 'phone' => '92991110000', 'birth_date' => '1988-04-02',
        ]);
        Candidate::factory()->create(['full_name' => 'José Pereira', 'phone' => '92990000000']);

        $this->postJson('/api/v1/candidates/duplicates/check', ['phone' => '(92) 99111-0000'])
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.candidate.id', $maria->uuid)
            ->assertJsonPath('data.0.reasons', ['Mesmo telefone']);

        $this->postJson('/api/v1/candidates/duplicates/check', ['full_name' => 'Maria Aparecida Santos', 'birth_date' => '1988-04-02'])
            ->assertJsonPath('data.0.candidate.id', $maria->uuid)
            ->assertJsonPath('data.0.reasons.1', 'Mesma data de nascimento');

        $this->postJson('/api/v1/candidates/duplicates/check', ['cpf' => '529.982.247-25', 'email' => 'MARIA@exemplo.com'])
            ->assertJsonPath('data.0.reasons', ['Mesmo CPF', 'Mesmo e-mail']);

        // Nome parecido sem outro indício não basta (nomes comuns geram falsos alertas).
        $this->postJson('/api/v1/candidates/duplicates/check', ['full_name' => 'Maria Aparecida Souza'])->assertJsonCount(0, 'data');

        // Ao editar, o próprio cadastro não é listado.
        $this->postJson('/api/v1/candidates/duplicates/check', ['phone' => '92991110000', 'ignore' => $maria->uuid])->assertJsonCount(0, 'data');
    }

    public function test_pairs_listing_and_dismissal(): void
    {
        $this->actingAsRole(Role::HrManager);
        $a = Candidate::factory()->create(['full_name' => 'Fernanda Lima Rocha', 'phone' => '92981112222']);
        $b = Candidate::factory()->create(['full_name' => 'Fernanda L. Rocha', 'phone' => '92970000000', 'phone_secondary' => '92981112222']);
        $c = Candidate::factory()->create(['full_name' => 'Roberto Carlos Nunes', 'birth_date' => '1979-09-09']);
        $d = Candidate::factory()->create(['full_name' => 'Roberto Carlos Nunez', 'birth_date' => '1979-09-09']);
        Candidate::factory()->create(['full_name' => 'Paulo Henrique']);

        $response = $this->getJson('/api/v1/candidates/duplicates')->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertSame([$a->uuid, $b->uuid], [$response->json('data.0.a.id'), $response->json('data.0.b.id')]);
        $this->assertContains('Mesmo telefone', $response->json('data.0.reasons'));
        $this->assertSame([$c->uuid, $d->uuid], [$response->json('data.1.a.id'), $response->json('data.1.b.id')]);

        $this->getJson("/api/v1/candidates/{$d->uuid}/duplicates")->assertJsonPath('data.0.candidate.id', $c->uuid);

        $this->postJson("/api/v1/candidates/{$d->uuid}/duplicates/{$c->uuid}/dismiss")->assertOk();
        $this->postJson("/api/v1/candidates/{$c->uuid}/duplicates/{$d->uuid}/dismiss")->assertOk();
        $this->getJson('/api/v1/candidates/duplicates')->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/candidates/{$c->uuid}/duplicates")->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/candidates/{$c->uuid}/history")->assertJsonPath('data.0.event_label', 'Duplicidade descartada');

        // Excluir um dos cadastros encerra o alerta.
        $this->deleteJson("/api/v1/candidates/{$b->uuid}")->assertNoContent();
        $this->getJson('/api/v1/candidates/duplicates')->assertJsonPath('meta.total', 0);
    }

    public function test_permissions(): void
    {
        $candidate = Candidate::factory()->create();
        $this->actingAsRole(Role::DepartmentManager, ['department_id' => Department::factory()->create()->id]);

        $this->getJson('/api/v1/candidates/duplicates')->assertForbidden();
        $this->postJson('/api/v1/candidates/duplicates/check', ['full_name' => 'X'])->assertForbidden();
        $this->putJson("/api/v1/candidates/{$candidate->uuid}/favorite")->assertForbidden();
        $this->putJson("/api/v1/candidates/{$candidate->uuid}/tags", ['tags' => []])->assertForbidden();
        $this->postJson('/api/v1/tags', ['name' => 'Teste', 'color' => 'blue'])->assertForbidden();
        $this->getJson('/api/v1/tags')->assertOk();
    }

    public function test_interviewer_cannot_see_tags(): void
    {
        $this->actingAsRole(Role::Interviewer);
        $this->getJson('/api/v1/tags')->assertForbidden();
    }
}

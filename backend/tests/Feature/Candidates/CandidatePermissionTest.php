<?php

namespace Tests\Feature\Candidates;

use App\Domain\Identity\Enums\Role;
use App\Models\Candidate;
use App\Models\Department;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class CandidatePermissionTest extends TestCase
{
    use InteractsWithIdentity;

    public function test_guest_is_rejected(): void
    {
        $this->getJson('/api/v1/candidates')->assertUnauthorized();
    }

    public function test_interviewer_sees_only_candidates_they_interview(): void
    {
        $this->actingAsRole(Role::Interviewer);
        $candidate = Candidate::factory()->create();

        // Sem entrevistas: lista vazia e ficha bloqueada (o acesso vem das entrevistas, Fase 7).
        $this->getJson('/api/v1/candidates')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/candidates/{$candidate->uuid}")->assertForbidden();
        $this->postJson('/api/v1/candidates', ['full_name' => 'X'])->assertForbidden();
    }

    public function test_department_manager_sees_no_candidates_until_applications_exist(): void
    {
        $this->actingAsRole(Role::DepartmentManager, ['department_id' => Department::factory()->create()->id]);
        $candidate = Candidate::factory()->create();

        $this->getJson('/api/v1/candidates')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/candidates/{$candidate->uuid}")->assertForbidden();
        $this->postJson('/api/v1/candidates', ['full_name' => 'X'])->assertForbidden();
    }

    public function test_only_delete_permission_removes_candidates(): void
    {
        $candidate = Candidate::factory()->create();

        $this->actingAsRole(Role::HrAnalyst);
        $this->deleteJson("/api/v1/candidates/{$candidate->uuid}")->assertForbidden();
        $this->getJson("/api/v1/candidates/{$candidate->uuid}")->assertJsonPath('data.abilities.delete', false);
    }

    public function test_admin_can_delete(): void
    {
        $candidate = Candidate::factory()->create();
        $this->actingAsRole(Role::Admin);
        $this->deleteJson("/api/v1/candidates/{$candidate->uuid}")->assertNoContent();
        $this->assertDatabaseHas('audit_logs', ['event' => 'candidate.deleted']);
    }
}

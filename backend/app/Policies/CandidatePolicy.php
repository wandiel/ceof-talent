<?php

namespace App\Policies;

use App\Domain\Identity\Enums\Permission;
use App\Models\Candidate;
use App\Models\User;

/**
 * Acesso a candidatos.
 *
 * O gestor de departamento (candidates.view_department) verá apenas candidatos
 * inscritos em vagas do próprio departamento. As inscrições chegam na Fase 6;
 * até lá esse perfil não enxerga nenhum candidato (ver Candidate::scopeVisibleTo).
 */
class CandidatePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::CandidatesView->value) || $actor->can(Permission::CandidatesViewDepartment->value);
    }

    public function view(User $actor, Candidate $candidate): bool
    {
        if ($actor->can(Permission::CandidatesView->value)) {
            return true;
        }

        return $actor->can(Permission::CandidatesViewDepartment->value)
            && Candidate::query()->visibleTo($actor)->whereKey($candidate->getKey())->exists();
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::CandidatesManage->value);
    }

    public function update(User $actor, Candidate $candidate): bool
    {
        return $actor->can(Permission::CandidatesManage->value);
    }

    public function delete(User $actor, Candidate $candidate): bool
    {
        return $actor->can(Permission::CandidatesDelete->value);
    }

    public function downloadDocuments(User $actor, Candidate $candidate): bool
    {
        return $actor->can(Permission::CandidatesDownloadDocuments->value) && $this->view($actor, $candidate);
    }

    /** Favoritos e etiquetas. */
    public function manageTalentPool(User $actor, Candidate $candidate): bool
    {
        return $actor->can(Permission::TalentPoolManage->value) && $this->view($actor, $candidate);
    }

    /** Duplicidades envolvem o banco inteiro: só para quem vê todos os candidatos. */
    public function reviewDuplicates(User $actor): bool
    {
        return $actor->can(Permission::CandidatesView->value);
    }
}

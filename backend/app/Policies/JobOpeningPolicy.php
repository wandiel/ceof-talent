<?php

namespace App\Policies;

use App\Domain\Identity\Enums\Permission as P;
use App\Domain\Recruitment\Enums\JobStatus;
use App\Models\JobOpening;
use App\Models\User;

/**
 * Acesso a vagas e aos seus processos seletivos.
 *
 * O RH vê todas as vagas; o gestor de departamento vê só as do próprio
 * departamento (JobOpening::scopeVisibleTo). As transições de situação
 * (enviar, aprovar, publicar...) são decididas em JobWorkflow::can().
 */
class JobOpeningPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(P::JobsView->value);
    }

    public function view(User $actor, JobOpening $job): bool
    {
        return $actor->can(P::JobsView->value) && $job->isVisibleTo($actor);
    }

    public function create(User $actor): bool
    {
        return $actor->can(P::JobsRequest->value) || $actor->can(P::JobsManage->value);
    }

    /**
     * Rascunho ou não aprovada: quem solicitou ou o RH.
     * Aprovada, publicada ou suspensa: só o RH (jobs.manage).
     * Encerrada ou cancelada: ninguém.
     */
    public function update(User $actor, JobOpening $job): bool
    {
        if ($job->status->isFinal() || ! $this->view($actor, $job)) {
            return false;
        }
        if ($actor->can(P::JobsManage->value)) {
            return true;
        }

        return in_array($job->status, [JobStatus::Draft, JobStatus::Rejected], true)
            && $job->requested_by === $actor->id
            && $actor->can(P::JobsRequest->value);
    }

    public function viewApplications(User $actor, JobOpening $job): bool
    {
        return $actor->can(P::RecruitmentView->value) && $job->isVisibleTo($actor);
    }

    public function addApplications(User $actor, JobOpening $job): bool
    {
        return $actor->can(P::RecruitmentMove->value)
            && $job->status->acceptsApplications()
            && $job->isVisibleTo($actor);
    }
}

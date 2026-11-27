<?php

namespace App\Policies;

use App\Domain\Identity\Enums\Permission as P;
use App\Models\Application;
use App\Models\Interview;
use App\Models\User;

/**
 * Entrevistas.
 *
 * - Agendar, remarcar, cancelar e registrar ausência: interviews.schedule, nas
 *   vagas que o usuário enxerga (o gestor, só no seu departamento).
 * - Registrar parecer: interviews.evaluate e ser um dos entrevistadores.
 * - Ver: quem acompanha o processo da vaga ou participa da entrevista.
 */
class InterviewPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(P::InterviewsView->value);
    }

    public function view(User $actor, Interview $interview): bool
    {
        return $actor->can(P::InterviewsView->value) && $interview->isVisibleTo($actor);
    }

    public function create(User $actor, Application $application): bool
    {
        return $actor->can(P::InterviewsSchedule->value)
            && $actor->can(P::RecruitmentView->value)
            && $application->jobOpening->isVisibleTo($actor);
    }

    public function update(User $actor, Interview $interview): bool
    {
        return $this->create($actor, $interview->application);
    }

    public function evaluate(User $actor, Interview $interview): bool
    {
        return $actor->can(P::InterviewsEvaluate->value) && $interview->hasInterviewer($actor);
    }

    /**
     * Pareceres dos colegas: o entrevistador só os vê depois de registrar o
     * próprio (evita influência); quem acompanha o processo vê todos.
     */
    public function viewAllEvaluations(User $actor, Interview $interview): bool
    {
        if ($interview->hasInterviewer($actor)) {
            return $interview->evaluations()->where('user_id', $actor->id)->exists();
        }

        return $actor->can(P::RecruitmentView->value) && $interview->application->jobOpening->isVisibleTo($actor);
    }
}

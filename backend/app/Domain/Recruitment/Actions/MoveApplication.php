<?php

namespace App\Domain\Recruitment\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\Permission as P;
use App\Domain\Recruitment\Enums\ApplicationStage as Stage;
use App\Domain\Recruitment\Enums\RejectionReason;
use App\Domain\Recruitment\JobWorkflow;
use App\Models\Application;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Movimenta uma inscrição entre etapas, com histórico imutável.
 *
 * - Etapas intermediárias, reprovação até a avaliação técnica e desistência:
 *   recruitment.move.
 * - Aprovação, contratação, reprovação de quem já foi aprovado e reabertura de
 *   inscrições encerradas: recruitment.decide (RH ou gestor do departamento).
 * - Reprovação exige motivo. Contratação respeita o número de posições e
 *   encerra a vaga automaticamente quando todas são preenchidas.
 */
class MoveApplication
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly JobWorkflow $workflow,
    ) {}

    public function requiredPermission(Application $application, Stage $to): string
    {
        $from = $application->stage;

        if (in_array($to, [Stage::Approved, Stage::Hired], true) || ! $from->isOpen()) {
            return P::RecruitmentDecide->value;
        }
        if ($to === Stage::Rejected && $from->order() >= Stage::Approved->order()) {
            return P::RecruitmentDecide->value;
        }

        return P::RecruitmentMove->value;
    }

    public function canMove(Application $application, Stage $to, User $user): bool
    {
        return $application->stage !== $to
            && $application->jobOpening->status->acceptsApplications()
            && $application->jobOpening->isVisibleTo($user)
            && $user->can($this->requiredPermission($application, $to));
    }

    /** @return array{application: Application, job_closed: bool} */
    public function handle(Application $application, Stage $to, User $user, ?RejectionReason $reason = null, ?string $note = null): array
    {
        $job = $application->jobOpening;

        if (! $job->status->acceptsApplications()) {
            throw ValidationException::withMessages(['stage' => "A vaga está {$job->status->label()}: não é possível movimentar candidatos."]);
        }
        if ($application->stage === $to) {
            throw ValidationException::withMessages(['stage' => 'O candidato já está nesta etapa.']);
        }
        if (! $this->canMove($application, $to, $user)) {
            abort(403, 'Esta movimentação exige uma decisão do RH ou do gestor do departamento.');
        }
        if ($to === Stage::Rejected && ! $reason) {
            throw ValidationException::withMessages(['reason' => 'Informe o motivo da reprovação.']);
        }
        if ($to === Stage::Hired && $job->hiredCount() >= $job->positions_count) {
            throw ValidationException::withMessages(['stage' => 'Todas as posições desta vaga já foram preenchidas.']);
        }
        if ($to === Stage::Hired && $application->stage !== Stage::Approved) {
            throw ValidationException::withMessages(['stage' => 'Só é possível contratar quem está na etapa Aprovação.']);
        }

        return DB::transaction(function () use ($application, $to, $user, $reason, $note, $job) {
            // Bloqueia a vaga para que duas contratações simultâneas não ultrapassem as posições.
            $job->newQuery()->whereKey($job->id)->lockForUpdate()->first();

            $from = $application->stage;
            $application->previous_stage = $from;
            $application->stage = $to;
            $application->stage_changed_at = now();
            $application->rejection_reason = $to === Stage::Rejected ? $reason : null;
            $application->hired_at = $to === Stage::Hired ? now() : null;
            $application->save();

            $note = $note !== null && trim($note) !== '' ? trim($note) : null;
            $application->history()->create(['from_stage' => $from, 'to_stage' => $to, 'reason' => $to === Stage::Rejected ? $reason : null, 'note' => $note, 'user_id' => $user->id]);

            $text = "{$job->code} · {$job->title}: {$from->label()} → {$to->label()}".($reason ? " ({$reason->label()})" : '');
            $this->audit->log('application.moved', $application->candidate, $text, ['stage' => $from->value], ['stage' => $to->value], ['application' => $application->uuid, 'job' => $job->uuid]);

            $closed = $to === Stage::Hired && $this->workflow->closeIfFilled($job->refresh(), $user);

            return ['application' => $application, 'job_closed' => $closed];
        });
    }
}

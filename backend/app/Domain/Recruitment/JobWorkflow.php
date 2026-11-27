<?php

namespace App\Domain\Recruitment;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\Permission as P;
use App\Domain\Recruitment\Enums\ApplicationStage;
use App\Domain\Recruitment\Enums\JobStatus as S;
use App\Models\JobOpening;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ciclo de vida da vaga.
 *
 *   rascunho ─enviar─▶ aguardando aprovação ─aprovar─▶ aprovada ─publicar─▶ publicada ⇄ suspensa
 *        ▲                    │                                              │
 *        └──── não aprovada ◀─┘ (reprovar, com motivo)                       └─encerrar─▶ encerrada ─reabrir─▶ publicada
 *   Cancelar é possível em qualquer situação não final.
 *
 * Quem aprova: o RH (jobs.manage) ou o gestor do departamento da vaga, desde que
 * não tenha sido ele mesmo a solicitar.
 */
class JobWorkflow
{
    /** ação => [situações de origem, situação de destino, exige motivo] */
    public const ACTIONS = [
        'submit' => [[S::Draft, S::Rejected], S::PendingApproval, false],
        'approve' => [[S::PendingApproval], S::Approved, false],
        'reject' => [[S::PendingApproval], S::Rejected, true],
        'publish' => [[S::Approved, S::OnHold], S::Published, false],
        'suspend' => [[S::Published], S::OnHold, true],
        'close' => [[S::Approved, S::Published, S::OnHold], S::Closed, true],
        'reopen' => [[S::Closed], S::Published, true],
        'cancel' => [[S::Draft, S::PendingApproval, S::Rejected, S::Approved, S::Published, S::OnHold], S::Cancelled, true],
    ];

    public const LABELS = [
        'submit' => 'Enviar para aprovação',
        'approve' => 'Aprovar',
        'reject' => 'Devolver sem aprovar',
        'publish' => 'Publicar',
        'suspend' => 'Suspender',
        'close' => 'Encerrar',
        'reopen' => 'Reabrir',
        'cancel' => 'Cancelar vaga',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return list<string> ações que o usuário pode executar agora */
    public function allowedActions(JobOpening $job, User $user): array
    {
        return array_values(array_filter(array_keys(self::ACTIONS), fn (string $action) => $this->can($job, $action, $user)));
    }

    public function can(JobOpening $job, string $action, User $user): bool
    {
        [$from] = self::ACTIONS[$action];
        if (! in_array($job->status, $from, true) || ! $job->isVisibleTo($user)) {
            return false;
        }

        $isRequester = $job->requested_by === $user->id;
        $manages = $user->can(P::JobsManage->value);

        return match ($action) {
            'submit' => $manages || ($isRequester && $user->can(P::JobsRequest->value)),
            'approve', 'reject' => $user->can(P::JobsApprove->value)
                && ($manages || ($job->department_id === $user->department_id && ! $isRequester)),
            'publish', 'suspend', 'close' => $user->can(P::JobsPublish->value),
            'reopen' => $manages && $job->hiredCount() < $job->positions_count,
            'cancel' => $manages || ($isRequester && in_array($job->status, [S::Draft, S::PendingApproval, S::Rejected], true)),
        };
    }

    public function apply(JobOpening $job, string $action, User $user, ?string $reason = null): JobOpening
    {
        abort_unless(array_key_exists($action, self::ACTIONS), 422, 'Ação desconhecida.');
        [, $to, $needsReason] = self::ACTIONS[$action];

        if (! $this->can($job, $action, $user)) {
            abort(403, 'Esta ação não está disponível para a vaga ou para o seu perfil.');
        }

        $reason = $reason !== null ? trim($reason) : null;
        if ($needsReason && ($reason === null || mb_strlen($reason) < 3)) {
            throw ValidationException::withMessages(['reason' => 'Informe o motivo.']);
        }

        if ($action === 'publish' && $job->closes_on && $job->closes_on->isPast() && ! $job->closes_on->isToday()) {
            throw ValidationException::withMessages(['reason' => 'A data limite de inscrições já passou. Edite a vaga antes de publicar.']);
        }

        return DB::transaction(fn () => $this->transition($job, $to, $user, $reason, $action));
    }

    /** Muda a situação, registra histórico e auditoria. Usado também pelo encerramento automático. */
    public function transition(JobOpening $job, S $to, ?User $user, ?string $reason, string $action): JobOpening
    {
        $from = $job->status;
        $job->status = $to;
        $job->status_reason = $reason;

        match ($to) {
            S::PendingApproval => $job->submitted_at = now(),
            S::Approved => [$job->approved_at = now(), $job->approved_by = $user?->id],
            S::Published => [$job->published_at ??= now(), $job->closed_at = null],
            S::Closed, S::Cancelled => $job->closed_at = now(),
            default => null,
        };

        $job->save();
        $job->statusHistory()->create(['from_status' => $from, 'to_status' => $to, 'user_id' => $user?->id, 'reason' => $reason]);
        $this->audit->log("job.{$action}", $job, self::LABELS[$action] ?? $to->label(), ['status' => $from?->value], ['status' => $to->value], $reason ? ['reason' => $reason] : null, $user?->id);

        return $job;
    }

    /** Encerra a vaga quando todas as posições foram preenchidas. */
    public function closeIfFilled(JobOpening $job, User $user): bool
    {
        if ($job->status->isFinal() || $job->applications()->where('stage', ApplicationStage::Hired->value)->count() < $job->positions_count) {
            return false;
        }

        $this->transition($job, S::Closed, $user, 'Todas as posições foram preenchidas.', 'close');

        return true;
    }
}

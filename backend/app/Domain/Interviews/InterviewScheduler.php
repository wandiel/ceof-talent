<?php

namespace App\Domain\Interviews;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\Permission as P;
use App\Domain\Interviews\Enums\InterviewStatus;
use App\Domain\Recruitment\Actions\MoveApplication;
use App\Domain\Recruitment\Enums\ApplicationStage;
use App\Models\Application;
use App\Models\EvaluationCriterion;
use App\Models\Interview;
use App\Models\User;
use App\Notifications\InterviewNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Agenda, remarca, cancela e registra ausência em entrevistas.
 *
 * - Só agenda para inscrições em andamento de vagas que aceitam movimentação.
 * - Entrevistadores precisam estar ativos e ter permissão de registrar parecer.
 * - Detecta conflito de horário dos entrevistadores e do candidato; o agendamento
 *   só segue com conflito quando confirmado (force).
 * - Opcionalmente leva a inscrição para a etapa Entrevista.
 * - Avisa os entrevistadores por e-mail e registra tudo na auditoria.
 */
class InterviewScheduler
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MoveApplication $move,
    ) {}

    /**
     * @param  array{type: string, mode: string, scheduled_at: string, duration_minutes: int, location?: ?string, meeting_url?: ?string, instructions?: ?string, interviewers: list<string>, criteria?: list<string>, force?: bool, move_to_stage?: bool}  $data
     */
    public function schedule(Application $application, array $data, User $actor): Interview
    {
        $job = $application->jobOpening;
        if (! $job->status->acceptsApplications() || ! $application->stage->isOpen()) {
            throw ValidationException::withMessages(['application' => 'Só é possível agendar entrevistas para candidatos em andamento em vagas abertas.']);
        }

        $interviewers = $this->interviewers($data['interviewers']);
        $start = Carbon::parse($data['scheduled_at'])->setTimezone(config('app.timezone'));
        $this->assertFuture($start);
        if (! ($data['force'] ?? false)) {
            $this->assertNoConflicts($start, (int) $data['duration_minutes'], $interviewers, $application, null);
        }

        return DB::transaction(function () use ($application, $data, $actor, $interviewers, $job) {
            $interview = new Interview($this->fields($data));
            $interview->application_id = $application->id;
            $interview->status = InterviewStatus::Scheduled;
            $interview->scheduled_by = $actor->id;
            $interview->criteria = $this->criteria($data['criteria'] ?? null);
            $interview->save();
            $interview->interviewers()->sync($interviewers->modelKeys());

            $when = $interview->scheduled_at->timezone(config('app.timezone'))->format('d/m/Y H:i');
            $text = "{$interview->type->label()} agendada para {$when} ({$job->code} · {$job->title}).";
            $this->audit->log('interview.scheduled', $interview, $text, new: ['scheduled_at' => $interview->scheduled_at->toIso8601String(), 'interviewers' => $interviewers->pluck('name')->all()]);
            $this->audit->log('interview.scheduled', $application->candidate, $text, context: ['interview' => $interview->uuid]);

            if (($data['move_to_stage'] ?? false)
                && $application->stage->order() < ApplicationStage::Interview->order()
                && $actor->can(P::RecruitmentMove->value)) {
                $this->move->handle($application, ApplicationStage::Interview, $actor, null, "Entrevista agendada para {$when}.");
            }

            DB::afterCommit(fn () => Notification::send($interviewers, new InterviewNotification($interview, InterviewNotification::SCHEDULED)));

            return $interview;
        });
    }

    /** Remarca ou ajusta uma entrevista agendada. */
    public function update(Interview $interview, array $data, User $actor): Interview
    {
        $this->assertStatus($interview, [InterviewStatus::Scheduled], 'Só entrevistas agendadas podem ser alteradas.');

        $interviewers = $this->interviewers($data['interviewers']);
        $start = Carbon::parse($data['scheduled_at'])->setTimezone(config('app.timezone'));
        $timeChanged = ! $start->equalTo($interview->scheduled_at) || (int) $data['duration_minutes'] !== $interview->duration_minutes;
        if ($timeChanged) {
            $this->assertFuture($start);
        }
        if (! ($data['force'] ?? false)) {
            $this->assertNoConflicts($start, (int) $data['duration_minutes'], $interviewers, $interview->application, $interview);
        }

        return DB::transaction(function () use ($interview, $data, $interviewers, $timeChanged) {
            $original = $interview->getAttributes();
            $before = $interview->interviewers()->pluck('users.id')->all();

            $interview->fill($this->fields($data));
            if (array_key_exists('criteria', $data) && $data['criteria'] !== null && ! $interview->evaluations()->exists()) {
                $interview->criteria = $this->criteria($data['criteria']);
            }
            if ($timeChanged) {
                $interview->reminded_at = null;
            }
            $interview->save();
            $interview->interviewers()->sync($interviewers->modelKeys());

            $added = $interviewers->whereNotIn('id', $before);
            $kept = $interviewers->whereIn('id', $before);
            $removed = User::query()->whereKey(array_diff($before, $interviewers->modelKeys()))->get();

            $changed = $this->audit->logChanges('interview.updated', $interview, $original, $timeChanged ? 'Entrevista remarcada.' : 'Dados da entrevista alterados.');
            if (! $changed && $added->isEmpty() && $removed->isEmpty()) {
                return $interview;
            }
            if ($added->isNotEmpty() || $removed->isNotEmpty()) {
                $this->audit->log('interview.updated', $interview, 'Entrevistadores alterados.', ['interviewers' => User::query()->whereKey($before)->pluck('name')->all()], ['interviewers' => $interviewers->pluck('name')->all()]);
            }
            if ($timeChanged) {
                $when = $interview->scheduled_at->timezone(config('app.timezone'))->format('d/m/Y H:i');
                $this->audit->log('interview.rescheduled', $interview->application->candidate, "Entrevista remarcada para {$when}.", context: ['interview' => $interview->uuid]);
            }

            DB::afterCommit(function () use ($interview, $added, $kept, $removed, $timeChanged) {
                Notification::send($added, new InterviewNotification($interview, InterviewNotification::SCHEDULED));
                if ($timeChanged) {
                    Notification::send($kept, new InterviewNotification($interview, InterviewNotification::RESCHEDULED));
                }
                Notification::send($removed, new InterviewNotification($interview, InterviewNotification::CANCELLED));
            });

            return $interview;
        });
    }

    public function cancel(Interview $interview, string $reason, User $actor): Interview
    {
        $this->assertStatus($interview, [InterviewStatus::Scheduled], 'Só entrevistas agendadas podem ser canceladas.');

        return DB::transaction(function () use ($interview, $reason) {
            $interview->status = InterviewStatus::Cancelled;
            $interview->status_reason = $reason;
            $interview->save();
            $this->audit->log('interview.cancelled', $interview, 'Entrevista cancelada.', ['status' => 'scheduled'], ['status' => 'cancelled'], ['reason' => $reason]);
            $this->audit->log('interview.cancelled', $interview->application->candidate, "Entrevista cancelada: {$reason}", context: ['interview' => $interview->uuid]);
            $interviewers = $interview->interviewers()->get();
            DB::afterCommit(fn () => Notification::send($interviewers, new InterviewNotification($interview, InterviewNotification::CANCELLED)));

            return $interview;
        });
    }

    public function markNoShow(Interview $interview, ?string $note, User $actor): Interview
    {
        $this->assertStatus($interview, [InterviewStatus::Scheduled], 'Só entrevistas agendadas podem ser marcadas como ausência.');
        if ($interview->scheduled_at->isFuture()) {
            throw ValidationException::withMessages(['status' => 'A entrevista ainda não aconteceu.']);
        }

        $interview->status = InterviewStatus::NoShow;
        $interview->status_reason = $note;
        $interview->save();
        $this->audit->log('interview.no_show', $interview, 'Candidato não compareceu.', ['status' => 'scheduled'], ['status' => 'no_show'], $note ? ['reason' => $note] : null);
        $this->audit->log('interview.no_show', $interview->application->candidate, 'Não compareceu à entrevista.', context: ['interview' => $interview->uuid]);

        return $interview;
    }

    /** Usuários que podem ser escolhidos como entrevistadores. */
    public static function eligibleInterviewers(): Builder
    {
        return User::query()->where('is_active', true)->permission(P::InterviewsEvaluate->value)->orderBy('name');
    }

    /**
     * Entrevistas agendadas que se sobrepõem ao intervalo, para os entrevistadores ou o candidato.
     *
     * @param  Collection<int, User>  $interviewers
     * @return list<string>
     */
    public function conflicts(Carbon $start, int $minutes, Collection $interviewers, Application $application, ?Interview $ignore): array
    {
        $end = $start->copy()->addMinutes($minutes);
        $overlapping = Interview::query()
            ->with(['interviewers:id,name', 'application.candidate:id,full_name,social_name'])
            ->where('status', InterviewStatus::Scheduled->value)
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->id))
            ->where('scheduled_at', '<', $end)
            ->whereRaw('scheduled_at + make_interval(mins => duration_minutes) > ?', [$start->toIso8601String()])
            ->where(fn ($q) => $q
                ->whereHas('interviewers', fn ($i) => $i->whereIn('users.id', $interviewers->modelKeys()))
                ->orWhereHas('application', fn ($a) => $a->where('candidate_id', $application->candidate_id)))
            ->get();

        $messages = [];
        foreach ($overlapping as $other) {
            $time = $other->scheduled_at->timezone(config('app.timezone'))->format('d/m H:i');
            foreach ($other->interviewers->whereIn('id', $interviewers->modelKeys()) as $user) {
                $messages[] = "{$user->name} já tem entrevista às {$time}.";
            }
            if ($other->application->candidate_id === $application->candidate_id) {
                $messages[] = 'O candidato já tem outra entrevista às '.$time.'.';
            }
        }

        return array_values(array_unique($messages));
    }

    /** @param  Collection<int, User>  $interviewers */
    private function assertNoConflicts(Carbon $start, int $minutes, Collection $interviewers, Application $application, ?Interview $ignore): void
    {
        $conflicts = $this->conflicts($start, $minutes, $interviewers, $application, $ignore);
        if ($conflicts) {
            throw ValidationException::withMessages(['conflicts' => $conflicts]);
        }
    }

    /**
     * @param  list<string>  $uuids
     * @return Collection<int, User>
     */
    private function interviewers(array $uuids): Collection
    {
        $users = self::eligibleInterviewers()->whereIn('uuid', $uuids)->get();
        if ($users->count() !== count(array_unique($uuids))) {
            throw ValidationException::withMessages(['interviewers' => 'Escolha entrevistadores ativos e com permissão para registrar parecer.']);
        }

        return $users;
    }

    /**
     * Copia os critérios escolhidos (ou todos os ativos).
     *
     * @param  list<string>|null  $uuids
     * @return list<array{id: string, name: string, description: string|null}>
     */
    private function criteria(?array $uuids): array
    {
        $query = EvaluationCriterion::query()->where('is_active', true)->ordered();
        if ($uuids !== null) {
            $query->whereIn('uuid', $uuids);
        }
        $criteria = $query->get()->map->snapshot()->all();
        if ($criteria === []) {
            throw ValidationException::withMessages(['criteria' => 'Escolha ao menos um critério de avaliação ativo.']);
        }

        return $criteria;
    }

    /** @return array<string, mixed> */
    private function fields(array $data): array
    {
        return [
            'type' => $data['type'],
            'mode' => $data['mode'],
            'scheduled_at' => Carbon::parse($data['scheduled_at'])->setTimezone(config('app.timezone')),
            'duration_minutes' => (int) $data['duration_minutes'],
            'location' => $data['location'] ?? null,
            'meeting_url' => $data['meeting_url'] ?? null,
            'instructions' => $data['instructions'] ?? null,
        ];
    }

    private function assertFuture(Carbon $start): void
    {
        if ($start->isPast()) {
            throw ValidationException::withMessages(['scheduled_at' => 'Escolha uma data e um horário futuros.']);
        }
    }

    /** @param  list<InterviewStatus>  $allowed */
    private function assertStatus(Interview $interview, array $allowed, string $message): void
    {
        if (! in_array($interview->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }
}

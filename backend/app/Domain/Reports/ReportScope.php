<?php

namespace App\Domain\Reports;

use App\Domain\Recruitment\Enums\JobStatus;
use App\Models\Application;
use App\Models\ApplicationStageHistory;
use App\Models\Candidate;
use App\Models\Interview;
use App\Models\JobOpening;
use Illuminate\Database\Eloquent\Builder;

/**
 * Consultas-base já filtradas pelo que o usuário pode ver e pelos filtros escolhidos.
 * Toda métrica do dashboard e todo relatório parte daqui.
 */
final class ReportScope
{
    public function __construct(public readonly ReportFilters $filters) {}

    /** @return Builder<JobOpening> */
    public function jobs(): Builder
    {
        $f = $this->filters;

        return JobOpening::query()->visibleTo($f->user)
            ->when($f->department, fn (Builder $q, $d) => $q->where('job_openings.department_id', $d->id))
            ->when($f->unit, fn (Builder $q, $u) => $q->where('job_openings.unit_id', $u->id))
            ->when($f->area, fn (Builder $q, $a) => $q->where('job_openings.professional_area', $a->value));
    }

    /** Vagas que aceitam inscrições agora (aprovadas, publicadas ou suspensas). */
    public function openJobs(): Builder
    {
        return $this->jobs()->whereIn('job_openings.status', [JobStatus::Approved->value, JobStatus::Published->value, JobStatus::OnHold->value]);
    }

    /** @return Builder<Application> */
    public function applications(): Builder
    {
        return Application::query()->whereIn('applications.job_opening_id', $this->jobs()->select('job_openings.id'));
    }

    /** Inscrições criadas no período. */
    public function applicationsInPeriod(): Builder
    {
        return $this->period($this->applications(), 'applications.created_at');
    }

    /** Movimentações registradas no período, das inscrições visíveis. */
    public function movements(string $toStage): Builder
    {
        return $this->period(
            ApplicationStageHistory::query()->where('application_stage_history.to_stage', $toStage)
                ->whereIn('application_stage_history.application_id', $this->applications()->select('applications.id')),
            'application_stage_history.created_at',
        );
    }

    /** @return Builder<Interview> */
    public function interviews(): Builder
    {
        return Interview::query()->whereIn('interviews.application_id', $this->applications()->select('applications.id'));
    }

    /**
     * Candidatos visíveis. Com filtro de departamento ou unidade, só os inscritos em vagas
     * desse departamento ou unidade.
     *
     * @return Builder<Candidate>
     */
    public function candidates(): Builder
    {
        $f = $this->filters;

        return Candidate::query()->visibleTo($f->user)
            ->when($f->area, fn (Builder $q, $a) => $q->where('candidates.professional_area', $a->value))
            ->when($f->department || $f->unit, fn (Builder $q) => $q->whereIn('candidates.id', $this->applications()->select('applications.candidate_id')));
    }

    public function candidatesInPeriod(): Builder
    {
        return $this->period($this->candidates(), 'candidates.created_at');
    }

    /**
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public function period(Builder $query, string $column): Builder
    {
        return $query
            ->when($this->filters->from, fn (Builder $q, $from) => $q->where($column, '>=', $from->toDateTimeString()))
            ->when($this->filters->to, fn (Builder $q, $to) => $q->where($column, '<=', $to->toDateTimeString()));
    }
}

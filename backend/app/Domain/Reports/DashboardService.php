<?php

namespace App\Domain\Reports;

use App\Domain\Candidates\Enums\CandidateStatus;
use App\Domain\Candidates\Enums\ProfessionalArea;
use App\Domain\Interviews\Enums\InterviewStatus;
use App\Domain\Recruitment\Enums\ApplicationStage;
use App\Models\Department;
use Carbon\CarbonImmutable;

/** Indicadores do painel inicial, calculados sobre os filtros escolhidos. */
final class DashboardService
{
    /** @return array<string, mixed> */
    public function build(ReportFilters $filters): array
    {
        $scope = new ReportScope($filters);

        return [
            'kpis' => $this->kpis($scope),
            'monthly' => $this->monthly($scope),
            'funnel' => $this->funnel($scope),
            'by_department' => $this->byDepartment($scope),
            'by_area' => $this->byArea($scope),
            'upcoming_interviews' => $scope->interviews()
                ->where('status', InterviewStatus::Scheduled->value)->where('scheduled_at', '>=', now())
                ->with('application.candidate:id,full_name,social_name', 'application.jobOpening:id,uuid,code,title')
                ->orderBy('scheduled_at')->limit(5)->get()
                ->map(fn ($i) => [
                    'id' => $i->uuid,
                    'scheduled_at' => $i->scheduled_at->toIso8601String(),
                    'type_label' => $i->type->label(),
                    'candidate' => $i->application->candidate->social_name ?: $i->application->candidate->full_name,
                    'job' => $i->application->jobOpening->code.' · '.$i->application->jobOpening->title,
                ])->all(),
        ];
    }

    /** @return array<string, int|float|null> */
    private function kpis(ReportScope $scope): array
    {
        $hires = $scope->movements(ApplicationStage::Hired->value);

        return [
            'total_candidates' => $scope->candidates()->count(),
            'new_candidates' => $scope->candidatesInPeriod()->count(),
            'active_candidates' => $scope->candidates()->where('candidates.status', CandidateStatus::Active->value)->count(),
            'open_jobs' => $scope->openJobs()->count(),
            'open_positions' => (int) $scope->openJobs()->sum('positions_count'),
            'active_processes' => $scope->applications()
                ->whereIn('applications.job_opening_id', $scope->openJobs()->select('job_openings.id'))
                ->whereIn('applications.stage', array_map(fn ($s) => $s->value, array_filter(ApplicationStage::cases(), fn ($s) => $s->isOpen())))
                ->count(),
            'scheduled_interviews' => $scope->interviews()->where('status', InterviewStatus::Scheduled->value)->where('scheduled_at', '>=', now())->count(),
            'approvals' => $scope->movements(ApplicationStage::Approved->value)->distinct()->count('application_stage_history.application_id'),
            'hires' => (clone $hires)->distinct()->count('application_stage_history.application_id'),
            'avg_days_to_hire' => $this->avgDaysToHire($scope),
        ];
    }

    private function avgDaysToHire(ReportScope $scope): ?float
    {
        $avg = $scope->period($scope->applications()->whereNotNull('applications.hired_at'), 'applications.hired_at')
            ->selectRaw('avg(extract(epoch from (applications.hired_at - applications.created_at)) / 86400) as d')
            ->value('d');

        return $avg === null ? null : round((float) $avg, 1);
    }

    /**
     * Séries mensais: novos cadastros, inscrições e contratações. Sem período escolhido,
     * mostra os últimos 12 meses.
     *
     * @return list<array{month: string, label: string, candidates: int, applications: int, hires: int}>
     */
    private function monthly(ReportScope $scope): array
    {
        $tz = config('app.timezone');
        $to = ($scope->filters->to ?? CarbonImmutable::now($tz))->startOfMonth();
        $from = ($scope->filters->from ?? $to->subMonths(11))->startOfMonth();
        if ($from->diffInMonths($to) > 23) {
            $from = $to->subMonths(23);
        }
        $range = new ReportScope(new ReportFilters($scope->filters->user, $from, $to->endOfMonth(), $scope->filters->department, $scope->filters->unit, $scope->filters->area));

        $count = fn ($query, string $column) => $query->selectRaw("to_char(date_trunc('month', {$column}), 'YYYY-MM') as m, count(*) as c")
            ->groupBy('m')->pluck('c', 'm');

        $candidates = $count($range->candidatesInPeriod()->reorder(), 'candidates.created_at');
        $applications = $count($range->applicationsInPeriod()->reorder(), 'applications.created_at');
        $hires = $count($range->movements(ApplicationStage::Hired->value)->reorder(), 'application_stage_history.created_at');

        $months = [];
        for ($m = $from; $m <= $to; $m = $m->addMonth()) {
            $key = $m->format('Y-m');
            $months[] = [
                'month' => $key,
                'label' => ucfirst($m->locale('pt_BR')->isoFormat('MMM/YY')),
                'candidates' => (int) ($candidates[$key] ?? 0),
                'applications' => (int) ($applications[$key] ?? 0),
                'hires' => (int) ($hires[$key] ?? 0),
            ];
        }

        return $months;
    }

    /**
     * Funil das inscrições do período: quantas chegaram a cada etapa (pelo histórico),
     * quantas foram reprovadas e quantas desistiram.
     *
     * @return array{stages: list<array{stage: string, label: string, count: int}>, rejected: int, withdrawn: int, total: int}
     */
    public function funnel(ReportScope $scope): array
    {
        $pipeline = ApplicationStage::pipeline();
        $order = array_flip(array_map(fn ($s) => $s->value, $pipeline));

        $rows = $scope->applicationsInPeriod()
            ->leftJoin('application_stage_history as h', 'h.application_id', '=', 'applications.id')
            ->groupBy('applications.id', 'applications.stage')
            ->selectRaw("applications.stage as st, string_agg(coalesce(h.to_stage, ''), ',') as reached")
            ->get();

        $max = array_fill(0, count($pipeline), 0);
        $rejected = $withdrawn = 0;
        foreach ($rows as $row) {
            $stages = array_filter([...explode(',', (string) $row->reached), $row->st]);
            $best = 0;
            foreach ($stages as $s) {
                $best = max($best, $order[$s] ?? 0);
            }
            $max[$best]++;
            $rejected += $row->st === ApplicationStage::Rejected->value ? 1 : 0;
            $withdrawn += $row->st === ApplicationStage::Withdrawn->value ? 1 : 0;
        }

        $reached = [];
        $running = 0;
        for ($i = count($pipeline) - 1; $i >= 0; $i--) {
            $running += $max[$i];
            $reached[$i] = $running;
        }

        return [
            'stages' => array_map(fn ($s, $i) => ['stage' => $s->value, 'label' => $s->label(), 'count' => $reached[$i]], $pipeline, array_keys($pipeline)),
            'rejected' => $rejected,
            'withdrawn' => $withdrawn,
            'total' => $rows->count(),
        ];
    }

    /** @return list<array{id: string, name: string, open_jobs: int, applications: int, hires: int}> */
    private function byDepartment(ReportScope $scope): array
    {
        $open = $scope->openJobs()->reorder()->groupBy('job_openings.department_id')->selectRaw('job_openings.department_id as d, count(*) as c')->pluck('c', 'd');
        $apps = $scope->applicationsInPeriod()->reorder()->join('job_openings as j', 'j.id', '=', 'applications.job_opening_id')
            ->groupBy('j.department_id')->selectRaw('j.department_id as d, count(*) as c')->pluck('c', 'd');
        $hires = $scope->movements(ApplicationStage::Hired->value)->reorder()
            ->join('applications as a', 'a.id', '=', 'application_stage_history.application_id')
            ->join('job_openings as j', 'j.id', '=', 'a.job_opening_id')
            ->groupBy('j.department_id')->selectRaw('j.department_id as d, count(distinct a.id) as c')->pluck('c', 'd');

        $ids = $open->keys()->merge($apps->keys())->merge($hires->keys())->unique();

        return Department::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'uuid', 'name'])
            ->map(fn (Department $d) => [
                'id' => $d->uuid,
                'name' => $d->name,
                'open_jobs' => (int) ($open[$d->id] ?? 0),
                'applications' => (int) ($apps[$d->id] ?? 0),
                'hires' => (int) ($hires[$d->id] ?? 0),
            ])->sortByDesc('applications')->values()->all();
    }

    /** @return list<array{area: string, label: string, count: int}> */
    private function byArea(ReportScope $scope): array
    {
        return $scope->candidates()->reorder()->groupBy('candidates.professional_area')
            ->selectRaw('candidates.professional_area as a, count(*) as c')->orderByDesc('c')->get()
            ->map(fn ($r) => ['area' => $r->a, 'label' => ProfessionalArea::labelFor($r->a) ?? $r->a, 'count' => (int) $r->c])
            ->all();
    }
}

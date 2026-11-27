<?php

namespace App\Domain\Reports;

use App\Domain\Candidates\Enums\CandidateSource;
use App\Domain\Candidates\Enums\CandidateStatus;
use App\Domain\Candidates\Enums\EducationLevel;
use App\Domain\Candidates\Enums\ProfessionalArea;
use App\Domain\Recruitment\Enums\ApplicationStage;
use App\Domain\Recruitment\Enums\JobStatus;
use App\Models\ApplicationStageHistory;
use App\Models\Candidate;
use App\Models\Interview;
use App\Models\JobOpening;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Catálogo de relatórios. Cada relatório tem colunas tipadas (para formatar na tela, no PDF e
 * na planilha) e uma função que gera as linhas a partir do escopo filtrado.
 *
 * Tipos de coluna: text, number, decimal, percent, date, datetime.
 */
final class ReportCatalog
{
    /** @return array<string, array{group: string, title: string, description: string, columns: list<array{key: string, label: string, type: string}>}> */
    public function all(): array
    {
        return array_map(fn (array $d) => array_diff_key($d, ['rows' => true]), $this->definitions());
    }

    /** @return array{group: string, title: string, description: string, columns: list<array{key: string, label: string, type: string}>, rows: \Closure(ReportScope): iterable<array<string, mixed>>} */
    public function get(string $key): array
    {
        return $this->definitions()[$key] ?? throw new NotFoundHttpException('Relatório não encontrado.');
    }

    /** @return LazyCollection<int, array<string, mixed>> */
    public function rows(string $key, ReportScope $scope): LazyCollection
    {
        $rows = $this->get($key)['rows'];

        return LazyCollection::make(fn () => yield from $rows($scope));
    }

    private static function col(string $key, string $label, string $type = 'text'): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type];
    }

    private static function name(?Candidate $c): string
    {
        return $c ? ($c->social_name ?: $c->full_name) : '—';
    }

    private static function date(mixed $value): ?string
    {
        return $value ? CarbonImmutable::parse($value)->toDateString() : null;
    }

    /**
     * Agrupa candidatos por uma coluna e devolve total, ativos e percentual.
     *
     * @param  \Closure(?string): string  $label
     * @return Collection<int, array<string, mixed>>
     */
    private static function grouped(ReportScope $scope, string $expr, \Closure $label): Collection
    {
        $rows = $scope->candidatesInPeriod()->reorder()
            ->selectRaw("{$expr} as g, count(*) as total, count(*) filter (where candidates.status = ?) as active", [CandidateStatus::Active->value])
            ->groupByRaw($expr)->orderByDesc('total')->get();
        $sum = max(1, (int) $rows->sum('total'));

        return $rows->map(fn ($r) => ['group' => $label($r->g), 'total' => (int) $r->total, 'active' => (int) $r->active, 'share' => round($r->total * 100 / $sum, 1)]);
    }

    /** @return array<string, array<string, mixed>> */
    private function definitions(): array
    {
        $groupCols = fn (string $label) => [self::col('group', $label), self::col('total', 'Candidatos', 'number'), self::col('active', 'Ativos', 'number'), self::col('share', '% do total', 'percent')];
        $hiredStage = ApplicationStage::Hired->value;

        return [
            // ── Banco de currículos ─────────────────────────────────────────────
            'candidates_general' => [
                'group' => 'Banco de currículos',
                'title' => 'Currículos cadastrados',
                'description' => 'Lista geral dos candidatos cadastrados no período.',
                'columns' => [
                    self::col('name', 'Candidato'), self::col('email', 'E-mail'), self::col('phone', 'Telefone'),
                    self::col('city', 'Cidade/UF'), self::col('area', 'Área'), self::col('education', 'Escolaridade'),
                    self::col('source', 'Origem'), self::col('status', 'Situação'), self::col('created_at', 'Cadastro', 'date'),
                ],
                'rows' => fn (ReportScope $s) => $s->candidatesInPeriod()->orderBy('candidates.full_name')->orderBy('candidates.id')->lazy(500)
                    ->map(fn (Candidate $c) => [
                        'name' => self::name($c), 'email' => $c->email, 'phone' => $c->phone,
                        'city' => $c->city ? $c->city.($c->state ? '/'.$c->state : '') : null,
                        'area' => $c->professional_area?->label(), 'education' => $c->education_level?->label(),
                        'source' => $c->source?->label(), 'status' => $c->status?->label(), 'created_at' => self::date($c->created_at),
                    ]),
            ],
            'candidates_by_area' => [
                'group' => 'Banco de currículos', 'title' => 'Currículos por área profissional',
                'description' => 'Quantidade de candidatos por área.', 'columns' => $groupCols('Área profissional'),
                'rows' => fn (ReportScope $s) => self::grouped($s, 'candidates.professional_area', fn ($v) => ProfessionalArea::labelFor($v) ?? 'Não informada'),
            ],
            'candidates_by_education' => [
                'group' => 'Banco de currículos', 'title' => 'Currículos por escolaridade',
                'description' => 'Quantidade de candidatos por nível de escolaridade.', 'columns' => $groupCols('Escolaridade'),
                'rows' => fn (ReportScope $s) => self::grouped($s, 'candidates.education_level', fn ($v) => EducationLevel::labelFor($v) ?? 'Não informada')
                    ->sortBy(fn ($r) => array_search($r['group'], array_column(EducationLevel::options(), 'label'), true))->values(),
            ],
            'candidates_by_city' => [
                'group' => 'Banco de currículos', 'title' => 'Currículos por cidade',
                'description' => 'Quantidade de candidatos por cidade e estado.', 'columns' => $groupCols('Cidade/UF'),
                'rows' => fn (ReportScope $s) => self::grouped($s, "coalesce(nullif(trim(candidates.city), '') || coalesce('/' || candidates.state, ''), '')", fn ($v) => $v ?: 'Não informada'),
            ],
            'new_candidates' => [
                'group' => 'Banco de currículos', 'title' => 'Novos cadastros por mês',
                'description' => 'Candidatos cadastrados a cada mês, por origem.',
                'columns' => [self::col('month', 'Mês'), self::col('total', 'Novos cadastros', 'number'), self::col('internal', 'Cadastro interno', 'number'), self::col('portal', 'Portal', 'number'), self::col('other', 'Outras origens', 'number')],
                'rows' => fn (ReportScope $s) => $s->candidatesInPeriod()->reorder()
                    ->selectRaw("date_trunc('month', candidates.created_at) as m, count(*) as total, count(*) filter (where source = 'internal') as internal, count(*) filter (where source = 'portal') as portal")
                    ->groupBy('m')->orderBy('m')->get()
                    ->map(fn ($r) => ['month' => ucfirst(CarbonImmutable::parse($r->m)->locale('pt_BR')->isoFormat('MMMM [de] YYYY')), 'total' => (int) $r->total, 'internal' => (int) $r->internal, 'portal' => (int) $r->portal, 'other' => $r->total - $r->internal - $r->portal]),
            ],
            'sources' => [
                'group' => 'Banco de currículos', 'title' => 'Origem dos candidatos',
                'description' => 'De onde vêm os candidatos e quantos deles foram contratados.',
                'columns' => [self::col('source', 'Origem'), self::col('candidates', 'Candidatos', 'number'), self::col('share', '% do total', 'percent'), self::col('applications', 'Inscrições', 'number'), self::col('hires', 'Contratações', 'number')],
                'rows' => function (ReportScope $s) use ($hiredStage) {
                    $rows = $s->candidatesInPeriod()->reorder()
                        ->leftJoin('applications as a', 'a.candidate_id', '=', 'candidates.id')
                        ->selectRaw('candidates.source as src, count(distinct candidates.id) as candidates, count(a.id) as applications, count(a.id) filter (where a.stage = ?) as hires', [$hiredStage])
                        ->groupBy('candidates.source')->orderByDesc('candidates')->get();
                    $sum = max(1, (int) $rows->sum('candidates'));

                    return $rows->map(fn ($r) => ['source' => CandidateSource::labelFor($r->src) ?? $r->src, 'candidates' => (int) $r->candidates, 'share' => round($r->candidates * 100 / $sum, 1), 'applications' => (int) $r->applications, 'hires' => (int) $r->hires]);
                },
            ],

            // ── Vagas e processos ───────────────────────────────────────────────
            'jobs_open' => [
                'group' => 'Vagas e processos', 'title' => 'Vagas abertas',
                'description' => 'Vagas aprovadas, publicadas ou suspensas, com o andamento das inscrições.',
                'columns' => [self::col('code', 'Código'), self::col('title', 'Vaga'), self::col('department', 'Departamento'), self::col('unit', 'Unidade'), self::col('status', 'Situação'), self::col('positions', 'Posições', 'number'), self::col('hired', 'Contratados', 'number'), self::col('in_process', 'Em processo', 'number'), self::col('published_at', 'Publicada em', 'date'), self::col('closes_on', 'Inscrições até', 'date')],
                'rows' => fn (ReportScope $s) => $s->period($s->openJobs(), 'job_openings.created_at')->with('department:id,name', 'unit:id,name')
                    ->withCount(['applications as hired' => fn ($q) => $q->where('stage', $hiredStage), 'applications as in_process' => fn ($q) => $q->whereNotIn('stage', ['hired', 'rejected', 'withdrawn'])])
                    ->orderBy('code')->get()
                    ->map(fn (JobOpening $j) => ['code' => $j->code, 'title' => $j->title, 'department' => $j->department?->name, 'unit' => $j->unit?->name, 'status' => $j->status->label(), 'positions' => $j->positions_count, 'hired' => $j->hired, 'in_process' => $j->in_process, 'published_at' => self::date($j->published_at), 'closes_on' => self::date($j->closes_on)]),
            ],
            'jobs_closed' => [
                'group' => 'Vagas e processos', 'title' => 'Vagas encerradas',
                'description' => 'Vagas encerradas ou canceladas no período, com o tempo em aberto.',
                'columns' => [self::col('code', 'Código'), self::col('title', 'Vaga'), self::col('department', 'Departamento'), self::col('status', 'Situação'), self::col('positions', 'Posições', 'number'), self::col('hired', 'Contratados', 'number'), self::col('published_at', 'Publicada em', 'date'), self::col('closed_at', 'Encerrada em', 'date'), self::col('days_open', 'Dias em aberto', 'number'), self::col('reason', 'Motivo')],
                'rows' => fn (ReportScope $s) => $s->period($s->jobs()->whereIn('job_openings.status', [JobStatus::Closed->value, JobStatus::Cancelled->value]), 'job_openings.closed_at')
                    ->with('department:id,name')->withCount(['applications as hired' => fn ($q) => $q->where('stage', $hiredStage)])
                    ->orderByDesc('closed_at')->get()
                    ->map(fn (JobOpening $j) => ['code' => $j->code, 'title' => $j->title, 'department' => $j->department?->name, 'status' => $j->status->label(), 'positions' => $j->positions_count, 'hired' => $j->hired, 'published_at' => self::date($j->published_at), 'closed_at' => self::date($j->closed_at), 'days_open' => $j->published_at && $j->closed_at ? (int) $j->published_at->diffInDays($j->closed_at) : null, 'reason' => $j->status_reason]),
            ],
            'processes' => [
                'group' => 'Vagas e processos', 'title' => 'Processos seletivos',
                'description' => 'Resumo de cada processo: inscritos, em andamento, reprovados, desistentes e contratados.',
                'columns' => [self::col('code', 'Código'), self::col('title', 'Vaga'), self::col('department', 'Departamento'), self::col('status', 'Situação da vaga'), self::col('total', 'Inscritos', 'number'), self::col('open', 'Em andamento', 'number'), self::col('interviews', 'Entrevistas', 'number'), self::col('rejected', 'Reprovados', 'number'), self::col('withdrawn', 'Desistentes', 'number'), self::col('hired', 'Contratados', 'number')],
                'rows' => fn (ReportScope $s) => $s->period($s->jobs()->whereNotIn('job_openings.status', [JobStatus::Draft->value, JobStatus::PendingApproval->value, JobStatus::Rejected->value]), 'job_openings.created_at')
                    ->with('department:id,name')
                    ->withCount([
                        'applications as total',
                        'applications as open' => fn ($q) => $q->whereNotIn('stage', ['hired', 'rejected', 'withdrawn']),
                        'applications as rejected' => fn ($q) => $q->where('stage', 'rejected'),
                        'applications as withdrawn' => fn ($q) => $q->where('stage', 'withdrawn'),
                        'applications as hired' => fn ($q) => $q->where('stage', $hiredStage),
                    ])
                    ->addSelect(['interviews' => Interview::query()->selectRaw('count(*)')->join('applications as ia', 'ia.id', '=', 'interviews.application_id')->whereColumn('ia.job_opening_id', 'job_openings.id')])
                    ->orderBy('code')->get()
                    ->map(fn (JobOpening $j) => ['code' => $j->code, 'title' => $j->title, 'department' => $j->department?->name, 'status' => $j->status->label(), 'total' => $j->total, 'open' => $j->open, 'interviews' => (int) $j->interviews, 'rejected' => $j->rejected, 'withdrawn' => $j->withdrawn, 'hired' => $j->hired]),
            ],
            'candidates_by_stage' => [
                'group' => 'Vagas e processos', 'title' => 'Candidatos por etapa',
                'description' => 'Etapa atual das inscrições feitas no período.',
                'columns' => [self::col('stage', 'Etapa'), self::col('total', 'Inscrições', 'number'), self::col('share', '% do total', 'percent')],
                'rows' => function (ReportScope $s) {
                    $counts = $s->applicationsInPeriod()->reorder()->groupBy('applications.stage')->selectRaw('applications.stage as st, count(*) as c')->pluck('c', 'st');
                    $sum = max(1, (int) $counts->sum());

                    return collect(ApplicationStage::cases())->map(fn ($st) => ['stage' => $st->label(), 'total' => (int) ($counts[$st->value] ?? 0), 'share' => round(($counts[$st->value] ?? 0) * 100 / $sum, 1)]);
                },
            ],
            'interviews' => [
                'group' => 'Entrevistas e contratações', 'title' => 'Entrevistas',
                'description' => 'Entrevistas marcadas para o período, com situação e média dos pareceres.',
                'columns' => [self::col('scheduled_at', 'Data e hora', 'datetime'), self::col('candidate', 'Candidato'), self::col('job', 'Vaga'), self::col('type', 'Tipo'), self::col('mode', 'Formato'), self::col('interviewers', 'Entrevistadores'), self::col('status', 'Situação'), self::col('evaluations', 'Pareceres', 'number'), self::col('average', 'Média', 'decimal')],
                'rows' => fn (ReportScope $s) => $s->period($s->interviews(), 'interviews.scheduled_at')
                    ->with('application.candidate:id,full_name,social_name', 'application.jobOpening:id,code,title', 'interviewers:id,name')
                    ->withCount('evaluations')->withAvg('evaluations', 'average_score')
                    ->orderBy('scheduled_at')->get()
                    ->map(fn (Interview $i) => ['scheduled_at' => $i->scheduled_at->timezone(config('app.timezone'))->format('Y-m-d H:i'), 'candidate' => self::name($i->application->candidate), 'job' => $i->application->jobOpening->code.' · '.$i->application->jobOpening->title, 'type' => $i->type->label(), 'mode' => $i->mode->label(), 'interviewers' => $i->interviewers->pluck('name')->join(', '), 'status' => $i->status->label(), 'evaluations' => $i->evaluations_count, 'average' => $i->evaluations_avg_average_score !== null ? round((float) $i->evaluations_avg_average_score, 2) : null]),
            ],
            'approvals' => [
                'group' => 'Entrevistas e contratações', 'title' => 'Aprovações',
                'description' => 'Candidatos levados à etapa de aprovação no período.',
                'columns' => [self::col('date', 'Data', 'date'), self::col('candidate', 'Candidato'), self::col('job', 'Vaga'), self::col('department', 'Departamento'), self::col('current', 'Etapa atual'), self::col('user', 'Registrado por')],
                'rows' => fn (ReportScope $s) => self::movementRows($s, ApplicationStage::Approved->value),
            ],
            'hires' => [
                'group' => 'Entrevistas e contratações', 'title' => 'Contratações',
                'description' => 'Contratações registradas no período e quantos dias levaram desde a inscrição.',
                'columns' => [self::col('date', 'Data', 'date'), self::col('candidate', 'Candidato'), self::col('job', 'Vaga'), self::col('department', 'Departamento'), self::col('source', 'Origem do candidato'), self::col('days', 'Dias até a contratação', 'number'), self::col('user', 'Registrado por')],
                'rows' => fn (ReportScope $s) => self::movementRows($s, $hiredStage),
            ],
            'time_to_hire' => [
                'group' => 'Entrevistas e contratações', 'title' => 'Tempo médio de contratação',
                'description' => 'Dias entre a inscrição e a contratação, por departamento.',
                'columns' => [self::col('department', 'Departamento'), self::col('hires', 'Contratações', 'number'), self::col('avg', 'Média (dias)', 'decimal'), self::col('min', 'Menor (dias)', 'number'), self::col('max', 'Maior (dias)', 'number')],
                'rows' => function (ReportScope $s) {
                    $days = 'extract(epoch from (applications.hired_at - applications.created_at)) / 86400';
                    $base = $s->period($s->applications()->whereNotNull('applications.hired_at'), 'applications.hired_at')->reorder();
                    $rows = (clone $base)->join('job_openings as j', 'j.id', '=', 'applications.job_opening_id')->join('departments as d', 'd.id', '=', 'j.department_id')
                        ->groupBy('d.name')->orderBy('d.name')
                        ->selectRaw("d.name as department, count(*) as hires, avg({$days}) as avg, min({$days}) as min, max({$days}) as max")->get();
                    $all = $base->selectRaw("count(*) as hires, avg({$days}) as avg, min({$days}) as min, max({$days}) as max")->first();
                    $map = fn ($r, $label) => ['department' => $label, 'hires' => (int) $r->hires, 'avg' => $r->avg !== null ? round((float) $r->avg, 1) : null, 'min' => $r->min !== null ? (int) floor((float) $r->min) : null, 'max' => $r->max !== null ? (int) floor((float) $r->max) : null];

                    return $rows->map(fn ($r) => $map($r, $r->department))->when($rows->count() > 1 && $all, fn ($c) => $c->push($map($all, 'Todos os departamentos')));
                },
            ],
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private static function movementRows(ReportScope $s, string $stage): Collection
    {
        return $s->movements($stage)
            ->with('user:id,name', 'application.candidate:id,full_name,social_name,source', 'application.jobOpening:id,code,title,department_id', 'application.jobOpening.department:id,name')
            ->orderBy('application_stage_history.created_at')->get()
            ->unique('application_id')->values()
            ->map(fn (ApplicationStageHistory $h) => [
                'date' => self::date($h->created_at),
                'candidate' => self::name($h->application->candidate),
                'job' => $h->application->jobOpening->code.' · '.$h->application->jobOpening->title,
                'department' => $h->application->jobOpening->department?->name,
                'current' => $h->application->stage->label(),
                'source' => $h->application->candidate?->source?->label(),
                'days' => (int) $h->application->created_at->diffInDays($h->created_at),
                'user' => $h->user?->name ?? 'Sistema',
            ]);
    }
}

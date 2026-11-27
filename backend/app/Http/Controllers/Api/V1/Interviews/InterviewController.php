<?php

namespace App\Http\Controllers\Api\V1\Interviews;

use App\Domain\Interviews\Actions\SubmitEvaluation;
use App\Domain\Interviews\Enums\InterviewMode;
use App\Domain\Interviews\Enums\InterviewStatus;
use App\Domain\Interviews\Enums\InterviewType;
use App\Domain\Interviews\Enums\Recommendation;
use App\Domain\Interviews\InterviewScheduler;
use App\Http\Controllers\Controller;
use App\Http\Resources\Interviews\InterviewResource;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\EvaluationCriterion;
use App\Models\Interview;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class InterviewController extends Controller
{
    /** Agenda: entrevistas visíveis ao usuário, filtráveis por período, situação, vaga e "minhas". */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Interview::class);
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::enum(InterviewStatus::class)],
            'mine' => ['nullable', 'boolean'],
            'pending_evaluation' => ['nullable', 'boolean'],
            'job' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['scheduled_at', '-scheduled_at'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $user = $request->user();
        $tz = config('app.timezone');

        $query = Interview::query()->visibleTo($user)
            ->with(['interviewers:id,uuid,name', 'application.candidate:id,uuid,full_name,social_name,desired_position', 'application.jobOpening:id,uuid,code,title'])
            ->withCount('evaluations')
            ->when($filters['from'] ?? null, fn (Builder $q, $d) => $q->where('scheduled_at', '>=', Carbon::parse($d, $tz)->startOfDay()))
            ->when($filters['to'] ?? null, fn (Builder $q, $d) => $q->where('scheduled_at', '<=', Carbon::parse($d, $tz)->endOfDay()))
            ->when($filters['status'] ?? null, fn (Builder $q, array $s) => $q->whereIn('status', $s))
            ->when(filter_var($filters['mine'] ?? false, FILTER_VALIDATE_BOOLEAN), fn (Builder $q) => $q->whereHas('interviewers', fn ($i) => $i->whereKey($user->id)))
            ->when(filter_var($filters['pending_evaluation'] ?? false, FILTER_VALIDATE_BOOLEAN), fn (Builder $q) => $q
                ->whereIn('status', [InterviewStatus::Scheduled->value, InterviewStatus::Completed->value])
                ->where('scheduled_at', '<=', now())
                ->whereHas('interviewers', fn ($i) => $i->whereKey($user->id))
                ->whereDoesntHave('evaluations', fn ($e) => $e->where('user_id', $user->id)))
            ->when($filters['job'] ?? null, fn (Builder $q, $uuid) => $q->whereHas('application.jobOpening', fn ($j) => $j->where('uuid', $uuid)))
            ->when($filters['search'] ?? null, fn (Builder $q, string $term) => $q->whereHas('application.candidate', fn ($c) => $c->whereRaw('unaccent(full_name) ILIKE unaccent(?)', ['%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%'])))
            ->orderBy('scheduled_at', ($filters['sort'] ?? 'scheduled_at') === '-scheduled_at' ? 'desc' : 'asc')
            ->orderBy('id');

        return InterviewResource::collection($query->paginate((int) ($filters['per_page'] ?? 20))->withQueryString());
    }

    public function options(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Interview::class);

        return response()->json(['data' => [
            'types' => InterviewType::options(),
            'modes' => InterviewMode::options(),
            'statuses' => InterviewStatus::options(),
            'recommendations' => Recommendation::options(),
            'criteria' => EvaluationCriterion::query()->where('is_active', true)->ordered()->get()->map->snapshot(),
            'interviewers' => $request->user()->can('interviews.schedule')
                ? InterviewScheduler::eligibleInterviewers()->get(['id', 'uuid', 'name', 'job_title'])->map(fn (User $u) => ['id' => $u->uuid, 'name' => $u->name, 'job_title' => $u->job_title])
                : [],
            'can_manage_criteria' => $request->user()->can('jobs.manage'),
        ]]);
    }

    /** Entrevistas de uma inscrição (painel do cartão no quadro). */
    public function forApplication(Application $application): AnonymousResourceCollection
    {
        $this->authorize('viewApplications', $application->jobOpening);

        return InterviewResource::collection($application->interviews()->with(['interviewers:id,uuid,name'])->withCount('evaluations')->get());
    }

    public function store(Request $request, Application $application, InterviewScheduler $scheduler): JsonResponse
    {
        $this->authorize('create', [Interview::class, $application]);
        $interview = $scheduler->schedule($application, $this->validated($request), $request->user());

        return (new InterviewResource($this->loadDetail($interview)))->response()->setStatusCode(201);
    }

    public function show(Interview $interview): InterviewResource
    {
        $this->authorize('view', $interview);

        return new InterviewResource($this->loadDetail($interview));
    }

    public function update(Request $request, Interview $interview, InterviewScheduler $scheduler): InterviewResource
    {
        $this->authorize('update', $interview);

        return new InterviewResource($this->loadDetail($scheduler->update($interview, $this->validated($request, true), $request->user())));
    }

    public function cancel(Request $request, Interview $interview, InterviewScheduler $scheduler): InterviewResource
    {
        $this->authorize('update', $interview);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']], [], ['reason' => 'motivo']);

        return new InterviewResource($this->loadDetail($scheduler->cancel($interview, trim($data['reason']), $request->user())));
    }

    public function noShow(Request $request, Interview $interview, InterviewScheduler $scheduler): InterviewResource
    {
        $this->authorize('update', $interview);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return new InterviewResource($this->loadDetail($scheduler->markNoShow($interview, $data['note'] ?? null, $request->user())));
    }

    /** Parecer do próprio entrevistador (cria ou corrige). */
    public function evaluate(Request $request, Interview $interview, SubmitEvaluation $submit): InterviewResource
    {
        $this->authorize('evaluate', $interview);
        $data = $request->validate([
            'recommendation' => ['required', Rule::enum(Recommendation::class)],
            'summary' => ['required', 'string', 'min:10', 'max:5000'],
            'observations' => ['nullable', 'string', 'max:5000'],
            'scores' => ['required', 'array', 'min:1', 'max:30'],
            'scores.*.criterion' => ['required', 'uuid', 'distinct'],
            'scores.*.score' => ['required', 'integer', 'min:1', 'max:5'],
            'scores.*.comment' => ['nullable', 'string', 'max:500'],
        ], [], ['recommendation' => 'recomendação', 'summary' => 'parecer', 'observations' => 'observações', 'scores' => 'notas', 'scores.*.score' => 'nota']);

        $submit->handle($interview, $data, $request->user());

        return new InterviewResource($this->loadDetail($interview->refresh()));
    }

    public function history(Interview $interview): JsonResponse
    {
        $this->authorize('view', $interview);

        $logs = $interview->auditLogs()->with('user:id,name')->latest('id')->limit(100)->get();

        return response()->json(['data' => $logs->map(fn (AuditLog $log) => [
            'id' => $log->id,
            'event' => $log->event,
            'event_label' => self::EVENT_LABELS[$log->event] ?? $log->event,
            'description' => $log->description,
            'reason' => $log->context['reason'] ?? null,
            'user' => $log->user?->name,
            'created_at' => $log->created_at?->toIso8601String(),
        ])]);
    }

    private const EVENT_LABELS = [
        'interview.scheduled' => 'Entrevista agendada',
        'interview.updated' => 'Entrevista alterada',
        'interview.cancelled' => 'Entrevista cancelada',
        'interview.no_show' => 'Candidato não compareceu',
        'interview.evaluated' => 'Parecer registrado',
        'interview.evaluation_updated' => 'Parecer corrigido',
    ];

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'type' => ['required', Rule::enum(InterviewType::class)],
            'mode' => ['required', Rule::enum(InterviewMode::class)],
            'scheduled_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:10', 'max:480'],
            'location' => ['nullable', 'required_if:mode,in_person', 'string', 'max:255'],
            'meeting_url' => ['nullable', 'url:https,http', 'max:500'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'interviewers' => ['required', 'array', 'min:1', 'max:10'],
            'interviewers.*' => ['uuid', 'distinct'],
            'criteria' => ['nullable', 'array', 'min:1', 'max:30'],
            'criteria.*' => ['uuid', 'distinct'],
            'force' => ['boolean'],
            'move_to_stage' => [$updating ? 'prohibited' : 'boolean'],
        ], [
            'location.required_if' => 'Informe o local da entrevista presencial.',
        ], [
            'type' => 'tipo', 'mode' => 'formato', 'scheduled_at' => 'data e horário', 'duration_minutes' => 'duração',
            'location' => 'local', 'meeting_url' => 'link da reunião', 'instructions' => 'orientações', 'interviewers' => 'entrevistadores', 'criteria' => 'critérios',
        ]);
    }

    private function loadDetail(Interview $interview): Interview
    {
        return $interview->load(['interviewers:id,uuid,name', 'application.candidate', 'application.jobOpening', 'scheduler:id,name'])->loadCount('evaluations');
    }
}

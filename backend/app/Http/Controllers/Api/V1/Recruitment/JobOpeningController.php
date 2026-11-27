<?php

namespace App\Http\Controllers\Api\V1\Recruitment;

use App\Domain\Candidates\Enums\EducationLevel;
use App\Domain\Candidates\Enums\ProfessionalArea;
use App\Domain\Recruitment\Actions\SaveJobOpening;
use App\Domain\Recruitment\Enums\ApplicationStage;
use App\Domain\Recruitment\Enums\EmploymentType;
use App\Domain\Recruitment\Enums\JobReason;
use App\Domain\Recruitment\Enums\JobStatus;
use App\Domain\Recruitment\Enums\RejectionReason;
use App\Domain\Recruitment\JobWorkflow;
use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\JobOpeningRequest;
use App\Http\Resources\Recruitment\JobOpeningResource;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\JobOpening;
use App\Models\Position;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class JobOpeningController extends Controller
{
    public const SORTABLE = ['code', 'title', 'created_at', 'closes_on', 'status'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', JobOpening::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::enum(JobStatus::class)],
            'department_id' => ['nullable', 'uuid'],
            'unit_id' => ['nullable', 'uuid'],
            'professional_area' => ['nullable', Rule::enum(ProfessionalArea::class)],
            'mine' => ['nullable', 'boolean'],
            'pending_my_approval' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(array_merge(self::SORTABLE, array_map(fn ($s) => '-'.$s, self::SORTABLE)))],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $user = $request->user();
        $sort = $filters['sort'] ?? '-created_at';

        $query = JobOpening::query()->visibleTo($user)
            ->with(['department:id,uuid,name', 'unit:id,uuid,name', 'requester:id,uuid,name'])
            ->withCount([
                'applications',
                'applications as active_applications_count' => fn (Builder $q) => $q->whereNotIn('stage', [ApplicationStage::Hired->value, ApplicationStage::Rejected->value, ApplicationStage::Withdrawn->value]),
                'applications as hired_count' => fn (Builder $q) => $q->where('stage', ApplicationStage::Hired->value),
            ])
            ->when($filters['search'] ?? null, function (Builder $q, string $term) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';
                $q->where(fn (Builder $w) => $w->whereRaw('unaccent(title) ILIKE unaccent(?)', [$like])->orWhere('code', 'ILIKE', $like));
            })
            ->when($filters['status'] ?? null, fn (Builder $q, array $s) => $q->whereIn('status', $s))
            ->when($filters['department_id'] ?? null, fn (Builder $q, $uuid) => $q->whereHas('department', fn ($d) => $d->where('uuid', $uuid)))
            ->when($filters['unit_id'] ?? null, fn (Builder $q, $uuid) => $q->whereHas('unit', fn ($u) => $u->where('uuid', $uuid)))
            ->when($filters['professional_area'] ?? null, fn (Builder $q, $a) => $q->where('professional_area', $a))
            ->when(filter_var($filters['mine'] ?? false, FILTER_VALIDATE_BOOLEAN), fn (Builder $q) => $q->where('requested_by', $user->id))
            ->when(filter_var($filters['pending_my_approval'] ?? false, FILTER_VALIDATE_BOOLEAN), function (Builder $q) use ($user) {
                $q->where('status', JobStatus::PendingApproval->value);
                if (! $user->can('jobs.manage')) {
                    $q->where('department_id', $user->department_id ?? 0)->where(fn (Builder $w) => $w->whereNull('requested_by')->orWhere('requested_by', '!=', $user->id));
                }
            })
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderByDesc('id');

        return JobOpeningResource::collection($query->paginate((int) ($filters['per_page'] ?? 15))->withQueryString());
    }

    public function options(Request $request): JsonResponse
    {
        $this->authorize('viewAny', JobOpening::class);
        $user = $request->user();
        $manages = JobOpening::seesAllDepartments($user);

        return response()->json(['data' => [
            'statuses' => JobStatus::options(),
            'employment_types' => EmploymentType::options(),
            'reasons' => JobReason::options(),
            'professional_areas' => ProfessionalArea::options(),
            'education_levels' => EducationLevel::options(),
            'stages' => ApplicationStage::options(),
            'pipeline' => array_map(fn (ApplicationStage $s) => $s->value, ApplicationStage::pipeline()),
            'rejection_reasons' => RejectionReason::options(),
            'departments' => Department::query()->where('is_active', true)->when(! $manages, fn ($q) => $q->whereKey($user->department_id ?? 0))->orderBy('name')->get(['uuid', 'name'])->map(fn ($d) => ['id' => $d->uuid, 'name' => $d->name]),
            'units' => Unit::query()->where('is_active', true)->orderBy('name')->get(['uuid', 'name'])->map(fn ($u) => ['id' => $u->uuid, 'name' => $u->name]),
            'positions' => Position::query()->where('is_active', true)->orderBy('title')->get(['uuid', 'title'])->map(fn ($p) => ['id' => $p->uuid, 'name' => $p->title]),
            'can_create' => $user->can('create', JobOpening::class),
            'can_choose_department' => $manages,
            'my_department_id' => $user->department?->uuid,
        ]]);
    }

    public function store(JobOpeningRequest $request, SaveJobOpening $save): JsonResponse
    {
        $job = $save->handle(new JobOpening, $request->payload(), $request->user());

        return (new JobOpeningResource($this->loadDetail($job)))->response()->setStatusCode(201);
    }

    public function show(JobOpening $job): JobOpeningResource
    {
        $this->authorize('view', $job);

        return new JobOpeningResource($this->loadDetail($job));
    }

    public function update(JobOpeningRequest $request, JobOpening $job, SaveJobOpening $save): JobOpeningResource
    {
        return new JobOpeningResource($this->loadDetail($save->handle($job, $request->payload(), $request->user())));
    }

    /** Executa uma ação do ciclo de vida: enviar, aprovar, reprovar, publicar, suspender, encerrar, reabrir ou cancelar. */
    public function transition(Request $request, JobOpening $job, JobWorkflow $workflow): JobOpeningResource
    {
        $this->authorize('view', $job);
        $data = $request->validate([
            'action' => ['required', Rule::in(array_keys(JobWorkflow::ACTIONS))],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [], ['reason' => 'motivo']);

        return new JobOpeningResource($this->loadDetail($workflow->apply($job, $data['action'], $request->user(), $data['reason'] ?? null)));
    }

    /** Histórico da vaga: situações e alterações, do mais recente para o mais antigo. */
    public function history(Request $request, JobOpening $job): JsonResponse
    {
        $this->authorize('view', $job);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        $page = $job->auditLogs()->with('user:id,name')->latest('id')->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'event' => $log->event,
                'event_label' => self::EVENT_LABELS[$log->event] ?? $log->event,
                'description' => $log->description,
                'reason' => $log->context['reason'] ?? null,
                'user' => $log->user?->name,
                'created_at' => $log->created_at?->toIso8601String(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
        ]);
    }

    private const EVENT_LABELS = [
        'job.created' => 'Vaga criada',
        'job.updated' => 'Vaga alterada',
        'job.submit' => 'Enviada para aprovação',
        'job.approve' => 'Aprovada',
        'job.reject' => 'Devolvida sem aprovação',
        'job.publish' => 'Publicada',
        'job.suspend' => 'Suspensa',
        'job.close' => 'Encerrada',
        'job.reopen' => 'Reaberta',
        'job.cancel' => 'Cancelada',
    ];

    private function loadDetail(JobOpening $job): JobOpening
    {
        return $job->load(['department:id,uuid,name', 'unit:id,uuid,name', 'position:id,uuid,title', 'requester:id,uuid,name', 'approver:id,uuid,name', 'requirements'])
            ->loadCount('applications');
    }
}

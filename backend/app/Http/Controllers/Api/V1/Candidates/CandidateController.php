<?php

namespace App\Http\Controllers\Api\V1\Candidates;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidates\Actions\SaveCandidate;
use App\Domain\Candidates\Enums\Availability;
use App\Domain\Candidates\Enums\CandidateSource;
use App\Domain\Candidates\Enums\CandidateStatus;
use App\Domain\Candidates\Enums\CourseType;
use App\Domain\Candidates\Enums\DocumentType;
use App\Domain\Candidates\Enums\EducationLevel;
use App\Domain\Candidates\Enums\EducationStatus;
use App\Domain\Candidates\Enums\ProfessionalArea;
use App\Domain\Candidates\Enums\SkillLevel;
use App\Domain\Candidates\Enums\WorkShift;
use App\Http\Controllers\Controller;
use App\Http\Requests\Candidates\CandidateRequest;
use App\Http\Requests\Organization\UnitRequest;
use App\Http\Resources\Candidates\CandidateListResource;
use App\Http\Resources\Candidates\CandidateResource;
use App\Models\AuditLog;
use App\Models\Candidate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class CandidateController extends Controller
{
    private const SORTABLE = ['full_name', 'created_at', 'updated_at', 'city'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Candidate::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(CandidateStatus::class)],
            'professional_area' => ['nullable', Rule::enum(ProfessionalArea::class)],
            'education_level' => ['nullable', Rule::enum(EducationLevel::class)],
            'source' => ['nullable', Rule::enum(CandidateSource::class)],
            'availability' => ['nullable', Rule::enum(Availability::class)],
            'state' => ['nullable', Rule::in(UnitRequest::STATES)],
            'city' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', 'string', 'max:30'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = Candidate::query()->visibleTo($request->user())->withCount('documents');

        if ($search = $filters['search'] ?? null) {
            $term = '%'.addcslashes($search, '%_\\').'%';
            $digits = preg_replace('/\D/', '', $search);
            $query->where(function (Builder $q) use ($term, $digits) {
                $q->where('full_name', 'ilike', $term)
                    ->orWhere('social_name', 'ilike', $term)
                    ->orWhere('email', 'ilike', $term)
                    ->orWhere('desired_position', 'ilike', $term);
                if (strlen($digits) >= 4) {
                    $q->orWhere('phone', 'like', '%'.$digits.'%')->orWhere('phone_secondary', 'like', '%'.$digits.'%');
                }
            });
        }

        foreach (['status', 'professional_area', 'education_level', 'source', 'availability', 'state'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (! empty($filters['city'])) {
            $query->where('city', 'ilike', addcslashes($filters['city'], '%_\\'));
        }

        $sort = $filters['sort'] ?? '-created_at';
        $column = ltrim($sort, '-');
        $query->orderBy(in_array($column, self::SORTABLE, true) ? $column : 'created_at', str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('id');

        return CandidateListResource::collection($query->paginate((int) ($filters['per_page'] ?? 15))->withQueryString());
    }

    /** Listas de opções usadas pelos formulários e filtros. */
    public function options(): JsonResponse
    {
        $this->authorize('viewAny', Candidate::class);

        return response()->json(['data' => [
            'statuses' => CandidateStatus::options(),
            'sources' => CandidateSource::options(),
            'professional_areas' => ProfessionalArea::options(),
            'education_levels' => EducationLevel::options(),
            'education_statuses' => EducationStatus::options(),
            'availabilities' => Availability::options(),
            'work_shifts' => WorkShift::options(),
            'course_types' => CourseType::options(),
            'skill_levels' => SkillLevel::options(),
            'document_types' => DocumentType::options(),
            'states' => UnitRequest::STATES,
        ]]);
    }

    public function store(CandidateRequest $request, SaveCandidate $save): JsonResponse
    {
        $candidate = $save->handle(new Candidate, $request->validated(), $request->user());

        return (new CandidateResource($this->loadDetail($candidate)))->response()->setStatusCode(201);
    }

    public function show(Request $request, Candidate $candidate, AuditLogger $audit): CandidateResource
    {
        $this->authorize('view', $candidate);

        // LGPD: registra quem acessou os dados pessoais do candidato.
        $audit->log('candidate.viewed', $candidate);

        return new CandidateResource($this->loadDetail($candidate));
    }

    public function update(CandidateRequest $request, Candidate $candidate, SaveCandidate $save): CandidateResource
    {
        return new CandidateResource($this->loadDetail($save->handle($candidate, $request->validated(), $request->user())));
    }

    public function updateStatus(Request $request, Candidate $candidate, AuditLogger $audit): CandidateResource
    {
        $this->authorize('update', $candidate);
        $data = $request->validate(['status' => ['required', Rule::enum(CandidateStatus::class)]]);

        $before = $candidate->status;
        $candidate->status = CandidateStatus::from($data['status']);
        $candidate->updated_by = $request->user()->id;
        $candidate->save();

        if ($before !== $candidate->status) {
            $audit->log('candidate.status_changed', $candidate, 'Situação alterada para '.$candidate->status->label().'.', ['status' => $before?->value], ['status' => $candidate->status->value]);
        }

        return new CandidateResource($this->loadDetail($candidate));
    }

    public function destroy(Candidate $candidate, AuditLogger $audit): Response
    {
        $this->authorize('delete', $candidate);

        $candidate->delete();
        $audit->log('candidate.deleted', $candidate, 'Cadastro excluído.');

        return response()->noContent();
    }

    /** Histórico do candidato (trilha de auditoria), do mais recente para o mais antigo. */
    public function history(Request $request, Candidate $candidate): JsonResponse
    {
        $this->authorize('view', $candidate);
        $filters = $request->validate(['include_views' => ['sometimes', 'boolean'], 'page' => ['sometimes', 'integer', 'min:1']]);

        $page = $candidate->auditLogs()
            ->with('user:id,name')
            ->when(! filter_var($filters['include_views'] ?? false, FILTER_VALIDATE_BOOLEAN), fn ($q) => $q->where('event', '!=', 'candidate.viewed'))
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'event' => $log->event,
                'event_label' => self::EVENT_LABELS[$log->event] ?? $log->event,
                'description' => $log->description,
                'user' => $log->user?->name,
                'created_at' => $log->created_at?->toIso8601String(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
        ]);
    }

    private const EVENT_LABELS = [
        'candidate.created' => 'Cadastro criado',
        'candidate.updated' => 'Cadastro alterado',
        'candidate.status_changed' => 'Situação alterada',
        'candidate.viewed' => 'Ficha visualizada',
        'candidate.deleted' => 'Cadastro excluído',
        'candidate.document_uploaded' => 'Documento anexado',
        'candidate.document_downloaded' => 'Documento baixado',
        'candidate.document_deleted' => 'Documento removido',
        'candidate.note_added' => 'Anotação registrada',
    ];

    private function loadDetail(Candidate $candidate): Candidate
    {
        return $candidate->load(['educations', 'experiences', 'courses', 'skills', 'documents.uploader', 'notes.author', 'creator', 'updater']);
    }
}

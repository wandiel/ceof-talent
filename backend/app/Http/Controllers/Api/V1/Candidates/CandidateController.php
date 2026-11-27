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
use App\Domain\Candidates\Search\CandidateSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Candidates\CandidateRequest;
use App\Http\Requests\Organization\UnitRequest;
use App\Http\Resources\Candidates\CandidateListResource;
use App\Http\Resources\Candidates\CandidateResource;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class CandidateController extends Controller
{
    public function index(Request $request, CandidateSearch $search): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Candidate::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'keywords' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', Rule::enum(CandidateStatus::class)],
            'professional_area' => ['nullable', Rule::enum(ProfessionalArea::class)],
            'education_level' => ['nullable', Rule::enum(EducationLevel::class)],
            'education_level_min' => ['nullable', Rule::enum(EducationLevel::class)],
            'education_course' => ['nullable', 'string', 'max:100'],
            'experience_title' => ['nullable', 'string', 'max:100'],
            'experience_company' => ['nullable', 'string', 'max:100'],
            'min_experience_years' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'skills' => ['nullable', 'array', 'max:10'],
            'skills.*' => ['string', 'max:80'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['uuid'],
            'source' => ['nullable', Rule::enum(CandidateSource::class)],
            'availability' => ['nullable', Rule::enum(Availability::class)],
            'work_shift' => ['nullable', Rule::enum(WorkShift::class)],
            'state' => ['nullable', Rule::in(UnitRequest::STATES)],
            'city' => ['nullable', 'string', 'max:120'],
            'salary_max' => ['nullable', 'numeric', 'min:0'],
            'age_min' => ['nullable', 'integer', 'min:14', 'max:100'],
            'age_max' => ['nullable', 'integer', 'min:14', 'max:100', Rule::when($request->filled('age_min'), 'gte:age_min')],
            'willing_to_relocate' => ['nullable', 'boolean'],
            'has_resume' => ['nullable', 'boolean'],
            'favorites' => ['nullable', 'boolean'],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date', 'after_or_equal:created_from'],
            'sort' => ['nullable', 'string', Rule::in(array_merge(CandidateSearch::SORTABLE, array_map(fn ($s) => '-'.$s, CandidateSearch::SORTABLE)))],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ], [], ['age_max' => 'idade máxima', 'age_min' => 'idade mínima', 'created_to' => 'cadastrado até', 'created_from' => 'cadastrado desde']);

        $user = $request->user();
        $query = Candidate::query()->visibleTo($user)
            ->withCount('documents')
            ->with(['tags', 'skills'])
            ->withExists(['favoritedBy as is_favorite' => fn ($q) => $q->whereKey($user->id)]);

        $search->apply($query, $filters, $user);

        return CandidateListResource::collection($query->paginate((int) ($filters['per_page'] ?? 15))->withQueryString())
            ->additional(['meta' => ['highlight_skills' => array_values($filters['skills'] ?? [])]]);
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
            'tags' => Tag::query()->orderBy('name')->get()->map(fn (Tag $t) => ['id' => $t->uuid, 'name' => $t->name, 'color' => $t->color]),
            'tag_colors' => Tag::COLORS,
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
        'candidate.tags_changed' => 'Etiquetas alteradas',
        'candidate.duplicate_dismissed' => 'Duplicidade descartada',
        'application.created' => 'Inscrito em vaga',
        'application.moved' => 'Etapa do processo alterada',
        'interview.scheduled' => 'Entrevista agendada',
        'interview.rescheduled' => 'Entrevista remarcada',
        'interview.cancelled' => 'Entrevista cancelada',
        'interview.no_show' => 'Não compareceu à entrevista',
        'interview.evaluated' => 'Parecer de entrevista',
        'candidate.suggestion_reviewed' => 'Sugestão automática revisada',
        'candidate.document_read' => 'Currículo lido automaticamente',
    ];

    private function loadDetail(Candidate $candidate): Candidate
    {
        $candidate->load(['educations', 'experiences', 'courses', 'skills', 'tags', 'documents.uploader', 'notes.author', 'creator', 'updater']);
        $candidate->setAttribute('is_favorite', $candidate->favoritedBy()->whereKey(request()->user()?->id)->exists());

        return $candidate;
    }
}

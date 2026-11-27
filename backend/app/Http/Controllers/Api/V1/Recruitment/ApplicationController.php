<?php

namespace App\Http\Controllers\Api\V1\Recruitment;

use App\Domain\Recruitment\Actions\AddCandidatesToJob;
use App\Domain\Recruitment\Actions\MoveApplication;
use App\Domain\Recruitment\Enums\ApplicationStage;
use App\Domain\Recruitment\Enums\RejectionReason;
use App\Http\Controllers\Controller;
use App\Http\Resources\Recruitment\ApplicationResource;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\JobOpening;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ApplicationController extends Controller
{
    /** Quadro do processo seletivo: todas as inscrições da vaga e a contagem por etapa. */
    public function index(Request $request, JobOpening $job): AnonymousResourceCollection
    {
        $this->authorize('viewApplications', $job);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100']]);

        $applications = $job->applications()
            ->whereHas('candidate', fn ($q) => $q->withoutTrashed()->when($filters['search'] ?? null, function ($w, string $term) {
                $w->whereRaw('unaccent(full_name) ILIKE unaccent(?)', ['%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%']);
            }))
            ->with('candidate')
            ->orderByDesc('stage_changed_at')
            ->orderByDesc('id')
            ->get();

        $counts = $applications->countBy(fn (Application $a) => $a->stage->value);

        return ApplicationResource::collection($applications)->additional(['meta' => [
            'counts' => collect(ApplicationStage::cases())->mapWithKeys(fn ($s) => [$s->value => $counts[$s->value] ?? 0]),
            'positions_count' => $job->positions_count,
        ]]);
    }

    public function store(Request $request, JobOpening $job, AddCandidatesToJob $add): JsonResponse
    {
        $this->authorize('addApplications', $job);
        $data = $request->validate([
            'candidates' => ['required', 'array', 'min:1', 'max:50'],
            'candidates.*' => ['uuid', 'distinct'],
        ], [], ['candidates' => 'candidatos']);

        $result = $add->handle($job, $data['candidates'], $request->user());

        return response()->json([
            'data' => ApplicationResource::collection(collect($result['added'])->each->load('candidate')),
            'meta' => ['added' => count($result['added']), 'already' => $result['already'], 'inactive' => $result['inactive']],
        ], count($result['added']) ? 201 : 200);
    }

    public function show(Application $application): ApplicationResource
    {
        $this->authorize('viewApplications', $application->jobOpening);

        return new ApplicationResource($application->load(['candidate', 'jobOpening.department', 'creator:id,name', 'history.user:id,name']));
    }

    public function move(Request $request, Application $application, MoveApplication $move): JsonResponse
    {
        $this->authorize('viewApplications', $application->jobOpening);
        $data = $request->validate([
            'stage' => ['required', Rule::enum(ApplicationStage::class)],
            'reason' => ['nullable', 'required_if:stage,rejected', Rule::enum(RejectionReason::class)],
            'note' => ['nullable', 'string', 'max:2000'],
        ], ['reason.required_if' => 'Informe o motivo da reprovação.'], ['stage' => 'etapa', 'reason' => 'motivo', 'note' => 'observação']);

        $result = $move->handle(
            $application,
            ApplicationStage::from($data['stage']),
            $request->user(),
            isset($data['reason']) ? RejectionReason::from($data['reason']) : null,
            $data['note'] ?? null,
        );

        return response()->json([
            'data' => new ApplicationResource($result['application']->load(['candidate', 'jobOpening.department', 'creator:id,name', 'history.user:id,name'])),
            'meta' => ['job_closed' => $result['job_closed']],
        ]);
    }

    /** Processos seletivos de que o candidato participa (aba "Processos" da ficha). */
    public function forCandidate(Request $request, Candidate $candidate): AnonymousResourceCollection
    {
        $this->authorize('view', $candidate);
        $user = $request->user();
        abort_unless($user->can('recruitment.view'), 403);

        $applications = $candidate->applications()
            ->whereHas('jobOpening', fn ($q) => $q->visibleTo($user))
            ->with(['jobOpening.department:id,name', 'history.user:id,name'])
            ->latest('id')
            ->get();

        return ApplicationResource::collection($applications);
    }
}

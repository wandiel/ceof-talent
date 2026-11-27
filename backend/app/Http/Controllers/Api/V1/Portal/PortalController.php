<?php

namespace App\Http\Controllers\Api\V1\Portal;

use App\Domain\Candidates\Documents\UploadInspector;
use App\Domain\Candidates\Enums\EducationLevel;
use App\Domain\Candidates\Enums\ProfessionalArea;
use App\Domain\Portal\Actions\SubmitPortalApplication;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\UnitRequest;
use App\Http\Requests\Portal\PortalApplicationRequest;
use App\Http\Resources\Portal\PublicJobResource;
use App\Models\JobOpening;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Portal "Trabalhe conosco": rotas públicas, sem login. */
class PortalController extends Controller
{
    public function jobs(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'area' => ['nullable', 'string', 'max:40'],
        ]);

        $jobs = JobOpening::query()->publiclyOpen()->with('department:id,name', 'unit:id,name,city,state')
            ->when($data['search'] ?? null, fn (Builder $q, string $s) => $q->where(fn ($w) => $w->whereRaw('unaccent(title) ILIKE unaccent(?)', ['%'.addcslashes($s, '%_\\').'%'])->orWhere('code', 'ILIKE', '%'.addcslashes($s, '%_\\').'%')))
            ->when($data['area'] ?? null, fn (Builder $q, string $a) => $q->where('professional_area', $a))
            ->orderByDesc('published_at')->limit(100)->get();

        return PublicJobResource::collection($jobs);
    }

    public function job(string $job): PublicJobResource
    {
        $model = JobOpening::query()->publiclyOpen()->where('uuid', $job)->with('department:id,name', 'unit:id,name,city,state', 'requirements')->first();
        abort_unless($model !== null, 404, 'Esta vaga não está mais disponível.');

        return new PublicJobResource($model);
    }

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'areas' => ProfessionalArea::options(),
            'education_levels' => EducationLevel::options(),
            'states' => UnitRequest::STATES,
            'max_file_mb' => (int) floor(UploadInspector::MAX_KILOBYTES / 1024),
            'max_extra_documents' => PortalApplicationRequest::MAX_EXTRA_DOCUMENTS,
            'privacy' => $this->privacyData(),
        ]]);
    }

    public function privacy(): JsonResponse
    {
        return response()->json(['data' => $this->privacyData()]);
    }

    public function submit(PortalApplicationRequest $request, SubmitPortalApplication $submit): JsonResponse
    {
        $data = $request->safe()->except(['resume', 'documents']);
        $submission = $submit->handle($data, $request->file('resume'), array_values($request->file('documents', [])), $request->ip(), $request->userAgent());
        $job = $submission->jobOpening;

        // Mesma resposta para cadastro novo ou existente (não revela se o e-mail já existia).
        return response()->json(['data' => [
            'protocol' => $submission->protocol,
            'received_at' => $submission->created_at->toIso8601String(),
            'job' => $job ? ['code' => $job->code, 'title' => $job->title] : null,
            'email' => $data['email'],
        ]], 201);
    }

    /** @return array<string, mixed> */
    private function privacyData(): array
    {
        return [
            'version' => config('privacy.notice_version'),
            'updated_at' => config('privacy.notice_updated_at'),
            'controller' => config('privacy.controller'),
            'dpo_name' => config('privacy.dpo_name'),
            'dpo_email' => config('privacy.dpo_email'),
            'talent_pool_retention_months' => config('privacy.talent_pool_retention_months'),
        ];
    }
}

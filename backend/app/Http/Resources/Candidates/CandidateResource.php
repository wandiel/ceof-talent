<?php

namespace App\Http\Resources\Candidates;

use App\Domain\Candidates\Enums\WorkShift;
use App\Domain\Candidates\Support\Cpf;
use App\Domain\Identity\Enums\Permission;
use App\Models\Candidate;
use App\Models\CandidateCourse;
use App\Models\CandidateEducation;
use App\Models\CandidateExperience;
use App\Models\Skill;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ficha completa do candidato.
 *
 * O CPF completo só é enviado a quem pode editar o cadastro; os demais veem a versão mascarada.
 *
 * @mixin Candidate
 */
class CandidateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canManage = $user?->can(Permission::CandidatesManage->value) ?? false;

        return [
            ...(new CandidateListResource($this->resource))->toArray($request),
            'phone_secondary' => $this->phone_secondary,
            'cpf' => $canManage ? Cpf::format($this->cpf) : null,
            'cpf_masked' => Cpf::mask($this->cpf),
            'birth_date' => $this->birth_date?->toDateString(),
            'age' => $this->birth_date?->age,
            'linkedin_url' => $this->linkedin_url,
            'portfolio_url' => $this->portfolio_url,
            'zip_code' => $this->zip_code,
            'neighborhood' => $this->neighborhood,
            'summary' => $this->summary,
            'salary_expectation' => $this->salary_expectation,
            'availability' => $this->availability?->value,
            'availability_label' => $this->availability?->label(),
            'work_shifts' => $this->work_shifts ?? [],
            'work_shifts_labels' => array_values(array_filter(array_map(fn ($s) => WorkShift::labelFor($s), $this->work_shifts ?? []))),
            'willing_to_relocate' => $this->willing_to_relocate,
            'source_detail' => $this->source_detail,
            'educations' => $this->whenLoaded('educations', fn () => $this->educations->map(fn (CandidateEducation $e) => [
                'level' => $e->level->value,
                'level_label' => $e->level->label(),
                'institution' => $e->institution,
                'course' => $e->course,
                'status' => $e->status->value,
                'status_label' => $e->status->label(),
                'start_year' => $e->start_year,
                'end_year' => $e->end_year,
            ])),
            'experiences' => $this->whenLoaded('experiences', fn () => $this->experiences->map(fn (CandidateExperience $x) => [
                'company' => $x->company,
                'job_title' => $x->job_title,
                'start_date' => $x->start_date->format('Y-m'),
                'end_date' => $x->end_date?->format('Y-m'),
                'is_current' => $x->is_current,
                'description' => $x->description,
            ])),
            'courses' => $this->whenLoaded('courses', fn () => $this->courses->map(fn (CandidateCourse $c) => [
                'type' => $c->type->value,
                'type_label' => $c->type->label(),
                'name' => $c->name,
                'institution' => $c->institution,
                'year' => $c->year,
                'workload_hours' => $c->workload_hours,
                'credential' => $c->credential,
            ])),
            'skills' => $this->whenLoaded('skills', fn () => $this->skills->map(fn (Skill $s) => [
                'name' => $s->name,
                'level' => $s->pivot->level,
            ])),
            'documents' => CandidateDocumentResource::collection($this->whenLoaded('documents')),
            'notes' => CandidateNoteResource::collection($this->whenLoaded('notes')),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'updated_by' => $this->whenLoaded('updater', fn () => $this->updater?->name),
            'abilities' => [
                'update' => $user?->can('update', $this->resource) ?? false,
                'delete' => $user?->can('delete', $this->resource) ?? false,
                'download_documents' => $user?->can('downloadDocuments', $this->resource) ?? false,
                'manage_talent_pool' => $user?->can('manageTalentPool', $this->resource) ?? false,
            ],
        ];
    }
}

<?php

namespace App\Http\Resources\Recruitment;

use App\Domain\Recruitment\JobWorkflow;
use App\Models\JobOpening;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vaga. Na listagem vem resumida; no detalhe (show/store/update/transition)
 * traz descrição, requisitos e as ações disponíveis para o usuário.
 *
 * @mixin JobOpening
 */
class JobOpeningResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $detail = ! $request->routeIs('api.v1.jobs.index');
        $user = $request->user();

        return [
            'id' => $this->uuid,
            'code' => $this->code,
            'title' => $this->title,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_reason' => $this->status_reason,
            'department' => $this->whenLoaded('department', fn () => ['id' => $this->department->uuid, 'name' => $this->department->name]),
            'unit' => $this->whenLoaded('unit', fn () => $this->unit ? ['id' => $this->unit->uuid, 'name' => $this->unit->name] : null),
            'position' => $this->whenLoaded('position', fn () => $this->position ? ['id' => $this->position->uuid, 'name' => $this->position->title] : null),
            'professional_area' => $this->professional_area?->value,
            'professional_area_label' => $this->professional_area?->label(),
            'employment_type' => $this->employment_type?->value,
            'employment_type_label' => $this->employment_type?->label(),
            'positions_count' => $this->positions_count,
            'applications_count' => $this->whenCounted('applications'),
            'active_applications_count' => $this->when(array_key_exists('active_applications_count', $this->getAttributes()), fn () => (int) $this->getAttribute('active_applications_count')),
            'hired_count' => $this->when(array_key_exists('hired_count', $this->getAttributes()), fn () => (int) $this->getAttribute('hired_count')),
            'is_public' => $this->is_public,
            'closes_on' => $this->closes_on?->toDateString(),
            'requester' => $this->whenLoaded('requester', fn () => $this->requester ? ['id' => $this->requester->uuid, 'name' => $this->requester->name] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),

            $this->mergeWhen($detail, fn () => [
                'reason' => $this->reason?->value,
                'reason_label' => $this->reason?->label(),
                'replaced_employee' => $this->replaced_employee,
                'work_schedule' => $this->work_schedule,
                'salary_min' => $this->salary_min,
                'salary_max' => $this->salary_max,
                'show_salary' => $this->show_salary,
                'education_level_min' => $this->education_level_min?->value,
                'education_level_min_label' => $this->education_level_min?->label(),
                'description' => $this->description,
                'responsibilities' => $this->responsibilities,
                'benefits' => $this->benefits,
                'requirements' => $this->whenLoaded('requirements', fn () => $this->requirements->map(fn ($r) => ['description' => $r->description, 'is_mandatory' => $r->is_mandatory])),
                'approver' => $this->whenLoaded('approver', fn () => $this->approver ? ['id' => $this->approver->uuid, 'name' => $this->approver->name] : null),
                'submitted_at' => $this->submitted_at?->toIso8601String(),
                'approved_at' => $this->approved_at?->toIso8601String(),
                'hired_total' => $this->hiredCount(),
                'abilities' => $user ? [
                    'update' => $user->can('update', $this->resource),
                    'view_applications' => $user->can('viewApplications', $this->resource),
                    'add_applications' => $user->can('addApplications', $this->resource),
                    'move' => $user->can('recruitment.move') && $this->status->acceptsApplications(),
                    'decide' => $user->can('recruitment.decide') && $this->status->acceptsApplications(),
                    'actions' => collect(app(JobWorkflow::class)->allowedActions($this->resource, $user))
                        ->map(fn (string $a) => ['action' => $a, 'label' => JobWorkflow::LABELS[$a], 'needs_reason' => JobWorkflow::ACTIONS[$a][2]])
                        ->values(),
                ] : null,
            ]),
        ];
    }
}

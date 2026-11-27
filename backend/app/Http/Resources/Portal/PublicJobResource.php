<?php

namespace App\Http\Resources\Portal;

use App\Models\JobOpening;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vaga como aparece no portal público: só o que pode ser divulgado
 * (sem solicitante, motivo, colaborador substituído ou faixa salarial oculta).
 *
 * @mixin JobOpening
 */
class PublicJobResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $detail = $request->route('job') !== null;

        return [
            'id' => $this->uuid,
            'code' => $this->code,
            'title' => $this->title,
            'department' => $this->department?->name,
            'unit' => $this->unit ? ['name' => $this->unit->name, 'city' => $this->unit->city, 'state' => $this->unit->state] : null,
            'professional_area' => $this->professional_area->value,
            'professional_area_label' => $this->professional_area->label(),
            'employment_type_label' => $this->employment_type->label(),
            'positions_count' => $this->positions_count,
            'work_schedule' => $this->work_schedule,
            'salary' => $this->show_salary ? ['min' => $this->salary_min, 'max' => $this->salary_max] : null,
            'education_level_min_label' => $this->education_level_min?->label(),
            'closes_on' => $this->closes_on?->toDateString(),
            'published_at' => $this->published_at?->toIso8601String(),
            'summary' => str($this->description)->limit(220)->toString(),
            $this->mergeWhen($detail, fn () => [
                'description' => $this->description,
                'responsibilities' => $this->responsibilities,
                'benefits' => $this->benefits,
                'requirements' => $this->requirements->map(fn ($r) => ['description' => $r->description, 'is_mandatory' => $r->is_mandatory])->values(),
            ]),
        ];
    }
}

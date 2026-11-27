<?php

namespace App\Http\Resources\Candidates;

use App\Models\Candidate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Linha da listagem de candidatos (sem CPF nem dados detalhados).
 *
 * @mixin Candidate
 */
class CandidateListResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'full_name' => $this->full_name,
            'social_name' => $this->social_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'city' => $this->city,
            'state' => $this->state,
            'professional_area' => $this->professional_area?->value,
            'professional_area_label' => $this->professional_area?->label(),
            'education_level' => $this->education_level?->value,
            'education_level_label' => $this->education_level?->label(),
            'desired_position' => $this->desired_position,
            'source' => $this->source?->value,
            'source_label' => $this->source?->label(),
            'status' => $this->status?->value,
            'documents_count' => $this->whenCounted('documents'),
            'experience_months' => $this->when(array_key_exists('experience_months', $this->getAttributes()), fn () => (int) $this->getAttribute('experience_months')),
            'is_favorite' => $this->when(array_key_exists('is_favorite', $this->getAttributes()), fn () => (bool) $this->getAttribute('is_favorite')),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn ($t) => ['id' => $t->uuid, 'name' => $t->name, 'color' => $t->color])),
            'skills_preview' => $this->when($this->relationLoaded('skills') && ! $request->routeIs('api.v1.candidates.show', 'api.v1.candidates.store', 'api.v1.candidates.update', 'api.v1.candidates.status'), fn () => $this->skills->pluck('name')->take(8)->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

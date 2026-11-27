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
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

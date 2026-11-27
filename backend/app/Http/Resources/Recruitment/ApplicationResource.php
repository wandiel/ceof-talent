<?php

namespace App\Http\Resources\Recruitment;

use App\Models\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Application */
class ApplicationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'stage' => $this->stage->value,
            'stage_label' => $this->stage->label(),
            'previous_stage' => $this->previous_stage?->value,
            'rejection_reason' => $this->rejection_reason?->value,
            'rejection_reason_label' => $this->rejection_reason?->label(),
            'source' => $this->source,
            'stage_changed_at' => $this->stage_changed_at?->toIso8601String(),
            'hired_at' => $this->hired_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'candidate' => $this->whenLoaded('candidate', fn () => [
                'id' => $this->candidate->uuid,
                'full_name' => $this->candidate->full_name,
                'social_name' => $this->candidate->social_name,
                'desired_position' => $this->candidate->desired_position,
                'city' => $this->candidate->city,
                'state' => $this->candidate->state,
                'email' => $this->candidate->email,
                'phone' => $this->candidate->phone,
                'deleted' => $this->candidate->trashed(),
            ]),
            'job' => $this->whenLoaded('jobOpening', fn () => [
                'id' => $this->jobOpening->uuid,
                'code' => $this->jobOpening->code,
                'title' => $this->jobOpening->title,
                'status' => $this->jobOpening->status->value,
                'status_label' => $this->jobOpening->status->label(),
                'department' => $this->jobOpening->relationLoaded('department') ? $this->jobOpening->department?->name : null,
            ]),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'history' => $this->whenLoaded('history', fn () => $this->history->map(fn ($h) => [
                'id' => $h->id,
                'from_stage' => $h->from_stage?->value,
                'from_stage_label' => $h->from_stage?->label(),
                'to_stage' => $h->to_stage->value,
                'to_stage_label' => $h->to_stage->label(),
                'reason_label' => $h->reason?->label(),
                'note' => $h->note,
                'user' => $h->user?->name,
                'created_at' => $h->created_at?->toIso8601String(),
            ])),
        ];
    }
}

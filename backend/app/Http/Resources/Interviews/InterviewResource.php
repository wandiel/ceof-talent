<?php

namespace App\Http\Resources\Interviews;

use App\Models\Interview;
use App\Models\InterviewEvaluation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Interview */
class InterviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $detail = ! $request->routeIs('api.v1.interviews.index', 'api.v1.applications.interviews');
        $application = $this->application;
        $candidate = $application?->candidate;
        $job = $application?->jobOpening;

        return [
            'id' => $this->uuid,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'mode' => $this->mode->value,
            'mode_label' => $this->mode->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_reason' => $this->status_reason,
            'scheduled_at' => $this->scheduled_at->toIso8601String(),
            'ends_at' => $this->endsAt()->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,
            'location' => $this->location,
            'meeting_url' => $this->meeting_url,
            'interviewers' => $this->whenLoaded('interviewers', fn () => $this->interviewers->map(fn ($u) => ['id' => $u->uuid, 'name' => $u->name])->values()),
            'candidate' => $candidate ? ['id' => $candidate->uuid, 'name' => $candidate->social_name ?: $candidate->full_name, 'desired_position' => $candidate->desired_position] : null,
            'application' => $application ? ['id' => $application->uuid, 'stage' => $application->stage->value, 'stage_label' => $application->stage->label()] : null,
            'job' => $job ? ['id' => $job->uuid, 'code' => $job->code, 'title' => $job->title] : null,
            'evaluations_count' => $this->whenCounted('evaluations'),
            'my_evaluation_pending' => $user && $this->relationLoaded('interviewers') && $this->interviewers->contains('id', $user->id)
                && ! $this->evaluations()->where('user_id', $user->id)->exists()
                && in_array($this->status->value, ['scheduled', 'completed'], true),

            $this->mergeWhen($detail, fn () => [
                'instructions' => $this->instructions,
                'criteria' => $this->criteria,
                'scheduled_by' => $this->scheduler?->name,
                'created_at' => $this->created_at?->toIso8601String(),
                'evaluations' => $this->visibleEvaluations($user)->map(fn (InterviewEvaluation $e) => [
                    'evaluator' => ['id' => $e->evaluator->uuid, 'name' => $e->evaluator->name],
                    'is_mine' => $e->user_id === $user?->id,
                    'recommendation' => $e->recommendation->value,
                    'recommendation_label' => $e->recommendation->label(),
                    'summary' => $e->summary,
                    'observations' => $e->observations,
                    'average_score' => $e->average_score,
                    'scores' => $e->scores->map(fn ($s) => ['criterion' => $s->criterion_uuid, 'name' => $s->criterion_name, 'score' => $s->score, 'comment' => $s->comment])->values(),
                    'updated_at' => $e->updated_at?->toIso8601String(),
                ])->values(),
                'evaluations_hidden' => $user ? ! $user->can('viewAllEvaluations', $this->resource) && $this->evaluations()->where('user_id', '!=', $user->id)->exists() : false,
                'abilities' => $user ? [
                    'update' => $this->status->value === 'scheduled' && $user->can('update', $this->resource),
                    'cancel' => $this->status->value === 'scheduled' && $user->can('update', $this->resource),
                    'no_show' => $this->status->value === 'scheduled' && $this->scheduled_at->isPast() && $user->can('update', $this->resource),
                    'evaluate' => in_array($this->status->value, ['scheduled', 'completed'], true) && $this->scheduled_at->isPast() && $user->can('evaluate', $this->resource),
                    'view_candidate' => $candidate ? $user->can('view', $candidate) : false,
                ] : null,
            ]),
        ];
    }

    /** @return \Illuminate\Support\Collection<int, InterviewEvaluation> */
    private function visibleEvaluations($user)
    {
        $query = $this->evaluations()->with(['evaluator:id,uuid,name', 'scores'])->orderBy('created_at');
        if (! $user || ! $user->can('viewAllEvaluations', $this->resource)) {
            $query->where('user_id', $user?->id ?? 0);
        }

        return $query->get();
    }
}

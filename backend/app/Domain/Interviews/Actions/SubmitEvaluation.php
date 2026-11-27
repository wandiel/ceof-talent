<?php

namespace App\Domain\Interviews\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Interviews\Enums\InterviewStatus;
use App\Domain\Interviews\Enums\Recommendation;
use App\Models\Interview;
use App\Models\InterviewEvaluation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registra (ou corrige) o parecer do próprio entrevistador: nota de 1 a 5 em
 * cada critério da entrevista, recomendação, parecer e observações.
 * O primeiro parecer marca a entrevista como realizada.
 */
class SubmitEvaluation
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{recommendation: string, summary: string, observations?: ?string, scores: list<array{criterion: string, score: int, comment?: ?string}>}  $data
     */
    public function handle(Interview $interview, array $data, User $evaluator): InterviewEvaluation
    {
        if (! in_array($interview->status, [InterviewStatus::Scheduled, InterviewStatus::Completed], true)) {
            throw ValidationException::withMessages(['status' => 'Não é possível registrar parecer em entrevista '.mb_strtolower($interview->status->label()).'.']);
        }
        if ($interview->scheduled_at->isFuture()) {
            throw ValidationException::withMessages(['status' => 'O parecer só pode ser registrado depois do horário da entrevista.']);
        }

        $criteria = collect($interview->criteria)->keyBy('id');
        $scores = collect($data['scores'])->keyBy('criterion');
        $missing = $criteria->keys()->diff($scores->keys());
        $unknown = $scores->keys()->diff($criteria->keys());
        if ($missing->isNotEmpty() || $unknown->isNotEmpty()) {
            throw ValidationException::withMessages(['scores' => 'Dê uma nota para cada critério da entrevista: '.$criteria->only($missing->all())->pluck('name')->implode(', ').'.']);
        }

        return DB::transaction(function () use ($interview, $data, $evaluator, $criteria, $scores) {
            $evaluation = $interview->evaluations()->firstOrNew(['user_id' => $evaluator->id]);
            $creating = ! $evaluation->exists;
            $before = $creating ? null : ['recommendation' => $evaluation->recommendation?->value, 'average_score' => $evaluation->average_score];

            $evaluation->fill([
                'recommendation' => Recommendation::from($data['recommendation']),
                'summary' => trim($data['summary']),
                'observations' => isset($data['observations']) && trim($data['observations']) !== '' ? trim($data['observations']) : null,
                'average_score' => round($scores->avg('score'), 2),
            ]);
            $evaluation->save();

            $evaluation->scores()->delete();
            foreach ($criteria as $id => $criterion) {
                $evaluation->scores()->create([
                    'criterion_uuid' => $id,
                    'criterion_name' => $criterion['name'],
                    'score' => (int) $scores[$id]['score'],
                    'comment' => isset($scores[$id]['comment']) && trim((string) $scores[$id]['comment']) !== '' ? trim($scores[$id]['comment']) : null,
                ]);
            }

            if ($interview->status === InterviewStatus::Scheduled) {
                $interview->status = InterviewStatus::Completed;
                $interview->save();
            }

            $label = $evaluation->recommendation->label();
            $text = ($creating ? 'Parecer registrado' : 'Parecer corrigido')." por {$evaluator->name}: {$label} (média {$evaluation->average_score}).";
            $this->audit->log($creating ? 'interview.evaluated' : 'interview.evaluation_updated', $interview, $text, $before, ['recommendation' => $evaluation->recommendation->value, 'average_score' => $evaluation->average_score]);
            $this->audit->log('interview.evaluated', $interview->application->candidate, $text, context: ['interview' => $interview->uuid]);

            return $evaluation;
        });
    }
}

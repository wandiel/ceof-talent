<?php

namespace App\Models;

use App\Domain\Interviews\Enums\Recommendation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Parecer de um entrevistador, com notas por critério (1 a 5). */
class InterviewEvaluation extends Model
{
    protected $fillable = ['user_id', 'recommendation', 'summary', 'observations', 'average_score'];

    protected function casts(): array
    {
        return ['recommendation' => Recommendation::class, 'average_score' => 'decimal:2'];
    }

    /** @return BelongsTo<Interview, $this> */
    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }

    /** @return BelongsTo<User, $this> */
    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<InterviewEvaluationScore, $this> */
    public function scores(): HasMany
    {
        return $this->hasMany(InterviewEvaluationScore::class)->orderBy('id');
    }
}

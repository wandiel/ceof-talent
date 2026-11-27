<?php

namespace App\Models;

use App\Domain\Intelligence\Enums\SuggestionStatus;
use App\Domain\Intelligence\Enums\SuggestionType;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sugestão automática (competência, área) extraída de um currículo, aguardando revisão. */
class CandidateSuggestion extends Model
{
    use HasPublicUuid;

    protected $fillable = ['candidate_document_id', 'type', 'value', 'confidence', 'evidence'];

    protected function casts(): array
    {
        return [
            'type' => SuggestionType::class,
            'status' => SuggestionStatus::class,
            'confidence' => 'decimal:2',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Candidate, $this> */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /** @return BelongsTo<CandidateDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(CandidateDocument::class, 'candidate_document_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}

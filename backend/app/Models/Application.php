<?php

namespace App\Models;

use App\Domain\Recruitment\Enums\ApplicationStage;
use App\Domain\Recruitment\Enums\RejectionReason;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Inscrição de um candidato numa vaga. */
class Application extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = ['job_opening_id', 'candidate_id', 'source', 'created_by'];

    protected function casts(): array
    {
        return [
            'stage' => ApplicationStage::class,
            'previous_stage' => ApplicationStage::class,
            'rejection_reason' => RejectionReason::class,
            'stage_changed_at' => 'datetime',
            'hired_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<JobOpening, $this> */
    public function jobOpening(): BelongsTo
    {
        return $this->belongsTo(JobOpening::class);
    }

    /** @return BelongsTo<Candidate, $this> */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<Interview, $this> */
    public function interviews(): HasMany
    {
        return $this->hasMany(Interview::class)->orderBy('scheduled_at');
    }

    /** @return HasMany<ApplicationStageHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(ApplicationStageHistory::class)->latest('id');
    }
}

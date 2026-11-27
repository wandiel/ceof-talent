<?php

namespace App\Models;

use App\Domain\Candidates\Enums\CourseType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Curso livre ou certificação. */
class CandidateCourse extends Model
{
    protected $fillable = ['type', 'name', 'institution', 'year', 'workload_hours', 'credential'];

    protected function casts(): array
    {
        return ['type' => CourseType::class, 'year' => 'integer', 'workload_hours' => 'integer'];
    }

    /** @return BelongsTo<Candidate, $this> */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }
}

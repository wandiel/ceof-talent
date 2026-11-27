<?php

namespace App\Models;

use App\Domain\Candidates\Enums\EducationLevel;
use App\Domain\Candidates\Enums\EducationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateEducation extends Model
{
    protected $table = 'candidate_educations';

    protected $fillable = ['level', 'institution', 'course', 'status', 'start_year', 'end_year'];

    protected function casts(): array
    {
        return ['level' => EducationLevel::class, 'status' => EducationStatus::class, 'start_year' => 'integer', 'end_year' => 'integer'];
    }

    /** @return BelongsTo<Candidate, $this> */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }
}

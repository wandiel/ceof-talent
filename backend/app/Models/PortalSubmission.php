<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Envio feito pelo portal "Trabalhe conosco" (imutável). */
class PortalSubmission extends Model
{
    use HasPublicUuid;

    public const UPDATED_AT = null;

    protected $fillable = ['protocol', 'candidate_id', 'job_opening_id', 'application_id', 'is_new_candidate', 'outcome', 'payload', 'ip_address', 'user_agent'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'is_new_candidate' => 'boolean'];
    }

    /** @return BelongsTo<Candidate, $this> */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /** @return BelongsTo<JobOpening, $this> */
    public function jobOpening(): BelongsTo
    {
        return $this->belongsTo(JobOpening::class);
    }
}

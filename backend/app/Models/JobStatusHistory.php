<?php

namespace App\Models;

use App\Domain\Recruitment\Enums\JobStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Registro imutável de mudança de situação da vaga. */
class JobStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'job_status_history';

    protected $fillable = ['from_status', 'to_status', 'user_id', 'reason'];

    protected function casts(): array
    {
        return ['from_status' => JobStatus::class, 'to_status' => JobStatus::class];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

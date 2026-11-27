<?php

namespace App\Models;

use App\Domain\Privacy\Enums\PrivacyRequestStatus;
use App\Domain\Privacy\Enums\PrivacyRequestType;
use App\Domain\Privacy\Enums\RequestChannel;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Solicitação de titular de dados (LGPD, art. 18). */
class PrivacyRequest extends Model
{
    use HasPublicUuid;

    protected $fillable = ['requester_name', 'requester_email', 'requester_phone', 'type', 'channel', 'description', 'status', 'due_on', 'response'];

    protected function casts(): array
    {
        return [
            'type' => PrivacyRequestType::class,
            'channel' => RequestChannel::class,
            'status' => PrivacyRequestStatus::class,
            'due_on' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function isOverdue(): bool
    {
        return ! $this->status->isClosed() && $this->due_on->endOfDay()->isPast();
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

    /** @return BelongsTo<User, $this> */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /** @return MorphMany<AuditLog, $this> */
    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }
}

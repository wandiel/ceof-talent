<?php

namespace App\Models;

use App\Domain\Portal\Enums\ConsentPurpose;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Consentimento (ou ciência) registrado para um candidato, com versão do aviso. */
class CandidateConsent extends Model
{
    use HasPublicUuid;

    protected $fillable = ['purpose', 'version', 'channel', 'granted_at', 'revoked_at', 'revoked_reason', 'recorded_by', 'ip_address', 'user_agent'];

    protected function casts(): array
    {
        return ['purpose' => ConsentPurpose::class, 'granted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    /** @return BelongsTo<Candidate, $this> */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}

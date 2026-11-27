<?php

namespace App\Models;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Interviews\Enums\InterviewMode;
use App\Domain\Interviews\Enums\InterviewStatus;
use App\Domain\Interviews\Enums\InterviewType;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/** Entrevista de um candidato dentro de um processo seletivo. */
class Interview extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = ['type', 'mode', 'scheduled_at', 'duration_minutes', 'location', 'meeting_url', 'instructions'];

    protected function casts(): array
    {
        return [
            'type' => InterviewType::class,
            'mode' => InterviewMode::class,
            'status' => InterviewStatus::class,
            'scheduled_at' => 'datetime',
            'reminded_at' => 'datetime',
            'duration_minutes' => 'integer',
            'criteria' => 'array',
        ];
    }

    public function endsAt(): Carbon
    {
        return $this->scheduled_at->copy()->addMinutes($this->duration_minutes);
    }

    /**
     * Entrevistas que o usuário pode ver: quem acompanha processos seletivos vê
     * as das vagas que enxerga; os demais (entrevistador) só aquelas de que participam.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(function (Builder $q) use ($user) {
            $q->whereHas('interviewers', fn (Builder $i) => $i->whereKey($user->id));
            if ($user->can(Permission::RecruitmentView->value)) {
                $q->orWhereHas('application.jobOpening', fn (Builder $j) => $j->visibleTo($user));
            }
        });
    }

    public function isVisibleTo(User $user): bool
    {
        return self::query()->visibleTo($user)->whereKey($this->getKey())->exists();
    }

    public function hasInterviewer(User $user): bool
    {
        return $this->interviewers()->whereKey($user->id)->exists();
    }

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function interviewers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'interview_interviewer');
    }

    /** @return HasMany<InterviewEvaluation, $this> */
    public function evaluations(): HasMany
    {
        return $this->hasMany(InterviewEvaluation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function scheduler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by');
    }

    /** @return MorphMany<AuditLog, $this> */
    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }
}

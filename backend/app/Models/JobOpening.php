<?php

namespace App\Models;

use App\Domain\Candidates\Enums\EducationLevel;
use App\Domain\Candidates\Enums\ProfessionalArea;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Recruitment\Enums\ApplicationStage;
use App\Domain\Recruitment\Enums\EmploymentType;
use App\Domain\Recruitment\Enums\JobReason;
use App\Domain\Recruitment\Enums\JobStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Vaga (requisição de pessoal) e seu processo seletivo. */
class JobOpening extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'title', 'department_id', 'unit_id', 'position_id', 'professional_area', 'employment_type', 'reason',
        'replaced_employee', 'positions_count', 'work_schedule', 'salary_min', 'salary_max', 'show_salary',
        'education_level_min', 'description', 'responsibilities', 'benefits', 'is_public', 'closes_on',
    ];

    protected function casts(): array
    {
        return [
            'status' => JobStatus::class,
            'professional_area' => ProfessionalArea::class,
            'employment_type' => EmploymentType::class,
            'reason' => JobReason::class,
            'education_level_min' => EducationLevel::class,
            'positions_count' => 'integer',
            'salary_min' => 'decimal:2',
            'salary_max' => 'decimal:2',
            'show_salary' => 'boolean',
            'is_public' => 'boolean',
            'closes_on' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * Vagas que o usuário pode ver: o RH vê todas; o gestor de departamento,
     * só as do próprio departamento.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (self::seesAllDepartments($user)) {
            return;
        }

        $query->where('job_openings.department_id', $user->department_id ?? 0);
    }

    /** Vagas que aparecem no portal: publicadas, públicas e com inscrições abertas. */
    public function scopePubliclyOpen(Builder $query): void
    {
        $query->where('job_openings.status', JobStatus::Published->value)
            ->where('job_openings.is_public', true)
            ->where(fn (Builder $q) => $q->whereNull('job_openings.closes_on')->orWhere('job_openings.closes_on', '>=', now()->toDateString()));
    }

    /** RH (gestão de vagas ou acesso a todos os candidatos) trabalha com qualquer departamento. */
    public static function seesAllDepartments(User $user): bool
    {
        return $user->can(Permission::JobsManage->value) || $user->can(Permission::CandidatesView->value);
    }

    public function isVisibleTo(User $user): bool
    {
        return self::query()->visibleTo($user)->whereKey($this->getKey())->exists();
    }

    public function hiredCount(): int
    {
        return $this->applications()->where('stage', ApplicationStage::Hired->value)->count();
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<Position, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<JobRequirement, $this> */
    public function requirements(): HasMany
    {
        return $this->hasMany(JobRequirement::class)->orderBy('sort')->orderBy('id');
    }

    /** @return HasMany<JobStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(JobStatusHistory::class)->latest('id');
    }

    /** @return HasMany<Application, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /** @return MorphMany<AuditLog, $this> */
    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }
}

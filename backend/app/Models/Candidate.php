<?php

namespace App\Models;

use App\Domain\Candidates\Enums\Availability;
use App\Domain\Candidates\Enums\CandidateSource;
use App\Domain\Candidates\Enums\CandidateStatus;
use App\Domain\Candidates\Enums\EducationLevel;
use App\Domain\Candidates\Enums\ProfessionalArea;
use App\Domain\Candidates\Support\Cpf;
use App\Domain\Identity\Enums\Permission;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Candidato do banco de talentos.
 *
 * O CPF é gravado criptografado; a busca por duplicidade usa cpf_hash.
 */
class Candidate extends Model
{
    use HasFactory, HasPublicUuid, SoftDeletes;

    protected $fillable = [
        'full_name', 'social_name', 'email', 'phone', 'phone_secondary', 'birth_date',
        'linkedin_url', 'portfolio_url', 'zip_code', 'neighborhood', 'city', 'state',
        'professional_area', 'education_level', 'desired_position', 'summary',
        'salary_expectation', 'availability', 'work_shifts', 'willing_to_relocate',
        'source', 'source_detail', 'status',
    ];

    protected $hidden = ['cpf', 'cpf_hash', 'search_vector'];

    protected function casts(): array
    {
        return [
            'cpf' => 'encrypted',
            'birth_date' => 'date',
            'salary_expectation' => 'decimal:2',
            'work_shifts' => 'array',
            'willing_to_relocate' => 'boolean',
            'professional_area' => ProfessionalArea::class,
            'education_level' => EducationLevel::class,
            'availability' => Availability::class,
            'source' => CandidateSource::class,
            'status' => CandidateStatus::class,
        ];
    }

    /** Define o CPF (só dígitos) e o hash usado para detectar duplicidade. */
    public function setCpf(?string $cpf): void
    {
        $digits = Cpf::normalize($cpf);
        $hash = $digits ? Cpf::hash($digits) : null;

        // Evita regravar o mesmo CPF (a criptografia gera um texto novo a cada vez).
        if ($this->exists && $hash === $this->cpf_hash) {
            return;
        }

        $this->cpf = $digits;
        $this->cpf_hash = $hash;
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', CandidateStatus::Active->value);
    }

    /**
     * Restringe aos candidatos que o usuário pode ver.
     * Gestor de departamento: candidatos das vagas do próprio departamento.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->can(Permission::CandidatesView->value)) {
            return;
        }

        $query->where(function (Builder $q) use ($user) {
            // Gestor de departamento: candidatos inscritos em vagas do próprio departamento.
            if ($user->can(Permission::CandidatesViewDepartment->value)) {
                $q->whereHas('applications.jobOpening', fn (Builder $j) => $j->where('department_id', $user->department_id ?? 0));
            }
            // Entrevistador: candidatos que ele entrevista.
            $q->orWhereHas('applications.interviews.interviewers', fn (Builder $i) => $i->whereKey($user->id));
        });
    }

    /** @return HasMany<CandidateSuggestion, $this> */
    public function suggestions(): HasMany
    {
        return $this->hasMany(CandidateSuggestion::class);
    }

    /** @return HasMany<CandidateEducation, $this> */
    public function educations(): HasMany
    {
        return $this->hasMany(CandidateEducation::class)->orderByDesc('end_year')->orderByDesc('start_year')->orderBy('id');
    }

    /** @return HasMany<CandidateExperience, $this> */
    public function experiences(): HasMany
    {
        return $this->hasMany(CandidateExperience::class)->orderByDesc('is_current')->orderByDesc('start_date')->orderBy('id');
    }

    /** @return HasMany<CandidateCourse, $this> */
    public function courses(): HasMany
    {
        return $this->hasMany(CandidateCourse::class)->orderByDesc('year')->orderBy('id');
    }

    /** @return BelongsToMany<Skill, $this> */
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class)->withPivot('level')->orderBy('name');
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withPivot(['tagged_by', 'created_at'])->orderBy('name');
    }

    /** Usuários que marcaram o candidato como favorito. @return BelongsToMany<User, $this> */
    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'candidate_favorites')->withPivot('created_at');
    }

    /** @return HasMany<Application, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /** @return HasMany<CandidateConsent, $this> */
    public function consents(): HasMany
    {
        return $this->hasMany(CandidateConsent::class)->latest('granted_at');
    }

    /** @return HasMany<PortalSubmission, $this> */
    public function portalSubmissions(): HasMany
    {
        return $this->hasMany(PortalSubmission::class)->latest('id');
    }

    /** @return HasMany<CandidateDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(CandidateDocument::class)->latest('id');
    }

    /** @return HasMany<CandidateNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(CandidateNote::class)->latest('id');
    }

    /** @return MorphMany<AuditLog, $this> */
    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

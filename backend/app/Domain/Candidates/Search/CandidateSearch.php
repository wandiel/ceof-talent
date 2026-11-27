<?php

namespace App\Domain\Candidates\Search;

use App\Domain\Candidates\Enums\DocumentType;
use App\Domain\Candidates\Enums\EducationLevel;
use App\Models\Candidate;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Pesquisa avançada do banco de currículos.
 *
 * Todos os filtros são combinados com "E". Dentro de "competências" todas
 * precisam estar presentes; dentro de "etiquetas" basta uma.
 */
class CandidateSearch
{
    /**
     * Tempo total de experiência, em dias, unindo períodos sobrepostos
     * (dois empregos ao mesmo tempo não contam em dobro).
     */
    public const EXPERIENCE_DAYS_SQL = <<<'SQL'
        (SELECT COALESCE(SUM(upper(r) - lower(r)), 0) FROM unnest((
            SELECT range_agg(daterange(x.start_date, GREATEST(x.start_date + 1, COALESCE((x.end_date + interval '1 month')::date, CURRENT_DATE))))
            FROM candidate_experiences x WHERE x.candidate_id = candidates.id
        )) AS r)
        SQL;

    /** Colunas aceitas em "sort" (prefixo "-" para ordem decrescente). */
    public const SORTABLE = ['relevance', 'full_name', 'city', 'created_at', 'updated_at', 'experience'];

    /**
     * @param  Builder<Candidate>  $query
     * @param  array<string, mixed>  $f  filtros já validados
     */
    public function apply(Builder $query, array $f, User $user): void
    {
        if ($query->getQuery()->columns === null) {
            $query->select('candidates.*');
        }
        $query->selectRaw('round(('.self::EXPERIENCE_DAYS_SQL.') / 30.4375)::int AS experience_months');

        if ($search = $f['search'] ?? null) {
            $this->simpleSearch($query, $search);
        }

        $keywords = trim((string) ($f['keywords'] ?? ''));
        if ($keywords !== '') {
            $query->whereRaw("candidates.search_vector @@ websearch_to_tsquery('portuguese', unaccent(?))", [$keywords])
                ->selectRaw("ts_rank_cd(candidates.search_vector, websearch_to_tsquery('portuguese', unaccent(?))) AS relevance", [$keywords]);
        }

        foreach (['status', 'professional_area', 'source', 'availability', 'state'] as $field) {
            if (! empty($f[$field])) {
                $query->where("candidates.$field", $f[$field]);
            }
        }

        if (! empty($f['city'])) {
            $query->whereRaw('unaccent(candidates.city) ILIKE unaccent(?)', [$this->like($f['city'], false)]);
        }

        if (! empty($f['education_level'])) {
            $query->where('candidates.education_level', $f['education_level']);
        }

        if (! empty($f['education_level_min'])) {
            $min = EducationLevel::from($f['education_level_min'])->rank();
            $levels = array_values(array_filter(EducationLevel::cases(), fn (EducationLevel $l) => $l->rank() >= $min));
            $query->whereIn('candidates.education_level', array_map(fn ($l) => $l->value, $levels));
        }

        if (! empty($f['education_course'])) {
            $query->whereHas('educations', fn (Builder $q) => $q->whereRaw('unaccent(course) ILIKE unaccent(?)', [$this->like($f['education_course'])]));
        }

        if (! empty($f['experience_title'])) {
            $query->whereHas('experiences', fn (Builder $q) => $q->whereRaw('unaccent(job_title) ILIKE unaccent(?)', [$this->like($f['experience_title'])]));
        }

        if (! empty($f['experience_company'])) {
            $query->whereHas('experiences', fn (Builder $q) => $q->whereRaw('unaccent(company) ILIKE unaccent(?)', [$this->like($f['experience_company'])]));
        }

        if (isset($f['min_experience_years']) && $f['min_experience_years'] !== null) {
            $query->whereRaw('('.self::EXPERIENCE_DAYS_SQL.') >= CAST(? AS numeric)', [(float) $f['min_experience_years'] * 365.25]);
        }

        foreach ($f['skills'] ?? [] as $skill) {
            $query->whereHas('skills', fn (Builder $q) => $q->where('skills.name', $skill));
        }

        if (! empty($f['tags'])) {
            $query->whereHas('tags', fn (Builder $q) => $q->whereIn('tags.uuid', $f['tags']));
        }

        if (! empty($f['work_shift'])) {
            $query->whereJsonContains('candidates.work_shifts', $f['work_shift']);
        }

        if (isset($f['salary_max']) && $f['salary_max'] !== null) {
            $query->where(fn (Builder $q) => $q->whereNull('candidates.salary_expectation')->orWhere('candidates.salary_expectation', '<=', $f['salary_max']));
        }

        if (! empty($f['age_min'])) {
            $query->where('candidates.birth_date', '<=', now()->subYears((int) $f['age_min'])->toDateString());
        }
        if (! empty($f['age_max'])) {
            $query->where('candidates.birth_date', '>', now()->subYears((int) $f['age_max'] + 1)->toDateString());
        }

        if (filter_var($f['willing_to_relocate'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->where('candidates.willing_to_relocate', true);
        }

        if (filter_var($f['has_resume'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->whereHas('documents', fn (Builder $q) => $q->where('type', DocumentType::Resume->value));
        }

        if (filter_var($f['favorites'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->whereHas('favoritedBy', fn (Builder $q) => $q->whereKey($user->id));
        }

        if (! empty($f['created_from'])) {
            $query->whereDate('candidates.created_at', '>=', $f['created_from']);
        }
        if (! empty($f['created_to'])) {
            $query->whereDate('candidates.created_at', '<=', $f['created_to']);
        }

        $this->sort($query, $f['sort'] ?? null, $keywords !== '');
    }

    /** Busca rápida: nome, e-mail, cargo pretendido ou parte do telefone. */
    private function simpleSearch(Builder $query, string $search): void
    {
        $digits = preg_replace('/\D/', '', $search);
        $query->where(function (Builder $q) use ($search, $digits) {
            $q->whereRaw('unaccent(candidates.full_name) ILIKE unaccent(?)', [$this->like($search)])
                ->orWhereRaw('unaccent(candidates.social_name) ILIKE unaccent(?)', [$this->like($search)])
                ->orWhere('candidates.email', 'ilike', $this->like($search))
                ->orWhereRaw('unaccent(candidates.desired_position) ILIKE unaccent(?)', [$this->like($search)]);
            if (strlen($digits) >= 4) {
                $q->orWhere('candidates.phone', 'like', '%'.$digits.'%')->orWhere('candidates.phone_secondary', 'like', '%'.$digits.'%');
            }
        });
    }

    private function sort(Builder $query, ?string $sort, bool $hasKeywords): void
    {
        $sort ??= $hasKeywords ? '-relevance' : '-created_at';
        $column = ltrim($sort, '-');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';

        match (true) {
            $column === 'relevance' && $hasKeywords => $query->orderBy('relevance', $direction === 'asc' ? 'asc' : 'desc'),
            $column === 'experience' => $query->orderBy('experience_months', $direction),
            in_array($column, ['full_name', 'city', 'created_at', 'updated_at'], true) => $query->orderBy("candidates.$column", $direction),
            default => $query->orderBy('candidates.created_at', 'desc'),
        };

        $query->orderBy('candidates.id');
    }

    private function like(string $value, bool $contains = true): string
    {
        $escaped = addcslashes($value, '%_\\');

        return $contains ? '%'.$escaped.'%' : $escaped;
    }
}

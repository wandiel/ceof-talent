<?php

namespace App\Domain\Candidates\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidates\Enums\CandidateStatus;
use App\Models\Candidate;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cria ou atualiza o cadastro completo de um candidato, incluindo formações,
 * experiências, cursos e competências, numa única transação auditada.
 */
class SaveCandidate
{
    /** Rótulos usados no histórico para dizer o que mudou. */
    private const FIELD_LABELS = [
        'full_name' => 'nome', 'social_name' => 'nome social', 'email' => 'e-mail', 'phone' => 'telefone',
        'phone_secondary' => 'telefone secundário', 'cpf_hash' => 'CPF', 'birth_date' => 'data de nascimento',
        'linkedin_url' => 'LinkedIn', 'portfolio_url' => 'portfólio', 'zip_code' => 'CEP', 'neighborhood' => 'bairro',
        'city' => 'cidade', 'state' => 'UF', 'professional_area' => 'área profissional', 'education_level' => 'escolaridade',
        'desired_position' => 'cargo pretendido', 'summary' => 'resumo', 'salary_expectation' => 'pretensão salarial',
        'availability' => 'disponibilidade', 'work_shifts' => 'turnos', 'willing_to_relocate' => 'mudança de cidade',
        'source' => 'origem', 'source_detail' => 'detalhe da origem',
    ];

    private const SECTIONS = ['educations' => 'formação', 'experiences' => 'experiências', 'courses' => 'cursos e certificações', 'skills' => 'competências'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $data  dados validados por CandidateRequest */
    public function handle(Candidate $candidate, array $data, User $actor): Candidate
    {
        return DB::transaction(function () use ($candidate, $data, $actor) {
            $creating = ! $candidate->exists;
            $original = $candidate->getAttributes();
            $sectionsBefore = $creating ? [] : $this->snapshotSections($candidate);

            $candidate->fill(Arr::only($data, $candidate->getFillable()));
            $candidate->work_shifts = array_values($data['work_shifts'] ?? []);
            $candidate->willing_to_relocate = (bool) ($data['willing_to_relocate'] ?? false);
            $candidate->setCpf($data['cpf'] ?? null);
            $candidate->updated_by = $actor->id;

            if ($creating) {
                $candidate->status = CandidateStatus::Active;
                $candidate->created_by = $actor->id;
            }

            $candidate->save();
            $changes = Arr::except($candidate->getChanges(), ['updated_at', 'updated_by', 'cpf']);
            $this->syncSections($candidate, $data);

            $candidate->refresh()->load(['educations', 'experiences', 'courses', 'skills']);

            if ($creating) {
                $this->audit->log('candidate.created', $candidate, 'Cadastro criado.', new: Arr::except($candidate->only($candidate->getFillable()), ['summary']));
            } else {
                $this->logUpdate($candidate, $changes, $original, $sectionsBefore);
            }

            return $candidate;
        });
    }

    /** @param  array<string, mixed>  $data */
    private function syncSections(Candidate $candidate, array $data): void
    {
        $candidate->educations()->delete();
        foreach ($data['educations'] ?? [] as $row) {
            $candidate->educations()->create(Arr::only($row, ['level', 'institution', 'course', 'status', 'start_year', 'end_year']));
        }

        $candidate->experiences()->delete();
        foreach ($data['experiences'] ?? [] as $row) {
            $current = (bool) ($row['is_current'] ?? false);
            $candidate->experiences()->create([
                'company' => $row['company'],
                'job_title' => $row['job_title'],
                'start_date' => Carbon::createFromFormat('!Y-m', $row['start_date']),
                'end_date' => ! $current && ! empty($row['end_date']) ? Carbon::createFromFormat('!Y-m', $row['end_date']) : null,
                'is_current' => $current,
                'description' => $row['description'] ?? null,
            ]);
        }

        $candidate->courses()->delete();
        foreach ($data['courses'] ?? [] as $row) {
            $candidate->courses()->create(Arr::only($row, ['type', 'name', 'institution', 'year', 'workload_hours', 'credential']));
        }

        $skills = [];
        foreach ($data['skills'] ?? [] as $row) {
            // O nome é citext: "excel" e "Excel" são a mesma competência.
            $skill = Skill::query()->where('name', $row['name'])->first() ?? Skill::create(['name' => $row['name']]);
            $skills[$skill->id] = ['level' => $row['level'] ?? null];
        }
        $candidate->skills()->sync($skills);
    }

    /** @return array<string, string> hash do conteúdo de cada seção */
    private function snapshotSections(Candidate $candidate): array
    {
        $candidate->load(['educations', 'experiences', 'courses', 'skills']);

        return [
            'educations' => md5($candidate->educations->map->only(['level', 'institution', 'course', 'status', 'start_year', 'end_year'])->toJson()),
            'experiences' => md5($candidate->experiences->map->only(['company', 'job_title', 'start_date', 'end_date', 'is_current', 'description'])->toJson()),
            'courses' => md5($candidate->courses->map->only(['type', 'name', 'institution', 'year', 'workload_hours', 'credential'])->toJson()),
            'skills' => md5($candidate->skills->map(fn ($s) => [$s->name, $s->pivot->level])->toJson()),
        ];
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $original
     * @param  array<string, string>  $sectionsBefore
     */
    private function logUpdate(Candidate $candidate, array $changes, array $original, array $sectionsBefore): void
    {
        $after = $this->snapshotSections($candidate);
        $changedSections = array_keys(array_filter(self::SECTIONS, fn ($label, $key) => ($sectionsBefore[$key] ?? null) !== $after[$key], ARRAY_FILTER_USE_BOTH));

        if ($changes === [] && $changedSections === []) {
            return;
        }

        $labels = array_merge(
            array_values(array_intersect_key(self::FIELD_LABELS, $changes)),
            array_map(fn ($key) => self::SECTIONS[$key], $changedSections),
        );

        $this->audit->log(
            'candidate.updated',
            $candidate,
            'Alterou: '.implode(', ', $labels).'.',
            old: Arr::except(Arr::only($original, array_keys($changes)), ['summary']),
            new: Arr::except($changes, ['summary']),
            context: $changedSections ? ['sections' => $changedSections] : null,
        );
    }
}

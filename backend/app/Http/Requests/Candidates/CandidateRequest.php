<?php

namespace App\Http\Requests\Candidates;

use App\Domain\Candidates\Enums\Availability;
use App\Domain\Candidates\Enums\CandidateSource;
use App\Domain\Candidates\Enums\CourseType;
use App\Domain\Candidates\Enums\EducationLevel;
use App\Domain\Candidates\Enums\EducationStatus;
use App\Domain\Candidates\Enums\ProfessionalArea;
use App\Domain\Candidates\Enums\SkillLevel;
use App\Domain\Candidates\Enums\WorkShift;
use App\Domain\Candidates\Support\Cpf;
use App\Http\Requests\Organization\UnitRequest;
use App\Models\Candidate;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Criação (POST) e edição (PUT) do cadastro completo do candidato.
 *
 * As listas (formações, experiências, cursos, competências) são enviadas
 * inteiras e substituem as anteriores.
 */
class CandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $candidate = $this->route('candidate');

        return $candidate instanceof Candidate
            ? $this->user()->can('update', $candidate)
            : $this->user()->can('create', Candidate::class);
    }

    protected function prepareForValidation(): void
    {
        $digits = fn (string $key) => $this->filled($key) ? preg_replace('/\D/', '', (string) $this->input($key)) : null;
        $text = fn (string $key) => $this->filled($key) ? trim(preg_replace('/\s+/u', ' ', (string) $this->input($key))) : null;

        $this->merge([
            'full_name' => $text('full_name'),
            'social_name' => $text('social_name'),
            'email' => $this->filled('email') ? mb_strtolower(trim((string) $this->input('email'))) : null,
            'phone' => $digits('phone'),
            'phone_secondary' => $digits('phone_secondary'),
            'cpf' => $digits('cpf'),
            'zip_code' => $digits('zip_code'),
            'state' => $this->filled('state') ? mb_strtoupper((string) $this->input('state')) : null,
            'skills' => collect((array) $this->input('skills', []))
                ->map(fn ($s) => is_array($s) ? ['name' => isset($s['name']) ? trim(preg_replace('/\s+/u', ' ', (string) $s['name'])) : null, 'level' => $s['level'] ?? null] : $s)
                ->values()->all(),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $candidate = $this->route('candidate');
        $ignoreId = $candidate instanceof Candidate ? $candidate->id : null;
        $year = (int) now()->format('Y');

        return [
            'full_name' => ['required', 'string', 'min:3', 'max:150'],
            'social_name' => ['nullable', 'string', 'max:150'],
            'email' => [
                'nullable', 'required_without:phone', 'email:rfc', 'max:191',
                Rule::unique('candidates', 'email')->ignore($ignoreId)->whereNull('deleted_at'),
            ],
            'phone' => ['nullable', 'required_without:email', 'regex:/^\d{10,11}$/'],
            'phone_secondary' => ['nullable', 'regex:/^\d{10,11}$/'],
            'cpf' => ['nullable', $this->validCpf(), $this->uniqueCpf($ignoreId)],
            'birth_date' => ['nullable', 'date', 'after:1900-01-01', 'before_or_equal:'.now()->subYears(14)->toDateString()],
            'linkedin_url' => ['nullable', 'url:http,https', 'max:255'],
            'portfolio_url' => ['nullable', 'url:http,https', 'max:255'],
            'zip_code' => ['nullable', 'digits:8'],
            'neighborhood' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', Rule::in(UnitRequest::STATES)],
            'professional_area' => ['required', Rule::enum(ProfessionalArea::class)],
            'education_level' => ['required', Rule::enum(EducationLevel::class)],
            'desired_position' => ['nullable', 'string', 'max:150'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'salary_expectation' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'availability' => ['nullable', Rule::enum(Availability::class)],
            'work_shifts' => ['nullable', 'array'],
            'work_shifts.*' => ['distinct', Rule::enum(WorkShift::class)],
            'willing_to_relocate' => ['sometimes', 'boolean'],
            'source' => ['required', Rule::enum(CandidateSource::class)],
            'source_detail' => ['nullable', 'string', 'max:150'],

            'educations' => ['nullable', 'array', 'max:20'],
            'educations.*.level' => ['required', Rule::enum(EducationLevel::class)],
            'educations.*.institution' => ['required', 'string', 'max:150'],
            'educations.*.course' => ['nullable', 'string', 'max:150'],
            'educations.*.status' => ['required', Rule::enum(EducationStatus::class)],
            'educations.*.start_year' => ['nullable', 'integer', 'min:1950', 'max:'.$year],
            'educations.*.end_year' => ['nullable', 'integer', 'min:1950', 'max:'.($year + 10)],

            'experiences' => ['nullable', 'array', 'max:30'],
            'experiences.*.company' => ['required', 'string', 'max:150'],
            'experiences.*.job_title' => ['required', 'string', 'max:150'],
            'experiences.*.start_date' => ['required', 'date_format:Y-m', 'after_or_equal:1950-01', 'before_or_equal:'.now()->format('Y-m')],
            'experiences.*.is_current' => ['sometimes', 'boolean'],
            'experiences.*.end_date' => ['nullable', 'date_format:Y-m', 'before_or_equal:'.now()->format('Y-m')],
            'experiences.*.description' => ['nullable', 'string', 'max:3000'],

            'courses' => ['nullable', 'array', 'max:50'],
            'courses.*.type' => ['required', Rule::enum(CourseType::class)],
            'courses.*.name' => ['required', 'string', 'max:150'],
            'courses.*.institution' => ['nullable', 'string', 'max:150'],
            'courses.*.year' => ['nullable', 'integer', 'min:1950', 'max:'.($year + 5)],
            'courses.*.workload_hours' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'courses.*.credential' => ['nullable', 'string', 'max:120'],

            'skills' => ['nullable', 'array', 'max:50'],
            'skills.*.name' => ['required', 'string', 'min:2', 'max:80', 'distinct:ignore_case'],
            'skills.*.level' => ['nullable', Rule::enum(SkillLevel::class)],
        ];
    }

    /** Regras que dependem de mais de um campo da mesma linha. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach ((array) $this->input('educations', []) as $i => $row) {
                    if (isset($row['start_year'], $row['end_year']) && is_numeric($row['start_year']) && is_numeric($row['end_year']) && (int) $row['end_year'] < (int) $row['start_year']) {
                        $validator->errors()->add("educations.$i.end_year", 'O ano de conclusão não pode ser anterior ao ano de início.');
                    }
                }

                foreach ((array) $this->input('experiences', []) as $i => $row) {
                    $current = filter_var($row['is_current'] ?? false, FILTER_VALIDATE_BOOLEAN);
                    $start = $row['start_date'] ?? null;
                    $end = $row['end_date'] ?? null;

                    if (! $current && empty($end)) {
                        $validator->errors()->add("experiences.$i.end_date", 'Informe a data de saída ou marque "Emprego atual".');
                    }
                    if (is_string($start) && is_string($end) && ! $current && $end < $start) {
                        $validator->errors()->add("experiences.$i.end_date", 'A data de saída não pode ser anterior à data de entrada.');
                    }
                }
            },
        ];
    }

    private function validCpf(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! Cpf::isValid((string) $value)) {
                $fail('O CPF informado não é válido.');
            }
        };
    }

    private function uniqueCpf(?int $ignoreId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignoreId) {
            if (! Cpf::isValid((string) $value)) {
                return;
            }

            $exists = Candidate::query()
                ->where('cpf_hash', Cpf::hash((string) Cpf::normalize((string) $value)))
                ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
                ->exists();

            if ($exists) {
                $fail('Já existe um candidato cadastrado com este CPF.');
            }
        };
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.unique' => 'Já existe um candidato cadastrado com este e-mail.',
            'email.required_without' => 'Informe ao menos um contato: e-mail ou telefone.',
            'phone.required_without' => 'Informe ao menos um contato: e-mail ou telefone.',
            'phone.regex' => 'Informe o telefone com DDD (10 ou 11 dígitos).',
            'phone_secondary.regex' => 'Informe o telefone com DDD (10 ou 11 dígitos).',
            'birth_date.before_or_equal' => 'O candidato precisa ter ao menos 14 anos.',
            'skills.*.name.distinct' => 'Esta competência foi informada mais de uma vez.',
            'experiences.*.start_date.before_or_equal' => 'A data de entrada não pode estar no futuro.',
            'experiences.*.end_date.before_or_equal' => 'A data de saída não pode estar no futuro.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'full_name' => 'nome completo', 'social_name' => 'nome social', 'email' => 'e-mail', 'phone' => 'telefone',
            'phone_secondary' => 'telefone secundário', 'cpf' => 'CPF', 'birth_date' => 'data de nascimento',
            'linkedin_url' => 'LinkedIn', 'portfolio_url' => 'portfólio', 'zip_code' => 'CEP', 'neighborhood' => 'bairro',
            'city' => 'cidade', 'state' => 'UF', 'professional_area' => 'área profissional', 'education_level' => 'escolaridade',
            'desired_position' => 'cargo pretendido', 'summary' => 'resumo profissional', 'salary_expectation' => 'pretensão salarial',
            'availability' => 'disponibilidade', 'work_shifts' => 'turnos', 'source' => 'origem', 'source_detail' => 'detalhe da origem',
            'educations.*.level' => 'nível', 'educations.*.institution' => 'instituição', 'educations.*.course' => 'curso',
            'educations.*.status' => 'situação', 'educations.*.start_year' => 'ano de início', 'educations.*.end_year' => 'ano de conclusão',
            'experiences.*.company' => 'empresa', 'experiences.*.job_title' => 'cargo', 'experiences.*.start_date' => 'data de entrada',
            'experiences.*.end_date' => 'data de saída', 'experiences.*.description' => 'atividades',
            'courses.*.type' => 'tipo', 'courses.*.name' => 'nome do curso', 'courses.*.institution' => 'instituição',
            'courses.*.year' => 'ano', 'courses.*.workload_hours' => 'carga horária', 'courses.*.credential' => 'registro',
            'skills.*.name' => 'competência', 'skills.*.level' => 'nível',
        ];
    }
}

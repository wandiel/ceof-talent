<?php

namespace App\Http\Requests\Recruitment;

use App\Domain\Candidates\Enums\EducationLevel;
use App\Domain\Candidates\Enums\ProfessionalArea;
use App\Domain\Recruitment\Enums\EmploymentType;
use App\Domain\Recruitment\Enums\JobReason;
use App\Models\Department;
use App\Models\JobOpening;
use App\Models\Position;
use App\Models\Unit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class JobOpeningRequest extends FormRequest
{
    public function authorize(): bool
    {
        $job = $this->route('job');

        return $job instanceof JobOpening
            ? $this->user()->can('update', $job)
            : $this->user()->can('create', JobOpening::class);
    }

    protected function prepareForValidation(): void
    {
        $trim = fn ($v) => is_string($v) ? (trim(preg_replace('/[ \t]+/', ' ', $v)) ?: null) : $v;

        $this->merge(collect($this->only(['title', 'replaced_employee', 'work_schedule']))->map($trim)->all());

        if (is_array($this->input('requirements'))) {
            $this->merge(['requirements' => array_values(array_filter(
                array_map(fn ($r) => is_array($r) ? ['description' => $trim($r['description'] ?? null), 'is_mandatory' => $r['is_mandatory'] ?? true] : $r, $this->input('requirements')),
                fn ($r) => ! is_array($r) || $r['description'] !== null,
            ))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var JobOpening|null $job */
        $job = $this->route('job');
        $closesOnUnchanged = $job && $job->closes_on?->toDateString() === $this->input('closes_on');

        return [
            'title' => ['required', 'string', 'max:150'],
            'department_id' => ['required', 'uuid', Rule::exists('departments', 'uuid')],
            'unit_id' => ['nullable', 'uuid', Rule::exists('units', 'uuid')],
            'position_id' => ['nullable', 'uuid', Rule::exists('positions', 'uuid')],
            'professional_area' => ['required', Rule::enum(ProfessionalArea::class)],
            'employment_type' => ['required', Rule::enum(EmploymentType::class)],
            'reason' => ['required', Rule::enum(JobReason::class)],
            'replaced_employee' => ['nullable', 'required_if:reason,replacement', 'string', 'max:150'],
            'positions_count' => ['required', 'integer', 'min:1', 'max:500'],
            'work_schedule' => ['nullable', 'string', 'max:150'],
            'salary_min' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'salary_max' => ['nullable', 'numeric', 'min:0', 'max:99999999', Rule::when($this->filled('salary_min'), 'gte:salary_min')],
            'show_salary' => ['boolean'],
            'education_level_min' => ['nullable', Rule::enum(EducationLevel::class)],
            'description' => ['required', 'string', 'min:20', 'max:10000'],
            'responsibilities' => ['nullable', 'string', 'max:10000'],
            'benefits' => ['nullable', 'string', 'max:5000'],
            'is_public' => ['boolean'],
            'closes_on' => ['nullable', 'date', Rule::when(! $closesOnUnchanged, 'after_or_equal:today')],
            'requirements' => ['array', 'max:30'],
            'requirements.*.description' => ['required', 'string', 'max:255'],
            'requirements.*.is_mandatory' => ['boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $user = $this->user();
            if (! JobOpening::seesAllDepartments($user) && Department::query()->where('uuid', $this->input('department_id'))->value('id') !== $user->department_id) {
                $validator->errors()->add('department_id', 'Você só pode solicitar vagas para o seu próprio departamento.');
            }
        }];
    }

    /**
     * Dados validados com os identificadores públicos (UUID) de departamento,
     * unidade e cargo trocados pelas chaves internas.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();
        foreach (['department_id' => Department::class, 'unit_id' => Unit::class, 'position_id' => Position::class] as $key => $model) {
            if (array_key_exists($key, $data)) {
                $data[$key] = $data[$key] ? $model::query()->where('uuid', $data[$key])->value('id') : null;
            }
        }

        return $data;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'title' => 'título', 'department_id' => 'departamento', 'unit_id' => 'unidade', 'position_id' => 'cargo',
            'professional_area' => 'área profissional', 'employment_type' => 'tipo de contratação', 'reason' => 'motivo da vaga',
            'replaced_employee' => 'colaborador substituído', 'positions_count' => 'quantidade de posições', 'work_schedule' => 'jornada',
            'salary_min' => 'salário mínimo', 'salary_max' => 'salário máximo', 'education_level_min' => 'escolaridade mínima',
            'description' => 'descrição', 'responsibilities' => 'atividades', 'benefits' => 'benefícios', 'closes_on' => 'data limite',
            'requirements.*.description' => 'requisito',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'replaced_employee.required_if' => 'Informe quem está sendo substituído.',
            'closes_on.after_or_equal' => 'A data limite não pode estar no passado.',
            'salary_max.gte' => 'O salário máximo deve ser maior ou igual ao mínimo.',
        ];
    }
}

<?php

namespace App\Http\Requests\Organization;

use App\Models\Department;
use App\Models\Position;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $position = $this->route('position');

        return $position instanceof Position ? $this->user()->can('update', $position) : $this->user()->can('create', Position::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:2', 'max:150'],
            'department_id' => ['nullable', 'uuid', Rule::exists('departments', 'uuid')],
            'cbo_code' => ['nullable', 'string', 'regex:/^\d{4}-?\d{2}$/'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** Título único dentro do mesmo departamento (sem diferenciar maiúsculas). */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $departmentId = $this->filled('department_id')
                ? Department::query()->where('uuid', $this->input('department_id'))->value('id')
                : null;

            $exists = Position::query()
                ->whereRaw('lower(title) = ?', [mb_strtolower((string) $this->input('title'))])
                ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId), fn ($q) => $q->whereNull('department_id'))
                ->when($this->route('position'), fn ($q, $position) => $q->whereKeyNot($position->id))
                ->exists();

            if ($exists) {
                $validator->errors()->add('title', 'Já existe um cargo com este título neste departamento.');
            }
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['title' => 'título', 'department_id' => 'departamento', 'cbo_code' => 'código CBO', 'description' => 'descrição'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['cbo_code.regex' => 'O código CBO deve ter o formato 0000-00.'];
    }
}

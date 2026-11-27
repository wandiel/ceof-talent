<?php

namespace App\Http\Requests\Organization;

use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $department = $this->route('department');

        return $department instanceof Department ? $this->user()->can('update', $department) : $this->user()->can('create', Department::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => $this->filled('code') ? mb_strtoupper(trim((string) $this->input('code'))) : null]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->route('department')?->id;

        return [
            'name' => ['required', 'string', 'min:2', 'max:150', Rule::unique('departments', 'name')->ignore($id)],
            'code' => ['nullable', 'string', 'max:30', 'alpha_dash', Rule::unique('departments', 'code')->ignore($id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'nome', 'code' => 'código', 'description' => 'descrição'];
    }
}

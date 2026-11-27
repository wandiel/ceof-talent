<?php

namespace App\Http\Requests\Organization;

use App\Models\Unit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        $unit = $this->route('unit');

        return $unit instanceof Unit ? $this->user()->can('update', $unit) : $this->user()->can('create', Unit::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'state' => $this->filled('state') ? mb_strtoupper((string) $this->input('state')) : null,
            'code' => $this->filled('code') ? mb_strtoupper(trim((string) $this->input('code'))) : null,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->route('unit')?->id;

        return [
            'name' => ['required', 'string', 'min:2', 'max:150', Rule::unique('units', 'name')->ignore($id)],
            'code' => ['nullable', 'string', 'max:30', 'alpha_dash', Rule::unique('units', 'code')->ignore($id)],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'size:2', Rule::in(self::STATES)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'nome', 'code' => 'código', 'city' => 'cidade', 'state' => 'UF'];
    }

    public const STATES = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];
}

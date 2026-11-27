<?php

namespace App\Http\Requests\Identity;

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\PasswordPolicy;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Criação (POST) e edição (PUT) de usuários internos. */
class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->route('user');

        return $user instanceof User
            ? $this->user()->can('update', $user)
            : $this->user()->can('create', User::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $user = $this->route('user');
        $creating = ! $user instanceof User;

        return [
            'name' => ['required', 'string', 'min:3', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'job_title' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9()+\-\s]{8,30}$/'],
            'unit_id' => ['nullable', 'uuid', Rule::exists('units', 'uuid')],
            // Gestor de departamento precisa de um departamento: ele define o escopo de acesso.
            'department_id' => [Rule::requiredIf(fn () => in_array(Role::DepartmentManager->value, (array) $this->input('roles', []), true)), 'nullable', 'uuid', Rule::exists('departments', 'uuid')],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'distinct', Rule::in(Role::values())],
            'is_active' => ['sometimes', 'boolean'],
            // Senha provisória: obrigatória na criação; o usuário troca no 1º acesso.
            'password' => [$creating ? 'required' : 'prohibited', 'string', PasswordPolicy::rule()],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'email' => 'e-mail',
            'job_title' => 'cargo/função',
            'phone' => 'telefone',
            'unit_id' => 'unidade',
            'department_id' => 'departamento',
            'roles' => 'perfis de acesso',
            'roles.*' => 'perfil de acesso',
            'password' => 'senha provisória',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Informe um telefone válido (somente números, espaços, parênteses, + e -).',
            'department_id.required' => 'Informe o departamento do Gestor de departamento.',
        ];
    }
}

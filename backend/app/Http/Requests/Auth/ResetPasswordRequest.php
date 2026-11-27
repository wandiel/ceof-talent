<?php

namespace App\Http\Requests\Auth;

use App\Domain\Identity\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', PasswordPolicy::rule()],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['password' => 'nova senha'];
    }
}

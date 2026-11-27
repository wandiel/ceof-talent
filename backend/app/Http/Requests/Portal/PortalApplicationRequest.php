<?php

namespace App\Http\Requests\Portal;

use App\Domain\Candidates\Documents\UploadInspector;
use App\Domain\Candidates\Enums\EducationLevel;
use App\Domain\Candidates\Enums\ProfessionalArea;
use App\Domain\Candidates\Support\Cpf;
use App\Http\Requests\Organization\UnitRequest;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Formulário público de cadastro e candidatura (sem login). */
class PortalApplicationRequest extends FormRequest
{
    public const MAX_EXTRA_DOCUMENTS = 3;

    public function authorize(): bool
    {
        return true;
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
            'cpf' => $digits('cpf'),
            'city' => $text('city'),
            'state' => $this->filled('state') ? mb_strtoupper((string) $this->input('state')) : null,
            'desired_position' => $text('desired_position'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'job' => ['nullable', 'uuid'],
            'full_name' => ['required', 'string', 'min:3', 'max:150'],
            'social_name' => ['nullable', 'string', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:191'],
            'phone' => ['required', 'regex:/^\d{10,11}$/'],
            'cpf' => ['nullable', function (string $attr, mixed $value, Closure $fail) {
                if (! Cpf::isValid((string) $value)) {
                    $fail('O CPF informado não é válido.');
                }
            }],
            'birth_date' => ['nullable', 'date', 'after:1900-01-01', 'before_or_equal:'.now()->subYears(14)->toDateString()],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['required', Rule::in(UnitRequest::STATES)],
            'professional_area' => ['required', Rule::enum(ProfessionalArea::class)],
            'education_level' => ['required', Rule::enum(EducationLevel::class)],
            'desired_position' => ['nullable', 'string', 'max:150'],
            'summary' => ['nullable', 'string', 'max:3000'],
            'linkedin_url' => ['nullable', 'url:https', 'max:255'],
            'resume' => ['required', 'file', 'max:'.UploadInspector::MAX_KILOBYTES],
            'documents' => ['nullable', 'array', 'max:'.self::MAX_EXTRA_DOCUMENTS],
            'documents.*' => ['file', 'max:'.UploadInspector::MAX_KILOBYTES],
            'privacy_accepted' => ['accepted'],
            'talent_pool_consent' => ['sometimes', 'boolean'],
            // Campo invisível: pessoas não preenchem, robôs costumam preencher.
            'website' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'privacy_accepted.accepted' => 'Para enviar, confirme que leu o aviso de privacidade.',
            'resume.required' => 'Anexe o seu currículo.',
            'phone.regex' => 'Informe o telefone com DDD (10 ou 11 dígitos).',
            'website.prohibited' => 'Não foi possível enviar o formulário.',
            'documents.max' => 'Envie no máximo '.self::MAX_EXTRA_DOCUMENTS.' documentos adicionais.',
            'birth_date.before_or_equal' => 'É preciso ter pelo menos 14 anos.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'full_name' => 'nome completo', 'social_name' => 'nome social', 'email' => 'e-mail', 'phone' => 'telefone',
            'birth_date' => 'data de nascimento', 'city' => 'cidade', 'state' => 'UF', 'professional_area' => 'área profissional',
            'education_level' => 'escolaridade', 'desired_position' => 'cargo pretendido', 'summary' => 'resumo profissional',
            'linkedin_url' => 'LinkedIn', 'resume' => 'currículo', 'documents.*' => 'documento',
        ];
    }
}

<?php

namespace App\Domain\Candidates\Enums;

use App\Support\LabeledEnum;

/** Tipo de documento anexado ao candidato. */
enum DocumentType: string
{
    use LabeledEnum;

    case Resume = 'resume';
    case Certificate = 'certificate';
    case ProfessionalRegistry = 'professional_registry';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Resume => 'Currículo',
            self::Certificate => 'Certificado ou diploma',
            self::ProfessionalRegistry => 'Registro profissional',
            self::Other => 'Outro',
        };
    }
}

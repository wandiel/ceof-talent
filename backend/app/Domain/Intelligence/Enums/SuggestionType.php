<?php

namespace App\Domain\Intelligence\Enums;

use App\Support\LabeledEnum;

/** Tipo de sugestão gerada pela leitura automática do currículo. */
enum SuggestionType: string
{
    use LabeledEnum;

    case Skill = 'skill';
    case ProfessionalArea = 'professional_area';

    public function label(): string
    {
        return match ($this) {
            self::Skill => 'Competência',
            self::ProfessionalArea => 'Área profissional',
        };
    }
}

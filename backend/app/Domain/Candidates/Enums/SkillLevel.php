<?php

namespace App\Domain\Candidates\Enums;

use App\Support\LabeledEnum;

/** Nível de domínio de uma competência. */
enum SkillLevel: string
{
    use LabeledEnum;

    case Basic = 'basic';
    case Intermediate = 'intermediate';
    case Advanced = 'advanced';

    public function label(): string
    {
        return match ($this) {
            self::Basic => 'Básico',
            self::Intermediate => 'Intermediário',
            self::Advanced => 'Avançado',
        };
    }
}

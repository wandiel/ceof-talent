<?php

namespace App\Domain\Recruitment\Enums;

use App\Support\LabeledEnum;

/** Motivo da abertura da vaga. */
enum JobReason: string
{
    use LabeledEnum;

    case NewPosition = 'new_position';
    case Replacement = 'replacement';
    case Temporary = 'temporary';

    public function label(): string
    {
        return match ($this) {
            self::NewPosition => 'Aumento de quadro',
            self::Replacement => 'Substituição',
            self::Temporary => 'Demanda temporária',
        };
    }
}

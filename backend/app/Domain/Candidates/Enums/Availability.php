<?php

namespace App\Domain\Candidates\Enums;

use App\Support\LabeledEnum;

/** Disponibilidade para início. */
enum Availability: string
{
    use LabeledEnum;

    case Immediate = 'immediate';
    case Days15 = 'days_15';
    case Days30 = 'days_30';
    case Negotiable = 'negotiable';

    public function label(): string
    {
        return match ($this) {
            self::Immediate => 'Imediata',
            self::Days15 => 'Em até 15 dias',
            self::Days30 => 'Em até 30 dias',
            self::Negotiable => 'A combinar',
        };
    }
}

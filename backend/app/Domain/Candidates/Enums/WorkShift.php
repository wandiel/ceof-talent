<?php

namespace App\Domain\Candidates\Enums;

use App\Support\LabeledEnum;

/** Turnos em que o candidato pode trabalhar. */
enum WorkShift: string
{
    use LabeledEnum;

    case Morning = 'morning';
    case Afternoon = 'afternoon';
    case Night = 'night';
    case OnCall = 'on_call';
    case Weekends = 'weekends';

    public function label(): string
    {
        return match ($this) {
            self::Morning => 'Manhã',
            self::Afternoon => 'Tarde',
            self::Night => 'Noite',
            self::OnCall => 'Plantão 12x36',
            self::Weekends => 'Fins de semana',
        };
    }
}

<?php

namespace App\Domain\Interviews\Enums;

use App\Support\LabeledEnum;

/** Recomendação final do entrevistador no parecer. */
enum Recommendation: string
{
    use LabeledEnum;

    case StrongYes = 'strong_yes';
    case Yes = 'yes';
    case WithReservations = 'with_reservations';
    case No = 'no';

    public function label(): string
    {
        return match ($this) {
            self::StrongYes => 'Recomendo fortemente',
            self::Yes => 'Recomendo',
            self::WithReservations => 'Recomendo com ressalvas',
            self::No => 'Não recomendo',
        };
    }
}

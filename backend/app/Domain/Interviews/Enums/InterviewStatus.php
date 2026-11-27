<?php

namespace App\Domain\Interviews\Enums;

use App\Support\LabeledEnum;

/** Situação da entrevista. */
enum InterviewStatus: string
{
    use LabeledEnum;

    case Scheduled = 'scheduled';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Agendada',
            self::Completed => 'Realizada',
            self::Cancelled => 'Cancelada',
            self::NoShow => 'Candidato não compareceu',
        };
    }
}

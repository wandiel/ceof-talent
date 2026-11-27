<?php

namespace App\Domain\Candidates\Enums;

use App\Support\LabeledEnum;

/** Situação de uma formação. */
enum EducationStatus: string
{
    use LabeledEnum;

    case Completed = 'completed';
    case InProgress = 'in_progress';
    case Interrupted = 'interrupted';

    public function label(): string
    {
        return match ($this) {
            self::Completed => 'Concluído',
            self::InProgress => 'Em andamento',
            self::Interrupted => 'Interrompido',
        };
    }
}

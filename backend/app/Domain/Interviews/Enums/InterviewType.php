<?php

namespace App\Domain\Interviews\Enums;

use App\Support\LabeledEnum;

/** Tipo de entrevista. */
enum InterviewType: string
{
    use LabeledEnum;

    case Hr = 'hr';
    case Technical = 'technical';
    case Manager = 'manager';
    case Panel = 'panel';

    public function label(): string
    {
        return match ($this) {
            self::Hr => 'Entrevista com o RH',
            self::Technical => 'Entrevista técnica',
            self::Manager => 'Entrevista com o gestor',
            self::Panel => 'Painel (vários entrevistadores)',
        };
    }
}

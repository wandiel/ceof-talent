<?php

namespace App\Domain\Privacy\Enums;

use App\Support\LabeledEnum;

enum RequestChannel: string
{
    use LabeledEnum;

    case Email = 'email';
    case Phone = 'phone';
    case InPerson = 'in_person';
    case Letter = 'letter';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'E-mail',
            self::Phone => 'Telefone',
            self::InPerson => 'Presencial',
            self::Letter => 'Carta / ofício',
            self::Other => 'Outro',
        };
    }
}

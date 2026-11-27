<?php

namespace App\Domain\Interviews\Enums;

use App\Support\LabeledEnum;

/** Formato da entrevista. */
enum InterviewMode: string
{
    use LabeledEnum;

    case InPerson = 'in_person';
    case Video = 'video';
    case Phone = 'phone';

    public function label(): string
    {
        return match ($this) {
            self::InPerson => 'Presencial',
            self::Video => 'Videochamada',
            self::Phone => 'Telefone',
        };
    }
}

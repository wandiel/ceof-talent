<?php

namespace App\Domain\Candidates\Enums;

use App\Support\LabeledEnum;

/** Tipo de qualificação complementar. */
enum CourseType: string
{
    use LabeledEnum;

    case Course = 'course';
    case Certification = 'certification';

    public function label(): string
    {
        return match ($this) {
            self::Course => 'Curso',
            self::Certification => 'Certificação',
        };
    }
}

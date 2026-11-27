<?php

namespace App\Domain\Recruitment\Enums;

use App\Support\LabeledEnum;

/** Regime de contratação. */
enum EmploymentType: string
{
    use LabeledEnum;

    case Clt = 'clt';
    case Internship = 'internship';
    case Apprentice = 'apprentice';
    case Temporary = 'temporary';
    case Contractor = 'contractor';
    case OnCall = 'on_call';

    public function label(): string
    {
        return match ($this) {
            self::Clt => 'CLT',
            self::Internship => 'Estágio',
            self::Apprentice => 'Jovem aprendiz',
            self::Temporary => 'Temporário',
            self::Contractor => 'Pessoa jurídica',
            self::OnCall => 'Plantonista ou autônomo',
        };
    }
}

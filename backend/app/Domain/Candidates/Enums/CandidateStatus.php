<?php

namespace App\Domain\Candidates\Enums;

use App\Support\LabeledEnum;

/** Situação do candidato no banco de talentos. */
enum CandidateStatus: string
{
    use LabeledEnum;

    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Ativo',
            self::Inactive => 'Inativo',
        };
    }
}

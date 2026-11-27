<?php

namespace App\Domain\Recruitment\Enums;

use App\Support\LabeledEnum;

/** Motivo de reprovação de um candidato no processo. */
enum RejectionReason: string
{
    use LabeledEnum;

    case ProfileMismatch = 'profile_mismatch';
    case Salary = 'salary';
    case Availability = 'availability';
    case Interview = 'interview';
    case Technical = 'technical';
    case Documentation = 'documentation';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ProfileMismatch => 'Perfil não aderente à vaga',
            self::Salary => 'Pretensão salarial',
            self::Availability => 'Disponibilidade de horário ou início',
            self::Interview => 'Desempenho na entrevista',
            self::Technical => 'Desempenho na avaliação técnica',
            self::Documentation => 'Documentação ou requisitos legais',
            self::Other => 'Outro',
        };
    }
}

<?php

namespace App\Domain\Candidates\Enums;

use App\Support\LabeledEnum;

/** Escolaridade, da menor para a maior (a ordem dos casos é usada em comparações). */
enum EducationLevel: string
{
    use LabeledEnum;

    case ElementaryIncomplete = 'elementary_incomplete';
    case Elementary = 'elementary';
    case HighSchoolIncomplete = 'high_school_incomplete';
    case HighSchool = 'high_school';
    case Technical = 'technical';
    case HigherIncomplete = 'higher_incomplete';
    case Higher = 'higher';
    case Postgraduate = 'postgraduate';
    case Masters = 'masters';
    case Doctorate = 'doctorate';

    public function label(): string
    {
        return match ($this) {
            self::ElementaryIncomplete => 'Fundamental incompleto',
            self::Elementary => 'Fundamental completo',
            self::HighSchoolIncomplete => 'Médio incompleto',
            self::HighSchool => 'Médio completo',
            self::Technical => 'Técnico',
            self::HigherIncomplete => 'Superior incompleto',
            self::Higher => 'Superior completo',
            self::Postgraduate => 'Pós-graduação ou especialização',
            self::Masters => 'Mestrado',
            self::Doctorate => 'Doutorado',
        };
    }

    /** Posição na escala de escolaridade (0 = menor). */
    public function rank(): int
    {
        return (int) array_search($this, self::cases(), true);
    }
}

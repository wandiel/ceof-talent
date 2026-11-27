<?php

namespace App\Domain\Recruitment\Enums;

use App\Support\LabeledEnum;

/** Etapa da inscrição no processo seletivo (as sete primeiras formam o funil; reprovado e desistente são desfechos). */
enum ApplicationStage: string
{
    use LabeledEnum;

    case Applied = 'applied';
    case Screening = 'screening';
    case Shortlisted = 'shortlisted';
    case Interview = 'interview';
    case Technical = 'technical';
    case Approved = 'approved';
    case Hired = 'hired';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Applied => 'Inscrição',
            self::Screening => 'Triagem',
            self::Shortlisted => 'Pré-seleção',
            self::Interview => 'Entrevista',
            self::Technical => 'Avaliação técnica',
            self::Approved => 'Aprovação',
            self::Hired => 'Contratação',
            self::Rejected => 'Reprovado',
            self::Withdrawn => 'Desistente',
        };
    }

    /** Etapas do funil, na ordem do processo. @return list<self> */
    public static function pipeline(): array
    {
        return [self::Applied, self::Screening, self::Shortlisted, self::Interview, self::Technical, self::Approved, self::Hired];
    }

    /** Ainda em andamento (pode ser movida). */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Hired, self::Rejected, self::Withdrawn], true);
    }

    /** Posição no funil (desfechos ficam fora: -1). */
    public function order(): int
    {
        $index = array_search($this, self::pipeline(), true);

        return $index === false ? -1 : $index;
    }
}

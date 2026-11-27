<?php

namespace App\Domain\Candidates\Enums;

use App\Support\LabeledEnum;

/** Origem do cadastro (usada no relatório de origem dos candidatos). */
enum CandidateSource: string
{
    use LabeledEnum;

    case Internal = 'internal';
    case Portal = 'portal';
    case Referral = 'referral';
    case LinkedIn = 'linkedin';
    case JobBoard = 'job_board';
    case Agency = 'agency';
    case WalkIn = 'walk_in';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Internal => 'Cadastro interno',
            self::Portal => 'Portal Trabalhe Conosco',
            self::Referral => 'Indicação',
            self::LinkedIn => 'LinkedIn',
            self::JobBoard => 'Site de vagas',
            self::Agency => 'Agência ou consultoria',
            self::WalkIn => 'Currículo entregue pessoalmente',
            self::Other => 'Outra',
        };
    }
}

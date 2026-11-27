<?php

namespace App\Domain\Recruitment\Enums;

use App\Support\LabeledEnum;

/** Situação da vaga ao longo do seu ciclo de vida. */
enum JobStatus: string
{
    use LabeledEnum;

    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Rejected = 'rejected';
    case Approved = 'approved';
    case Published = 'published';
    case OnHold = 'on_hold';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::PendingApproval => 'Aguardando aprovação',
            self::Rejected => 'Não aprovada',
            self::Approved => 'Aprovada',
            self::Published => 'Publicada',
            self::OnHold => 'Suspensa',
            self::Closed => 'Encerrada',
            self::Cancelled => 'Cancelada',
        };
    }

    /** A vaga aceita inscrições e movimentações de candidatos. */
    public function acceptsApplications(): bool
    {
        return in_array($this, [self::Approved, self::Published, self::OnHold], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Closed, self::Cancelled], true);
    }
}

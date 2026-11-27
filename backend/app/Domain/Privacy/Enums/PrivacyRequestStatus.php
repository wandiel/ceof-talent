<?php

namespace App\Domain\Privacy\Enums;

use App\Support\LabeledEnum;

enum PrivacyRequestStatus: string
{
    use LabeledEnum;

    case Open = 'open';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Aberta',
            self::InProgress => 'Em atendimento',
            self::Completed => 'Concluída',
            self::Rejected => 'Indeferida',
        };
    }

    public function isClosed(): bool
    {
        return in_array($this, [self::Completed, self::Rejected], true);
    }
}

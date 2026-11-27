<?php

namespace App\Domain\Intelligence\Enums;

use App\Support\LabeledEnum;

/** Revisão humana de uma sugestão. */
enum SuggestionStatus: string
{
    use LabeledEnum;

    case Pending = 'pending';
    case Accepted = 'accepted';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Aguardando revisão',
            self::Accepted => 'Aceita',
            self::Dismissed => 'Descartada',
        };
    }
}

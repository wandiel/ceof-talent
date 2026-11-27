<?php

namespace App\Domain\Privacy\Enums;

use App\Support\LabeledEnum;

/** Direitos do titular (art. 18 da LGPD). */
enum PrivacyRequestType: string
{
    use LabeledEnum;

    case Access = 'access';
    case Correction = 'correction';
    case Deletion = 'deletion';
    case Portability = 'portability';
    case RevokeConsent = 'revoke_consent';
    case Information = 'information';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Access => 'Confirmação e acesso aos dados',
            self::Correction => 'Correção de dados',
            self::Deletion => 'Eliminação / anonimização',
            self::Portability => 'Portabilidade',
            self::RevokeConsent => 'Revogação de consentimento',
            self::Information => 'Informação sobre compartilhamento',
            self::Other => 'Outro pedido',
        };
    }
}

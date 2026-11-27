<?php

namespace App\Domain\Portal\Enums;

use App\Support\LabeledEnum;

enum ConsentPurpose: string
{
    use LabeledEnum;

    /** Ciência do aviso de privacidade e tratamento para o processo seletivo. */
    case PrivacyNotice = 'privacy_notice';
    /** Manter o currículo no banco de talentos para vagas futuras. */
    case TalentPool = 'talent_pool';

    public function label(): string
    {
        return match ($this) {
            self::PrivacyNotice => 'Ciência do aviso de privacidade',
            self::TalentPool => 'Manter currículo no banco de talentos',
        };
    }
}

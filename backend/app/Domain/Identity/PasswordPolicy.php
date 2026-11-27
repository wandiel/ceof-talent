<?php

namespace App\Domain\Identity;

use Illuminate\Validation\Rules\Password;

/** Política de senha dos usuários internos. */
final class PasswordPolicy
{
    public const MIN_LENGTH = 10;

    public static function rule(): Password
    {
        return Password::min(self::MIN_LENGTH)
            ->letters()
            ->mixedCase()
            ->numbers()
            ->symbols();
    }
}

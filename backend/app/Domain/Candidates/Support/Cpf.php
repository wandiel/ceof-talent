<?php

namespace App\Domain\Candidates\Support;

/** Utilitários de CPF: normalização, validação dos dígitos e hash para duplicidade. */
final class Cpf
{
    public static function normalize(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        return $digits === '' ? null : $digits;
    }

    public static function isValid(?string $value): bool
    {
        $cpf = self::normalize($value);

        if ($cpf === null || strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $cpf[$i] * (($t + 1) - $i);
            }
            $digit = ((10 * $sum) % 11) % 10;
            if ((int) $cpf[$t] !== $digit) {
                return false;
            }
        }

        return true;
    }

    /**
     * HMAC determinístico com a APP_KEY: permite encontrar duplicidades sem
     * guardar o CPF em claro. Trocar a APP_KEY exige recalcular os hashes.
     */
    public static function hash(string $digits): string
    {
        return hash_hmac('sha256', 'cpf:'.$digits, (string) config('app.key'));
    }

    public static function mask(?string $digits): ?string
    {
        if ($digits === null || strlen($digits) !== 11) {
            return null;
        }

        return '***.'.substr($digits, 3, 3).'.'.substr($digits, 6, 3).'-**';
    }

    public static function format(?string $digits): ?string
    {
        if ($digits === null || strlen($digits) !== 11) {
            return $digits;
        }

        return substr($digits, 0, 3).'.'.substr($digits, 3, 3).'.'.substr($digits, 6, 3).'-'.substr($digits, 9, 2);
    }
}

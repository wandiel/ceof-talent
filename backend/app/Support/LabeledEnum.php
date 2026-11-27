<?php

namespace App\Support;

/**
 * Enums com rótulo em português e lista de opções para a interface.
 *
 * @phpstan-require-implements \BackedEnum
 */
trait LabeledEnum
{
    abstract public function label(): string;

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function labelFor(?string $value): ?string
    {
        return $value === null ? null : self::tryFrom($value)?->label();
    }
}

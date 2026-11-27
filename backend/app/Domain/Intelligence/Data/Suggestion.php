<?php

namespace App\Domain\Intelligence\Data;

use App\Domain\Intelligence\Enums\SuggestionType;

/** Uma sugestão (competência, área) com grau de confiança de 0 a 1 e o trecho que a motivou. */
final class Suggestion
{
    public function __construct(
        public readonly SuggestionType $type,
        public readonly string $value,
        public readonly float $confidence,
        public readonly ?string $evidence = null,
    ) {}
}

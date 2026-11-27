<?php

namespace App\Domain\Intelligence\Contracts;

use App\Models\Candidate;

/** Aponta possíveis cadastros duplicados de um candidato. */
interface DuplicateDetector
{
    /** @return list<mixed> */
    public function forCandidate(Candidate $candidate, int $limit = 10): array;
}

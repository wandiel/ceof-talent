<?php

namespace App\Domain\Intelligence\Local;

use App\Domain\Candidates\Search\DuplicateFinder;
use App\Domain\Intelligence\Contracts\DuplicateDetector;
use App\Models\Candidate;

/** Duplicidades pelas regras da Fase 5 (CPF, e-mail, telefone, nome parecido). */
class FinderDuplicateDetector implements DuplicateDetector
{
    public function __construct(private readonly DuplicateFinder $finder) {}

    public function forCandidate(Candidate $candidate, int $limit = 10): array
    {
        return $this->finder->forCandidate($candidate)->take($limit)->values()->all();
    }
}

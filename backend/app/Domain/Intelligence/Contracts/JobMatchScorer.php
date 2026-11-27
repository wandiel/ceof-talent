<?php

namespace App\Domain\Intelligence\Contracts;

use App\Domain\Intelligence\Data\MatchResult;
use App\Models\Candidate;
use App\Models\JobOpening;

/** Compara o perfil do candidato com os requisitos da vaga. */
interface JobMatchScorer
{
    public function score(Candidate $candidate, JobOpening $job): MatchResult;
}

<?php

namespace App\Domain\Intelligence\Contracts;

use App\Domain\Intelligence\Data\Suggestion;
use App\Models\Candidate;

/** Gera sugestões de perfil (competências, área profissional) a partir do texto de um currículo. */
interface CandidateProfileExtractor
{
    /** @return list<Suggestion> */
    public function suggest(string $text, Candidate $candidate): array;
}

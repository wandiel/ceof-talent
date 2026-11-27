<?php

namespace App\Domain\Intelligence\Local;

use App\Domain\Intelligence\Contracts\JobMatchScorer;
use App\Domain\Intelligence\Data\MatchResult;
use App\Domain\Intelligence\Support\Text;
use App\Models\Candidate;
use App\Models\JobOpening;

/**
 * Aderência por palavras: cada requisito da vaga é conferido no perfil do
 * candidato (competências, experiências, formação, cursos, resumo e texto do
 * currículo). Requisitos obrigatórios pesam o dobro. Também confere a
 * escolaridade mínima e a área profissional.
 */
class KeywordJobMatchScorer implements JobMatchScorer
{
    public function score(Candidate $candidate, JobOpening $job): MatchResult
    {
        $candidate->loadMissing(['skills', 'experiences', 'educations', 'courses', 'documents']);
        $corpus = $this->corpus($candidate);
        $stems = array_flip(array_map([Text::class, 'stem'], Text::keywords($corpus)));
        $normalized = Text::normalize($corpus);

        $items = [];
        foreach ($job->requirements as $requirement) {
            $keywords = Text::keywords($requirement->description);
            $hits = array_filter($keywords, fn ($w) => isset($stems[Text::stem($w)]));
            $matched = Text::containsPhrase($normalized, $requirement->description)
                || ($keywords !== [] && count($hits) / count($keywords) >= 0.6);
            $items[] = [
                'label' => $requirement->description,
                'mandatory' => (bool) $requirement->is_mandatory,
                'matched' => $matched,
                'evidence' => $matched && $hits ? 'Encontrado: '.implode(', ', array_slice($hits, 0, 5)) : null,
            ];
        }

        if ($job->education_level_min) {
            $ok = $candidate->education_level && $candidate->education_level->rank() >= $job->education_level_min->rank();
            $items[] = [
                'label' => 'Escolaridade mínima: '.$job->education_level_min->label(),
                'mandatory' => true,
                'matched' => (bool) $ok,
                'evidence' => $candidate->education_level?->label(),
            ];
        }

        $items[] = [
            'label' => 'Área profissional: '.$job->professional_area->label(),
            'mandatory' => false,
            'matched' => $candidate->professional_area === $job->professional_area,
            'evidence' => $candidate->professional_area?->label(),
        ];

        $weight = fn ($i) => $i['mandatory'] ? 2 : 1;
        $total = array_sum(array_map($weight, $items));
        $got = array_sum(array_map(fn ($i) => $i['matched'] ? $weight($i) : 0, $items));

        return new MatchResult($total ? (int) round(100 * $got / $total) : 0, $items, 'keywords');
    }

    private function corpus(Candidate $candidate): string
    {
        return implode("\n", array_filter([
            $candidate->desired_position,
            $candidate->summary,
            $candidate->skills->pluck('name')->implode(', '),
            $candidate->experiences->map(fn ($x) => "{$x->job_title} {$x->company} {$x->description}")->implode("\n"),
            $candidate->educations->map(fn ($e) => "{$e->course} {$e->institution}")->implode("\n"),
            $candidate->courses->map(fn ($c) => "{$c->name} {$c->institution} {$c->credential}")->implode("\n"),
            $candidate->documents->map(fn ($d) => $d->extracted_text)->filter()->implode("\n"),
        ]));
    }
}

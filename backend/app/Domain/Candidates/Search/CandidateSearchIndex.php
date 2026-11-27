<?php

namespace App\Domain\Candidates\Search;

use Illuminate\Support\Facades\DB;

/**
 * Mantém a coluna candidates.search_vector (busca por palavras-chave).
 *
 * O texto é montado a partir do cadastro e das listas relacionadas, sem acentos,
 * com o dicionário "portuguese" (plural e flexões: "enfermeiras" encontra "enfermeira").
 */
class CandidateSearchIndex
{
    public const SQL = <<<'SQL'
        UPDATE candidates c SET search_vector =
            setweight(to_tsvector('portuguese', unaccent(concat_ws(' ', c.full_name, c.social_name, c.desired_position))), 'A')
            || setweight(to_tsvector('portuguese', unaccent(concat_ws(' ',
                (SELECT string_agg(s.name, ' ') FROM candidate_skill cs JOIN skills s ON s.id = cs.skill_id WHERE cs.candidate_id = c.id),
                (SELECT string_agg(x.job_title, ' ') FROM candidate_experiences x WHERE x.candidate_id = c.id),
                (SELECT string_agg(t.name, ' ') FROM candidate_tag ct JOIN tags t ON t.id = ct.tag_id WHERE ct.candidate_id = c.id)
            ))), 'B')
            || setweight(to_tsvector('portuguese', unaccent(concat_ws(' ',
                c.summary,
                (SELECT string_agg(concat_ws(' ', x.company, x.description), ' ') FROM candidate_experiences x WHERE x.candidate_id = c.id),
                (SELECT string_agg(concat_ws(' ', e.course, e.institution), ' ') FROM candidate_educations e WHERE e.candidate_id = c.id),
                (SELECT string_agg(concat_ws(' ', k.name, k.institution, k.credential), ' ') FROM candidate_courses k WHERE k.candidate_id = c.id)
            ))), 'C')
            || setweight(to_tsvector('portuguese', unaccent(concat_ws(' ', c.city, c.neighborhood))), 'D')
        SQL;

    /** @param  int|list<int>  $ids */
    public function refresh(int|array $ids): void
    {
        $ids = array_values(array_unique((array) $ids));
        if ($ids === []) {
            return;
        }

        DB::update(self::SQL.' WHERE c.id IN ('.implode(',', array_fill(0, count($ids), '?')).')', $ids);
    }
}

<?php

namespace App\Domain\Candidates\Search;

use App\Domain\Candidates\Support\Cpf;
use App\Models\Candidate;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Identifica possíveis cadastros duplicados.
 *
 * Critérios (qualquer um basta):
 * - mesmo e-mail ou mesmo CPF (só ocorre ao cadastrar; o banco já impede salvar);
 * - mesmo telefone (principal ou secundário, em qualquer combinação);
 * - nome muito parecido (similaridade ≥ 0,8);
 * - nome parecido (similaridade ≥ 0,55) e mesma data de nascimento.
 *
 * Pares revisados e marcados como "pessoas diferentes" não voltam a aparecer.
 */
class DuplicateFinder
{
    public const STRONG_NAME = 0.8;

    public const WEAK_NAME = 0.55;

    /**
     * Possíveis duplicados de um cadastro (existente ou em digitação).
     *
     * @param  array{full_name?: ?string, email?: ?string, phone?: ?string, phone_secondary?: ?string, cpf?: ?string, birth_date?: ?string}  $data
     * @return Collection<int, array{candidate: Candidate, reasons: list<string>, score: int}>
     */
    public function find(array $data, ?Candidate $ignore = null, int $limit = 10): Collection
    {
        $name = mb_strtolower(trim((string) ($data['full_name'] ?? '')));
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $phones = array_values(array_filter(array_map(fn ($p) => preg_replace('/\D/', '', (string) $p), [$data['phone'] ?? null, $data['phone_secondary'] ?? null])));
        $cpfHash = Cpf::isValid($data['cpf'] ?? null) ? Cpf::hash((string) Cpf::normalize($data['cpf'])) : null;
        $birth = $data['birth_date'] ?? null;

        if ($name === '' && $email === '' && $phones === [] && ! $cpfHash) {
            return collect();
        }

        $query = Candidate::query()
            ->select('candidates.*')
            ->selectRaw('similarity(lower(full_name), ?) AS name_similarity', [$name])
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->id))
            ->where(function ($q) use ($name, $email, $phones, $cpfHash, $birth) {
                if ($email !== '') {
                    $q->orWhere('email', $email);
                }
                if ($cpfHash) {
                    $q->orWhere('cpf_hash', $cpfHash);
                }
                if ($phones !== []) {
                    $q->orWhereIn('phone', $phones)->orWhereIn('phone_secondary', $phones);
                }
                if (mb_strlen($name) >= 5) {
                    $q->orWhere(function ($q) use ($name, $birth) {
                        $q->whereRaw('lower(full_name) % ?', [$name])
                            ->where(function ($q) use ($name, $birth) {
                                $q->whereRaw('similarity(lower(full_name), ?) >= ?', [$name, self::STRONG_NAME]);
                                if ($birth) {
                                    $q->orWhere(fn ($q) => $q->whereRaw('similarity(lower(full_name), ?) >= ?', [$name, self::WEAK_NAME])->whereDate('birth_date', $birth));
                                }
                            });
                    });
                }
            })
            ->limit(50);

        if ($ignore) {
            $query->whereNotExists(fn ($q) => $q->from('candidate_duplicate_dismissals as d')
                ->whereRaw('(d.candidate_id = candidates.id AND d.other_candidate_id = ?) OR (d.other_candidate_id = candidates.id AND d.candidate_id = ?)', [$ignore->id, $ignore->id]));
        }

        return $query->get()
            ->map(function (Candidate $c) use ($email, $phones, $cpfHash, $birth) {
                $reasons = [];
                $score = 0;
                if ($cpfHash && $c->cpf_hash === $cpfHash) {
                    $reasons[] = 'Mesmo CPF';
                    $score += 100;
                }
                if ($email !== '' && mb_strtolower((string) $c->email) === $email) {
                    $reasons[] = 'Mesmo e-mail';
                    $score += 90;
                }
                if ($phones !== [] && array_intersect($phones, array_filter([$c->phone, $c->phone_secondary]))) {
                    $reasons[] = 'Mesmo telefone';
                    $score += 60;
                }
                $similarity = (float) $c->getAttribute('name_similarity');
                if ($similarity >= self::WEAK_NAME) {
                    $reasons[] = $similarity >= 0.99 ? 'Mesmo nome' : 'Nome parecido ('.round($similarity * 100).'%)';
                    $score += (int) round($similarity * 40);
                }
                if ($birth && $c->birth_date?->toDateString() === $birth) {
                    $reasons[] = 'Mesma data de nascimento';
                    $score += 30;
                }

                return ['candidate' => $c, 'reasons' => $reasons, 'score' => $score];
            })
            ->filter(fn ($row) => $row['reasons'] !== [] && $row['reasons'] !== ['Mesma data de nascimento'])
            ->sortByDesc('score')
            ->take($limit)
            ->values();
    }

    /** @return Collection<int, array{candidate: Candidate, reasons: list<string>, score: int}> */
    public function forCandidate(Candidate $candidate): Collection
    {
        return $this->find([
            'full_name' => $candidate->full_name,
            'phone' => $candidate->phone,
            'phone_secondary' => $candidate->phone_secondary,
            'birth_date' => $candidate->birth_date?->toDateString(),
        ], $candidate);
    }

    /**
     * Todos os pares suspeitos do banco, do mais provável para o menos provável.
     *
     * @return LengthAwarePaginator<int, array{a: Candidate, b: Candidate, reasons: list<string>, score: int}>
     */
    public function pairs(int $perPage = 20, int $page = 1): LengthAwarePaginator
    {
        $sql = <<<'SQL'
            SELECT a.id AS a_id, b.id AS b_id,
                   similarity(lower(a.full_name), lower(b.full_name)) AS sim,
                   COALESCE((a.phone IS NOT NULL AND (a.phone = b.phone OR a.phone = b.phone_secondary))
                     OR (a.phone_secondary IS NOT NULL AND (a.phone_secondary = b.phone OR a.phone_secondary = b.phone_secondary)), false) AS same_phone,
                   COALESCE(a.birth_date = b.birth_date, false) AS same_birth
            FROM candidates a
            JOIN candidates b ON b.id > a.id AND b.deleted_at IS NULL
             AND (
                  (a.phone IS NOT NULL AND (a.phone = b.phone OR a.phone = b.phone_secondary))
               OR (a.phone_secondary IS NOT NULL AND (a.phone_secondary = b.phone OR a.phone_secondary = b.phone_secondary))
               OR (lower(a.full_name) %% lower(b.full_name) AND (
                      similarity(lower(a.full_name), lower(b.full_name)) >= %1$s
                   OR (similarity(lower(a.full_name), lower(b.full_name)) >= %2$s AND a.birth_date = b.birth_date)))
             )
            WHERE a.deleted_at IS NULL
              AND NOT EXISTS (SELECT 1 FROM candidate_duplicate_dismissals d WHERE d.candidate_id = a.id AND d.other_candidate_id = b.id)
            SQL;

        // Os limiares são constantes da classe (não vêm do usuário).
        $sql = sprintf($sql, self::STRONG_NAME, self::WEAK_NAME);
        $total = (int) DB::selectOne("SELECT count(*) AS total FROM ($sql) p")->total;
        $rows = DB::select("SELECT * FROM ($sql) p ORDER BY same_phone DESC, (sim + CASE WHEN same_birth THEN 0.3 ELSE 0 END) DESC, a_id LIMIT ? OFFSET ?", [$perPage, ($page - 1) * $perPage]);

        $candidates = Candidate::query()->withCount('documents')->whereKey(collect($rows)->flatMap(fn ($r) => [$r->a_id, $r->b_id])->unique()->all())->get()->keyBy('id');

        $items = collect($rows)->map(function ($r) use ($candidates) {
            $reasons = [];
            if ($r->same_phone) {
                $reasons[] = 'Mesmo telefone';
            }
            if ($r->sim >= self::WEAK_NAME) {
                $reasons[] = $r->sim >= 0.99 ? 'Mesmo nome' : 'Nome parecido ('.round($r->sim * 100).'%)';
            }
            if ($r->same_birth) {
                $reasons[] = 'Mesma data de nascimento';
            }

            return ['a' => $candidates[$r->a_id], 'b' => $candidates[$r->b_id], 'reasons' => $reasons, 'score' => (int) round(($r->same_phone ? 60 : 0) + $r->sim * 40 + ($r->same_birth ? 30 : 0))];
        });

        return new LengthAwarePaginator($items, $total, $perPage, $page);
    }
}

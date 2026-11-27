<?php

namespace App\Http\Controllers\Api\V1\Candidates;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidates\Search\CandidateSearchIndex;
use App\Domain\Candidates\Search\DuplicateFinder;
use App\Http\Controllers\Controller;
use App\Http\Resources\Candidates\CandidateListResource;
use App\Models\Candidate;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Favoritos, etiquetas e duplicidades do banco de currículos. */
class TalentPoolController extends Controller
{
    public function favorite(Request $request, Candidate $candidate): JsonResponse
    {
        $this->authorize('manageTalentPool', $candidate);
        $candidate->favoritedBy()->syncWithoutDetaching([$request->user()->id]);

        return response()->json(['data' => ['is_favorite' => true]]);
    }

    public function unfavorite(Request $request, Candidate $candidate): JsonResponse
    {
        $this->authorize('manageTalentPool', $candidate);
        $candidate->favoritedBy()->detach($request->user()->id);

        return response()->json(['data' => ['is_favorite' => false]]);
    }

    public function syncTags(Request $request, Candidate $candidate, AuditLogger $audit, CandidateSearchIndex $index): JsonResponse
    {
        $this->authorize('manageTalentPool', $candidate);
        $data = $request->validate([
            'tags' => ['present', 'array', 'max:20'],
            'tags.*' => ['uuid', 'distinct', 'exists:tags,uuid'],
        ], [], ['tags' => 'etiquetas', 'tags.*' => 'etiqueta']);

        $tags = Tag::query()->whereIn('uuid', $data['tags'])->get();
        $before = $candidate->tags()->pluck('name')->all();

        DB::transaction(function () use ($candidate, $tags, $request, $before, $audit, $index) {
            $candidate->tags()->sync($tags->mapWithKeys(fn (Tag $t) => [$t->id => ['tagged_by' => $request->user()->id]])->all());
            $after = $tags->pluck('name')->sort()->values()->all();
            $added = array_values(array_diff($after, $before));
            $removed = array_values(array_diff($before, $after));

            if ($added || $removed) {
                $parts = array_filter([
                    $added ? 'adicionou '.implode(', ', $added) : null,
                    $removed ? 'removeu '.implode(', ', $removed) : null,
                ]);
                $audit->log('candidate.tags_changed', $candidate, ucfirst(implode('; ', $parts)).'.', ['tags' => $before], ['tags' => $after]);
                $index->refresh($candidate->id);
            }
        });

        return response()->json(['data' => $candidate->tags()->get()->map(fn (Tag $t) => ['id' => $t->uuid, 'name' => $t->name, 'color' => $t->color])]);
    }

    /** Possíveis duplicados de um candidato já cadastrado. */
    public function candidateDuplicates(Request $request, Candidate $candidate, DuplicateFinder $finder): JsonResponse
    {
        $this->authorize('view', $candidate);
        $this->authorize('reviewDuplicates', Candidate::class);

        return response()->json(['data' => $this->present($finder->forCandidate($candidate), $request)]);
    }

    /** Verificação antes de salvar: o formulário avisa sobre possíveis duplicados. */
    public function check(Request $request, DuplicateFinder $finder): JsonResponse
    {
        $this->authorize('create', Candidate::class);
        $data = $request->validate([
            'full_name' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:30'],
            'phone_secondary' => ['nullable', 'string', 'max:30'],
            'cpf' => ['nullable', 'string', 'max:20'],
            'birth_date' => ['nullable', 'date'],
            'ignore' => ['nullable', 'uuid'],
        ]);

        $ignore = isset($data['ignore']) ? Candidate::query()->where('uuid', $data['ignore'])->first() : null;

        return response()->json(['data' => $this->present($finder->find($data, $ignore, 5), $request)]);
    }

    /** Lista de pares suspeitos para revisão do RH. */
    public function duplicates(Request $request, DuplicateFinder $finder): JsonResponse
    {
        $this->authorize('reviewDuplicates', Candidate::class);
        $page = $finder->pairs(20, max(1, (int) $request->integer('page', 1)));

        return response()->json([
            'data' => collect($page->items())->map(fn ($pair) => [
                'a' => (new CandidateListResource($pair['a']))->toArray($request),
                'b' => (new CandidateListResource($pair['b']))->toArray($request),
                'reasons' => $pair['reasons'],
                'score' => $pair['score'],
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ]);
    }

    /** Marca dois cadastros como pessoas diferentes. */
    public function dismiss(Candidate $candidate, Candidate $other, AuditLogger $audit): JsonResponse
    {
        $this->authorize('update', $candidate);
        $this->authorize('reviewDuplicates', Candidate::class);
        abort_if($candidate->id === $other->id, 422, 'Escolha dois cadastros diferentes.');

        [$first, $second] = $candidate->id < $other->id ? [$candidate, $other] : [$other, $candidate];
        DB::table('candidate_duplicate_dismissals')->insertOrIgnore([
            'candidate_id' => $first->id,
            'other_candidate_id' => $second->id,
            'dismissed_by' => request()->user()->id,
            'created_at' => now(),
        ]);

        foreach ([[$candidate, $other], [$other, $candidate]] as [$a, $b]) {
            $audit->log('candidate.duplicate_dismissed', $a, "Marcado como pessoa diferente de {$b->full_name}.", context: ['other' => $b->uuid]);
        }

        return response()->json(['message' => 'Cadastros marcados como pessoas diferentes.']);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array{candidate: Candidate, reasons: list<string>, score: int}>  $rows
     * @return list<array<string, mixed>>
     */
    private function present($rows, Request $request): array
    {
        return $rows->map(fn ($row) => [
            'candidate' => (new CandidateListResource($row['candidate']))->toArray($request),
            'reasons' => $row['reasons'],
            'score' => $row['score'],
        ])->all();
    }
}

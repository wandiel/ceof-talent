<?php

namespace App\Http\Controllers\Api\V1\Candidates;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidates\Search\CandidateSearchIndex;
use App\Domain\Identity\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Catálogo de etiquetas do banco de talentos. */
class TagController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Catálogo de etiquetas: só para quem trabalha com o banco de talentos (não para entrevistadores).
        abort_unless($request->user()->can('candidates.view') || $request->user()->can('candidates.view_department'), 403);

        $tags = Tag::query()->withCount('candidates')->orderBy('name')->get();

        return response()->json(['data' => $tags->map(fn (Tag $t) => $this->present($t))]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $this->ensureCanManage($request);
        $data = $this->validated($request);

        $tag = Tag::create($data + ['created_by' => $request->user()->id]);
        $audit->log('tag.created', $tag, 'Etiqueta criada: '.$tag->name, new: $data);

        return response()->json(['data' => $this->present($tag->loadCount('candidates'))], 201);
    }

    public function update(Request $request, Tag $tag, AuditLogger $audit, CandidateSearchIndex $index): JsonResponse
    {
        $this->ensureCanManage($request);
        $original = $tag->getAttributes();
        $tag->update($this->validated($request, $tag));
        $audit->logChanges('tag.updated', $tag, $original);

        if ($tag->wasChanged('name')) {
            $index->refresh($tag->candidates()->pluck('candidates.id')->all());
        }

        return response()->json(['data' => $this->present($tag->loadCount('candidates'))]);
    }

    public function destroy(Request $request, Tag $tag, AuditLogger $audit, CandidateSearchIndex $index): Response
    {
        $this->ensureCanManage($request);
        $ids = $tag->candidates()->pluck('candidates.id')->all();

        $tag->delete();
        $audit->log('tag.deleted', null, 'Etiqueta excluída: '.$tag->name, old: ['name' => $tag->name, 'candidates' => count($ids)]);
        $index->refresh($ids);

        return response()->noContent();
    }

    private function ensureCanManage(Request $request): void
    {
        abort_unless($request->user()->can(Permission::TalentPoolManage->value), 403, 'Esta ação não é permitida para o seu perfil.');
    }

    /** @return array{name: string, color: string} */
    private function validated(Request $request, ?Tag $tag = null): array
    {
        $request->merge(['name' => trim(preg_replace('/\s+/u', ' ', (string) $request->input('name')))]);

        return $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:50', Rule::unique('tags', 'name')->ignore($tag?->id)],
            'color' => ['required', Rule::in(Tag::COLORS)],
        ], ['name.unique' => 'Já existe uma etiqueta com este nome.'], ['name' => 'nome', 'color' => 'cor']);
    }

    /** @return array<string, mixed> */
    private function present(Tag $tag): array
    {
        return ['id' => $tag->uuid, 'name' => $tag->name, 'color' => $tag->color, 'candidates_count' => $tag->candidates_count ?? 0];
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Interviews;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\EvaluationCriterion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Catálogo de critérios de avaliação. Consultado por quem vê entrevistas;
 * mantido pelo RH (jobs.manage). Entrevistas guardam uma cópia dos critérios,
 * então alterar ou excluir um critério não muda pareceres já registrados.
 */
class EvaluationCriterionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('interviews.view'), 403);

        return response()->json(['data' => EvaluationCriterion::query()->ordered()->get()->map(fn ($c) => $this->present($c))]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $this->authorizeManage($request);
        $criterion = EvaluationCriterion::create($this->validated($request) + ['sort' => (int) EvaluationCriterion::max('sort') + 1]);
        $audit->log('evaluation_criterion.created', $criterion, "Critério \"{$criterion->name}\" criado.");

        return response()->json(['data' => $this->present($criterion)], 201);
    }

    public function update(Request $request, EvaluationCriterion $criterion, AuditLogger $audit): JsonResponse
    {
        $this->authorizeManage($request);
        $original = $criterion->getAttributes();
        $criterion->update($this->validated($request, $criterion));
        $audit->logChanges('evaluation_criterion.updated', $criterion, $original, "Critério \"{$criterion->name}\" alterado.");

        return response()->json(['data' => $this->present($criterion)]);
    }

    public function destroy(Request $request, EvaluationCriterion $criterion, AuditLogger $audit): Response
    {
        $this->authorizeManage($request);
        $criterion->delete();
        $audit->log('evaluation_criterion.deleted', null, "Critério \"{$criterion->name}\" excluído.");

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?EvaluationCriterion $criterion = null): array
    {
        $request->merge(['name' => is_string($request->input('name')) ? trim($request->input('name')) : $request->input('name')]);

        return $request->validate([
            'name' => ['required', 'string', 'max:100', function ($attr, $value, $fail) use ($criterion) {
                $exists = EvaluationCriterion::query()->whereRaw('lower(name) = lower(?)', [$value])
                    ->when($criterion, fn ($q) => $q->whereKeyNot($criterion->id))->exists();
                if ($exists) {
                    $fail('Já existe um critério com este nome.');
                }
            }],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ], [], ['name' => 'nome', 'description' => 'descrição']);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->can('jobs.manage'), 403, 'Somente o RH pode alterar os critérios de avaliação.');
    }

    /** @return array<string, mixed> */
    private function present(EvaluationCriterion $c): array
    {
        return ['id' => $c->uuid, 'name' => $c->name, 'description' => $c->description, 'is_active' => $c->is_active];
    }
}

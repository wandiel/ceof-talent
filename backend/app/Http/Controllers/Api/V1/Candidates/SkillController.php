<?php

namespace App\Http\Controllers\Api\V1\Candidates;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\Skill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Sugestões de competências já cadastradas (autocompletar do formulário). */
class SkillController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Candidate::class);
        $data = $request->validate(['search' => ['nullable', 'string', 'max:80']]);

        $skills = Skill::query()
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where('name', 'ilike', '%'.addcslashes($s, '%_\\').'%'))
            ->withCount('candidates')
            ->orderByDesc('candidates_count')->orderBy('name')
            ->limit(15)
            ->get(['id', 'name']);

        return response()->json(['data' => $skills->map(fn (Skill $s) => ['name' => $s->name, 'candidates_count' => $s->candidates_count])]);
    }
}

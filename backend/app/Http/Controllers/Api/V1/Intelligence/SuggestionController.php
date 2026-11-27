<?php

namespace App\Http\Controllers\Api\V1\Intelligence;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidates\Enums\ProfessionalArea;
use App\Domain\Candidates\Search\CandidateSearchIndex;
use App\Domain\Intelligence\Contracts\JobMatchScorer;
use App\Domain\Intelligence\Enums\SuggestionStatus;
use App\Domain\Intelligence\Enums\SuggestionType;
use App\Http\Controllers\Controller;
use App\Jobs\ReadCandidateDocument;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\CandidateSuggestion;
use App\Models\Skill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sugestões automáticas (leitura de currículo) e aderência à vaga.
 * A IA só sugere: aceitar ou descartar é sempre uma ação de uma pessoa.
 */
class SuggestionController extends Controller
{
    public function index(Candidate $candidate): JsonResponse
    {
        $this->authorize('view', $candidate);

        $suggestions = $candidate->suggestions()->with(['document:id,uuid,original_name', 'reviewer:id,name'])
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 ELSE 1 END")->orderBy('type')->orderByDesc('confidence')->get();

        return response()->json([
            'data' => $suggestions->map(fn (CandidateSuggestion $s) => $this->present($s)),
            'meta' => [
                'reading' => $candidate->documents()->where('extraction_status', 'pending')->exists(),
                'can_review' => request()->user()->can('update', $candidate),
            ],
        ]);
    }

    public function accept(Request $request, Candidate $candidate, CandidateSuggestion $suggestion, AuditLogger $audit, CandidateSearchIndex $index): JsonResponse
    {
        $this->authorize('update', $candidate);
        $this->ensurePending($candidate, $suggestion);

        DB::transaction(function () use ($request, $candidate, $suggestion, $audit, $index) {
            if ($suggestion->type === SuggestionType::Skill) {
                $skill = Skill::query()->whereRaw('lower(name) = lower(?)', [$suggestion->value])->first() ?? Skill::create(['name' => $suggestion->value]);
                $candidate->skills()->syncWithoutDetaching([$skill->id => ['level' => null]]);
                $text = "Competência \"{$suggestion->value}\" adicionada a partir da leitura do currículo.";
            } else {
                $before = $candidate->professional_area;
                $candidate->professional_area = ProfessionalArea::from($suggestion->value);
                $candidate->updated_by = $request->user()->id;
                $candidate->save();
                $text = 'Área profissional alterada de '.($before?->label() ?? '—').' para '.$candidate->professional_area->label().' a partir da leitura do currículo.';
            }
            $this->review($suggestion, SuggestionStatus::Accepted, $request);
            $audit->log('candidate.suggestion_reviewed', $candidate, $text);
            $index->refresh($candidate->id);
        });

        return response()->json(['data' => $this->present($suggestion->fresh(['document', 'reviewer']))]);
    }

    public function dismiss(Request $request, Candidate $candidate, CandidateSuggestion $suggestion, AuditLogger $audit): JsonResponse
    {
        $this->authorize('update', $candidate);
        $this->ensurePending($candidate, $suggestion);

        $this->review($suggestion, SuggestionStatus::Dismissed, $request);
        $audit->log('candidate.suggestion_reviewed', $candidate, "Sugestão descartada: {$suggestion->type->label()} \"{$this->valueLabel($suggestion)}\".");

        return response()->json(['data' => $this->present($suggestion->fresh(['document', 'reviewer']))]);
    }

    /** Lê de novo um documento (ex.: depois de corrigir o arquivo ou instalar OCR). */
    public function reread(Candidate $candidate, CandidateDocument $document): JsonResponse
    {
        $this->authorize('update', $candidate);
        abort_unless($document->candidate_id === $candidate->id, 404);

        $document->forceFill(['extraction_status' => 'pending'])->save();
        ReadCandidateDocument::dispatch($document->id);

        return response()->json(['message' => 'Leitura do documento iniciada.'], 202);
    }

    /** Aderência do candidato à vaga da inscrição. */
    public function match(Application $application, JobMatchScorer $scorer): JsonResponse
    {
        $this->authorize('viewApplications', $application->jobOpening);
        $job = $application->jobOpening->load('requirements');

        return response()->json(['data' => $scorer->score($application->candidate, $job)->toArray()]);
    }

    private function ensurePending(Candidate $candidate, CandidateSuggestion $suggestion): void
    {
        abort_unless($suggestion->candidate_id === $candidate->id, 404);
        abort_if($suggestion->status !== SuggestionStatus::Pending, 422, 'Esta sugestão já foi revisada.');
    }

    private function review(CandidateSuggestion $suggestion, SuggestionStatus $status, Request $request): void
    {
        $suggestion->status = $status;
        $suggestion->reviewed_by = $request->user()->id;
        $suggestion->reviewed_at = now();
        $suggestion->save();
    }

    private function valueLabel(CandidateSuggestion $s): string
    {
        return $s->type === SuggestionType::ProfessionalArea ? (ProfessionalArea::tryFrom($s->value)?->label() ?? $s->value) : $s->value;
    }

    /** @return array<string, mixed> */
    private function present(CandidateSuggestion $s): array
    {
        return [
            'id' => $s->uuid,
            'type' => $s->type->value,
            'type_label' => $s->type->label(),
            'value' => $s->value,
            'value_label' => $this->valueLabel($s),
            'confidence' => (float) $s->confidence,
            'evidence' => $s->evidence,
            'status' => $s->status->value,
            'status_label' => $s->status->label(),
            'document' => $s->document?->original_name,
            'reviewed_by' => $s->reviewer?->name,
            'reviewed_at' => $s->reviewed_at?->toIso8601String(),
        ];
    }
}

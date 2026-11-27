<?php

namespace App\Domain\Intelligence;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidates\Search\CandidateSearchIndex;
use App\Domain\Intelligence\Contracts\CandidateProfileExtractor;
use App\Domain\Intelligence\Contracts\DocumentTextExtractor;
use App\Domain\Intelligence\Enums\SuggestionStatus;
use App\Models\CandidateDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Lê um documento anexado, guarda o texto (para a busca por palavras-chave) e
 * gera sugestões de perfil para revisão humana. Nada é alterado no cadastro
 * sem que alguém aceite a sugestão.
 */
class DocumentReader
{
    public const STATUS_DONE = 'done';

    public const STATUS_EMPTY = 'empty';

    public const STATUS_UNSUPPORTED = 'unsupported';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        private readonly DocumentTextExtractor $extractor,
        private readonly CandidateProfileExtractor $profiles,
        private readonly CandidateSearchIndex $index,
        private readonly AuditLogger $audit,
    ) {}

    /** @return int quantidade de sugestões novas */
    public function read(CandidateDocument $document): int
    {
        $candidate = $document->candidate;
        if (! $candidate || ! $this->extractor->supports($document->mime_type)) {
            $this->finish($document, self::STATUS_UNSUPPORTED, null, null);

            return 0;
        }

        try {
            $extracted = $this->extractor->extract(Storage::disk(CandidateDocument::DISK)->path($document->path), $document->mime_type);
        } catch (\Throwable $e) {
            Log::warning('Falha ao ler documento', ['document' => $document->uuid, 'error' => $e->getMessage()]);
            $this->finish($document, self::STATUS_FAILED, null, null);

            return 0;
        }

        if ($extracted->isEmpty()) {
            $this->finish($document, self::STATUS_EMPTY, $extracted->method, null);

            return 0;
        }

        return DB::transaction(function () use ($document, $candidate, $extracted) {
            $this->finish($document, self::STATUS_DONE, $extracted->method, $extracted->text);

            $created = 0;
            foreach ($this->profiles->suggest($extracted->text, $candidate) as $suggestion) {
                // Não repete sugestões já revisadas (aceitas ou descartadas).
                $existing = $candidate->suggestions()->where('type', $suggestion->type->value)->where('value', $suggestion->value)->first();
                if ($existing && $existing->status !== SuggestionStatus::Pending) {
                    continue;
                }
                $model = $existing ?? $candidate->suggestions()->make(['type' => $suggestion->type, 'value' => $suggestion->value]);
                $model->fill(['candidate_document_id' => $document->id, 'confidence' => $suggestion->confidence, 'evidence' => $suggestion->evidence]);
                $model->status ??= SuggestionStatus::Pending;
                $created += $model->exists ? 0 : 1;
                $model->save();
            }

            $this->index->refresh($candidate->id);
            $this->audit->log('candidate.document_read', $candidate, "Leitura automática de {$document->original_name}: {$created} sugestão(ões) para revisão.", context: ['document' => $document->uuid, 'method' => $extracted->method], userId: null);

            return $created;
        });
    }

    private function finish(CandidateDocument $document, string $status, ?string $method, ?string $text): void
    {
        $document->forceFill([
            'extraction_status' => $status,
            'extraction_method' => $method,
            'extracted_text' => $text,
            'extracted_at' => now(),
        ])->save();
    }
}

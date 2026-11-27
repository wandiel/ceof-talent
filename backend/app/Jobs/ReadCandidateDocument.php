<?php

namespace App\Jobs;

use App\Domain\Intelligence\DocumentReader;
use App\Models\CandidateDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Leitura do currículo em segundo plano (PDF/DOCX/OCR pode levar alguns segundos). */
class ReadCandidateDocument implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public readonly int $documentId) {}

    public function uniqueId(): string
    {
        return (string) $this->documentId;
    }

    public function handle(DocumentReader $reader): void
    {
        $document = CandidateDocument::query()->with('candidate')->find($this->documentId);
        if ($document) {
            $reader->read($document);
        }
    }
}

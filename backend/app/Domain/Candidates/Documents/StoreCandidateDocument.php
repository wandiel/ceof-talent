<?php

namespace App\Domain\Candidates\Documents;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidates\Enums\DocumentType;
use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\User;
use App\Jobs\ReadCandidateDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Guarda um anexo do candidato no disco privado, com nome aleatório. */
class StoreCandidateDocument
{
    public const MAX_PER_CANDIDATE = 20;

    public function __construct(
        private readonly UploadInspector $inspector,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Candidate $candidate, UploadedFile $file, DocumentType $type, ?User $actor): CandidateDocument
    {
        $info = $this->inspector->inspect($file);
        $sha256 = hash_file('sha256', $file->getRealPath());

        if ($candidate->documents()->count() >= self::MAX_PER_CANDIDATE) {
            throw ValidationException::withMessages(['file' => 'Limite de '.self::MAX_PER_CANDIDATE.' arquivos por candidato atingido.']);
        }

        if ($candidate->documents()->where('sha256', $sha256)->exists()) {
            throw ValidationException::withMessages(['file' => 'Este arquivo já foi anexado a este candidato.']);
        }

        $path = 'candidates/'.$candidate->uuid.'/'.Str::random(40).'.'.$info['extension'];
        Storage::disk(CandidateDocument::DISK)->putFileAs(dirname($path), $file, basename($path));

        try {
            return DB::transaction(function () use ($candidate, $file, $type, $actor, $info, $sha256, $path) {
                $document = $candidate->documents()->create([
                    'type' => $type,
                    'original_name' => $this->safeName($file->getClientOriginalName(), $info['extension']),
                    'path' => $path,
                    'mime_type' => $info['mime'],
                    'size' => $file->getSize(),
                    'sha256' => $sha256,
                    'uploaded_by' => $actor?->id,
                ]);

                $this->audit->log('candidate.document_uploaded', $candidate, 'Anexou '.$type->label().': '.$document->original_name, context: ['document' => $document->uuid]);

                // Currículos são lidos em segundo plano para busca e sugestões (Fase 7.9).
                if ($type === DocumentType::Resume) {
                    $document->extraction_status = 'pending';
                    $document->save();
                    ReadCandidateDocument::dispatch($document->id)->afterCommit();
                }

                return $document;
            });
        } catch (\Throwable $e) {
            Storage::disk(CandidateDocument::DISK)->delete($path);
            throw $e;
        }
    }

    /** Remove caminhos e caracteres de controle do nome original (exibido e usado no download). */
    private function safeName(string $name, string $extension): string
    {
        $base = pathinfo(str_replace('\\', '/', $name), PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[\x00-\x1F\x7F"\/\\\\<>:|?*]+/u', ' ', $base));
        $base = Str::limit($base !== '' ? $base : 'documento', 120, '');

        return $base.'.'.$extension;
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Candidates;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidates\Documents\StoreCandidateDocument;
use App\Domain\Candidates\Documents\UploadInspector;
use App\Domain\Candidates\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Candidates\CandidateDocumentResource;
use App\Models\Candidate;
use App\Models\CandidateDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CandidateDocumentController extends Controller
{
    public function store(Request $request, Candidate $candidate, StoreCandidateDocument $store): JsonResponse
    {
        $this->authorize('update', $candidate);

        $data = $request->validate([
            'type' => ['required', Rule::enum(DocumentType::class)],
            'file' => ['required', 'file', 'max:'.UploadInspector::MAX_KILOBYTES],
        ], [
            'file.max' => 'O arquivo deve ter no máximo 10 MB.',
            'file.uploaded' => 'Não foi possível receber o arquivo. Verifique se ele tem no máximo 10 MB.',
        ], ['file' => 'arquivo', 'type' => 'tipo de documento']);

        $document = $store->handle($candidate, $data['file'], DocumentType::from($data['type']), $request->user());

        return (new CandidateDocumentResource($document->load('uploader')))->response()->setStatusCode(201);
    }

    public function download(Candidate $candidate, CandidateDocument $document, AuditLogger $audit): StreamedResponse
    {
        $this->ensureBelongs($candidate, $document);
        $this->authorize('downloadDocuments', $candidate);

        $disk = CandidateDocument::storage();
        abort_unless($disk->exists($document->path), 404, 'Arquivo não encontrado no armazenamento.');

        $audit->log('candidate.document_downloaded', $candidate, 'Baixou '.$document->type->label().': '.$document->original_name, context: ['document' => $document->uuid]);

        return $disk->download($document->path, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function destroy(Candidate $candidate, CandidateDocument $document, AuditLogger $audit): Response
    {
        $this->ensureBelongs($candidate, $document);
        $this->authorize('update', $candidate);

        $document->delete();
        CandidateDocument::storage()->delete($document->path);
        $audit->log('candidate.document_deleted', $candidate, 'Removeu '.$document->type->label().': '.$document->original_name, context: ['document' => $document->uuid]);

        return response()->noContent();
    }

    private function ensureBelongs(Candidate $candidate, CandidateDocument $document): void
    {
        abort_unless($document->candidate_id === $candidate->id, 404);
    }
}

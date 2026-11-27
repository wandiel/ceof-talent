<?php

namespace Tests\Feature\Infrastructure;

use App\Domain\Identity\Enums\Role;
use App\Models\Candidate;
use App\Models\CandidateDocument;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Support\Files;
use Tests\TestCase;

/** Fase 12: documentos em bucket S3 (FILESYSTEM_DISK=s3) funcionam como no disco local. */
class S3DocumentStorageTest extends TestCase
{
    use InteractsWithIdentity;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.documents' => 's3']);
        Storage::fake('s3');
        Storage::fake('local');
    }

    public function test_upload_reading_download_and_deletion_use_the_bucket(): void
    {
        $this->assertSame('s3', CandidateDocument::diskName());
        $candidate = Candidate::factory()->create();
        $this->actingAsRole(Role::HrManager);

        $this->post("/api/v1/candidates/{$candidate->uuid}/documents", [
            'file' => Files::pdfWithText(['Técnica de enfermagem com experiência em centro cirúrgico e Tasy.']),
            'type' => 'resume',
        ], ['Accept' => 'application/json'])->assertCreated();

        $document = $candidate->documents()->firstOrFail();
        Storage::disk('s3')->assertExists($document->path);
        $this->assertSame([], Storage::disk('local')->allFiles(), 'Nada deve ir para o disco local.');

        // A leitura automática baixa uma cópia temporária do bucket.
        $this->assertSame('done', $document->extraction_status);
        $this->assertStringContainsString('centro cirúrgico', $document->extracted_text);

        $download = $this->get("/api/v1/candidates/{$candidate->uuid}/documents/{$document->uuid}");
        $download->assertOk();
        $this->assertSame($document->sha256, hash('sha256', $download->streamedContent()));

        $this->deleteJson("/api/v1/candidates/{$candidate->uuid}/documents/{$document->uuid}")->assertNoContent();
        Storage::disk('s3')->assertMissing($document->path);
    }

    public function test_health_check_uses_the_documents_bucket(): void
    {
        $this->getJson('/api/health')->assertOk()->assertJsonPath('checks.storage.status', 'ok');
    }
}

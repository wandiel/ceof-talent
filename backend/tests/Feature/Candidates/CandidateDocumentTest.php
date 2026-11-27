<?php

namespace Tests\Feature\Candidates;

use App\Domain\Identity\Enums\Role;
use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\Department;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;
use ZipArchive;

class CandidateDocumentTest extends TestCase
{
    use InteractsWithIdentity;

    private Candidate $candidate;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(CandidateDocument::DISK);
        $this->candidate = Candidate::factory()->create();
    }

    private function pdf(string $name = 'curriculo.pdf', string $extra = ''): UploadedFile
    {
        $content = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n{$extra}\ntrailer << /Root 1 0 R >>\n%%EOF\n";

        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function docx(bool $withMacro = false): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>');
        if ($withMacro) {
            $zip->addFromString('word/vbaProject.bin', 'macro');
        }
        $zip->close();

        return UploadedFile::fake()->createWithContent('curriculo.docx', (string) file_get_contents($path));
    }

    private function upload(UploadedFile $file, string $type = 'resume')
    {
        return $this->post("/api/v1/candidates/{$this->candidate->uuid}/documents", ['type' => $type, 'file' => $file], ['Accept' => 'application/json']);
    }

    public function test_upload_download_and_delete(): void
    {
        $this->actingAsRole(Role::Recruiter);

        $id = $this->upload($this->pdf('../../Currículo Maria<>.pdf'))
            ->assertCreated()
            ->assertJsonPath('data.type_label', 'Currículo')
            ->assertJsonPath('data.original_name', 'Currículo Maria.pdf')
            ->assertJsonPath('data.mime_type', 'application/pdf')
            ->json('data.id');

        $document = CandidateDocument::query()->where('uuid', $id)->firstOrFail();
        $this->assertStringStartsWith('candidates/'.$this->candidate->uuid.'/', $document->path);
        $this->assertStringNotContainsString('Maria', $document->path);
        Storage::disk(CandidateDocument::DISK)->assertExists($document->path);

        $download = $this->get("/api/v1/candidates/{$this->candidate->uuid}/documents/{$id}")->assertOk();
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $download->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('%PDF-', $download->streamedContent());
        $this->assertDatabaseHas('audit_logs', ['event' => 'candidate.document_downloaded']);

        $this->deleteJson("/api/v1/candidates/{$this->candidate->uuid}/documents/{$id}")->assertNoContent();
        Storage::disk(CandidateDocument::DISK)->assertMissing($document->path);
        $this->assertDatabaseHas('audit_logs', ['event' => 'candidate.document_deleted']);
    }

    public function test_valid_docx_and_images_are_accepted(): void
    {
        $this->actingAsRole(Role::HrAnalyst);

        $this->upload($this->docx())->assertCreated()
            ->assertJsonPath('data.mime_type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $this->upload(UploadedFile::fake()->image('coren.png'), 'professional_registry')->assertCreated();
    }

    public function test_malicious_or_invalid_files_are_rejected(): void
    {
        $this->actingAsRole(Role::HrAnalyst);

        $this->upload(UploadedFile::fake()->createWithContent('virus.exe', 'MZ binary'))
            ->assertUnprocessable()->assertJsonPath('errors.file.0', 'Formato não permitido. Envie PDF, DOC, DOCX, ODT, JPG ou PNG.');

        // Executável renomeado para .pdf: o conteúdo não corresponde à extensão.
        $this->upload(UploadedFile::fake()->createWithContent('curriculo.pdf', "MZ\x90\x00\x03\x00\x00\x00 executable"))
            ->assertUnprocessable()->assertJsonPath('errors.file.0', 'O conteúdo do arquivo não corresponde à extensão .pdf.');

        $this->upload($this->pdf('script.pdf', '2 0 obj << /S /JavaScript /JS (app.alert(1)) >> endobj'))
            ->assertUnprocessable()->assertJsonPath('errors.file.0', 'O PDF contém conteúdo ativo (scripts ou arquivos embutidos) e foi bloqueado.');

        $this->upload($this->docx(withMacro: true))
            ->assertUnprocessable()->assertJsonPath('errors.file.0', 'Documentos com macros não são aceitos.');

        $this->upload(UploadedFile::fake()->create('grande.pdf', 11 * 1024, 'application/pdf'))
            ->assertUnprocessable()->assertJsonPath('errors.file.0', 'O arquivo deve ter no máximo 10 MB.');

        $this->assertSame(0, CandidateDocument::query()->count());
        $this->assertSame([], Storage::disk(CandidateDocument::DISK)->allFiles());
    }

    public function test_same_file_cannot_be_attached_twice(): void
    {
        $this->actingAsRole(Role::HrAnalyst);
        $this->upload($this->pdf())->assertCreated();
        $this->upload($this->pdf('outro-nome.pdf'))->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'Este arquivo já foi anexado a este candidato.');
    }

    public function test_document_of_another_candidate_is_not_found(): void
    {
        $this->actingAsRole(Role::HrAnalyst);
        $id = $this->upload($this->pdf())->json('data.id');
        $other = Candidate::factory()->create();

        $this->get("/api/v1/candidates/{$other->uuid}/documents/{$id}")->assertNotFound();
    }

    /** Cria um anexo direto no banco (trocar de usuário no meio do teste não funciona com a sessão). */
    private function storedDocument(): CandidateDocument
    {
        $path = 'candidates/'.$this->candidate->uuid.'/arquivo.pdf';
        Storage::disk(CandidateDocument::DISK)->put($path, '%PDF-1.4');

        return $this->candidate->documents()->create([
            'type' => 'resume', 'original_name' => 'cv.pdf', 'path' => $path,
            'mime_type' => 'application/pdf', 'size' => 8, 'sha256' => str_repeat('a', 64),
        ]);
    }

    public function test_department_manager_cannot_download_or_upload(): void
    {
        $document = $this->storedDocument();
        $this->actingAsRole(Role::DepartmentManager, ['department_id' => Department::factory()->create()->id]);

        $this->get("/api/v1/candidates/{$this->candidate->uuid}/documents/{$document->uuid}")->assertForbidden();
        $this->upload($this->pdf('x.pdf'))->assertForbidden();
    }

    public function test_interviewer_cannot_download(): void
    {
        $document = $this->storedDocument();
        $this->actingAsRole(Role::Interviewer);

        $this->get("/api/v1/candidates/{$this->candidate->uuid}/documents/{$document->uuid}")->assertForbidden();
    }
}

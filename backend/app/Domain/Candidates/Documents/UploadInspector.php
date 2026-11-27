<?php

namespace App\Domain\Candidates\Documents;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Valida o conteúdo real de um arquivo enviado, além da extensão.
 *
 * - O tipo MIME é detectado pelo conteúdo (fileinfo) e precisa corresponder à extensão.
 * - DOCX precisa ser um pacote Word válido e não pode conter macros.
 * - PDF não pode conter JavaScript nem ações de execução automática.
 */
class UploadInspector
{
    public const MAX_KILOBYTES = 10 * 1024;

    /** Extensão permitida => tipos MIME aceitos para ela. */
    public const ALLOWED = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/vnd.ms-office', 'application/cdfv2'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'odt' => ['application/vnd.oasis.opendocument.text'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
    ];

    /** @return array{extension: string, mime: string} */
    public function inspect(UploadedFile $file, string $field = 'file'): array
    {
        $extension = mb_strtolower($file->getClientOriginalExtension());
        $mime = mb_strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()));

        if (! array_key_exists($extension, self::ALLOWED)) {
            $this->fail($field, 'Formato não permitido. Envie PDF, DOC, DOCX, ODT, JPG ou PNG.');
        }

        if (! in_array($mime, self::ALLOWED[$extension], true)) {
            $this->fail($field, 'O conteúdo do arquivo não corresponde à extensão .'.$extension.'.');
        }

        match ($extension) {
            'docx' => $this->inspectDocx($file, $field),
            'pdf' => $this->inspectPdf($file, $field),
            default => null,
        };

        $canonical = match ($extension) {
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'doc' => 'application/msword',
            default => $mime,
        };

        return ['extension' => $extension === 'jpeg' ? 'jpg' : $extension, 'mime' => $canonical];
    }

    private function inspectDocx(UploadedFile $file, string $field): void
    {
        $zip = new ZipArchive;
        if ($zip->open($file->getRealPath(), ZipArchive::RDONLY) !== true) {
            $this->fail($field, 'O arquivo DOCX está corrompido.');
        }

        try {
            if ($zip->locateName('word/document.xml') === false || $zip->locateName('[Content_Types].xml') === false) {
                $this->fail($field, 'O arquivo não é um documento do Word válido.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (str_contains(mb_strtolower((string) $zip->getNameIndex($i)), 'vbaproject.bin')) {
                    $this->fail($field, 'Documentos com macros não são aceitos.');
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function inspectPdf(UploadedFile $file, string $field): void
    {
        $content = (string) file_get_contents($file->getRealPath());

        if (! str_starts_with(ltrim(substr($content, 0, 1024)), '%PDF-')) {
            $this->fail($field, 'O arquivo PDF está corrompido.');
        }

        if (preg_match('#/(JavaScript|JS|Launch|EmbeddedFile)\b#', $content)) {
            $this->fail($field, 'O PDF contém conteúdo ativo (scripts ou arquivos embutidos) e foi bloqueado.');
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

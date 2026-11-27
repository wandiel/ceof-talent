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
 * - PDF não pode conter JavaScript, ações de execução automática nem arquivos anexados
 *   (exceto o selo de procedência C2PA, que é só metadado).
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

        $scan = $this->pdfStructure($content);

        if (preg_match('#/(JavaScript|JS|Launch)(?![A-Za-z0-9])#', $scan)) {
            $this->fail($field, 'O PDF contém scripts ou ações automáticas e foi bloqueado. Salve o currículo novamente como PDF (por exemplo, em "Imprimir > Salvar como PDF") e envie de novo.');
        }
        if ($this->hasEmbeddedFiles($scan)) {
            $this->fail($field, 'O PDF contém outros arquivos anexados dentro dele e foi bloqueado. Salve o currículo novamente como PDF (por exemplo, em "Imprimir > Salvar como PDF") e envie de novo.');
        }
    }

    /**
     * Arquivos anexados dentro do PDF. O único tipo aceito é o selo de procedência
     * "Content Credentials" (C2PA, application/c2pa), que editores e ferramentas de IA
     * incluem automaticamente: são só metadados assinados, sem conteúdo executável.
     */
    private function hasEmbeddedFiles(string $scan): bool
    {
        $streams = preg_match_all('#/Type\s*/EmbeddedFile(?![A-Za-z0-9])#', $scan, $m, PREG_OFFSET_CAPTURE);
        if (! preg_match('#/EmbeddedFiles?(?![A-Za-z0-9])#', $scan)) {
            return false;
        }
        if ($streams === 0) {
            return true; // Referência a anexo sem o anexo visível: não dá para confirmar o tipo.
        }
        foreach ($m[0] as [, $offset]) {
            $open = strrpos(substr($scan, 0, $offset), '<<');
            $close = strpos($scan, '>>', $offset);
            $dict = substr($scan, $open === false ? $offset : $open, ($close === false ? $offset + 200 : $close) - ($open === false ? $offset : $open));
            if (! preg_match('#/Subtype\s*/application/c2pa(?![A-Za-z0-9])#i', $dict)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Estrutura do PDF (dicionários e objetos), sem o conteúdo binário das páginas, imagens e
     * fontes — senão bytes aleatórios comprimidos podem parecer "/JS" e bloquear um PDF comum.
     * Fluxos de objetos (/ObjStm) guardam dicionários comprimidos: esses são descomprimidos e
     * verificados também. Nomes com escape (#4A#53 = JS) são decodificados.
     */
    private function pdfStructure(string $content): string
    {
        $parts = [];
        $offset = 0;
        while (preg_match('#stream\r?\n#', $content, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $m[0][1];
            $dataStart = $start + strlen($m[0][0]);
            $end = strpos($content, 'endstream', $dataStart);
            if ($end === false) {
                break;
            }
            $before = substr($content, $offset, $start - $offset);
            $parts[] = $before;

            $dict = substr($before, max(0, strrpos($before, ' obj') ?: 0));
            if (str_contains($dict, '/ObjStm') && str_contains($dict, '/FlateDecode')) {
                $inflated = @gzuncompress(rtrim(substr($content, $dataStart, $end - $dataStart), "\r\n"), 20 * 1024 * 1024);
                if ($inflated === false) {
                    $inflated = @gzinflate(substr($content, $dataStart + 2, $end - $dataStart - 2), 20 * 1024 * 1024);
                }
                $parts[] = $inflated === false ? '' : $inflated;
            }
            $offset = $end + strlen('endstream');
        }
        $parts[] = substr($content, $offset);

        return (string) preg_replace_callback(
            '#/[^\s/<>\[\]()]+#',
            fn ($n) => preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn ($h) => chr(hexdec($h[1])), $n[0]),
            implode("\n", $parts),
        );
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

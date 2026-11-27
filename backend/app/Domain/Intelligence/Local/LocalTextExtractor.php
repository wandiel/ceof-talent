<?php

namespace App\Domain\Intelligence\Local;

use App\Domain\Intelligence\Contracts\DocumentTextExtractor;
use App\Domain\Intelligence\Data\ExtractedText;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Leitura local, sem enviar dados para fora do servidor:
 * - PDF com texto: pdftotext (poppler-utils);
 * - PDF digitalizado (sem texto): pdftoppm + OCR Tesseract em português, até 5 páginas;
 * - DOCX e ODT: XML interno do arquivo;
 * - JPG e PNG: OCR Tesseract.
 */
class LocalTextExtractor implements DocumentTextExtractor
{
    private const MAX_CHARS = 200_000;

    private const OCR_PAGES = 5;

    public function supports(string $mime): bool
    {
        return in_array($mime, [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.oasis.opendocument.text',
            'image/jpeg', 'image/png',
        ], true);
    }

    public function extract(string $absolutePath, string $mime): ExtractedText
    {
        $result = match ($mime) {
            'application/pdf' => $this->pdf($absolutePath),
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => new ExtractedText($this->zipXml($absolutePath, 'word/document.xml', 'w:p'), ExtractedText::DOCX),
            'application/vnd.oasis.opendocument.text' => new ExtractedText($this->zipXml($absolutePath, 'content.xml', 'text:p'), ExtractedText::ODT),
            'image/jpeg', 'image/png' => new ExtractedText($this->ocrImage($absolutePath), ExtractedText::OCR),
            default => throw new \RuntimeException("Formato não suportado: {$mime}"),
        };

        return new ExtractedText(Str::limit($this->clean($result->text), self::MAX_CHARS, ''), $result->method);
    }

    private function pdf(string $path): ExtractedText
    {
        $run = Process::timeout(60)->run(['pdftotext', '-layout', '-enc', 'UTF-8', '-q', $path, '-']);
        if (! $run->successful()) {
            throw new \RuntimeException('Não foi possível ler o PDF.');
        }
        $text = new ExtractedText($run->output(), ExtractedText::PDF_TEXT);
        if (! $text->isEmpty()) {
            return $text;
        }

        // PDF sem camada de texto (digitalizado): converte as páginas em imagem e aplica OCR.
        $dir = storage_path('app/tmp/ocr-'.Str::random(16));
        File::ensureDirectoryExists($dir);
        try {
            $render = Process::timeout(120)->run(['pdftoppm', '-r', '200', '-gray', '-png', '-l', (string) self::OCR_PAGES, $path, $dir.'/page']);
            if (! $render->successful()) {
                throw new \RuntimeException('Não foi possível converter o PDF para OCR.');
            }
            $pages = collect(File::files($dir))->sortBy(fn ($f) => $f->getFilename())->map(fn ($f) => $this->ocrImage($f->getPathname()));

            return new ExtractedText($pages->implode("\n\n"), ExtractedText::OCR);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    private function ocrImage(string $path): string
    {
        $run = Process::timeout(120)->run(['tesseract', $path, 'stdout', '-l', 'por', '--psm', '3']);
        if (! $run->successful()) {
            throw new \RuntimeException('O OCR não conseguiu ler a imagem.');
        }

        return $run->output();
    }

    /** Lê o XML principal de um DOCX/ODT, uma linha por parágrafo, sem executar nada do arquivo. */
    private function zipXml(string $path, string $entry, string $paragraphTag): string
    {
        $zip = new \ZipArchive;
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('Arquivo compactado inválido.');
        }
        $stat = $zip->statName($entry);
        if ($stat === false || $stat['size'] > 20 * 1024 * 1024) {
            $zip->close();
            throw new \RuntimeException('Conteúdo do documento não encontrado.');
        }
        $xml = (string) $zip->getFromName($entry);
        $zip->close();

        $xml = preg_replace('#</'.preg_quote($paragraphTag, '#').'>#', "\n", $xml);
        $xml = preg_replace('#<(w:tab|text:tab|w:br|text:line-break)\b[^>]*/>#', ' ', (string) $xml);

        return html_entity_decode(strip_tags((string) $xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function clean(string $text): string
    {
        $text = (string) preg_replace('/[^\P{C}\n\t]/u', ' ', $text);
        $text = (string) preg_replace('/[ \t]+/u', ' ', $text);

        return trim((string) preg_replace('/\n{3,}/u', "\n\n", $text));
    }
}

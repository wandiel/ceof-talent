<?php

namespace App\Domain\Intelligence\Contracts;

use App\Domain\Intelligence\Data\ExtractedText;

/** Lê o texto de um arquivo (PDF, DOCX, ODT, imagem). Implementações trocáveis por serviços externos. */
interface DocumentTextExtractor
{
    public function supports(string $mime): bool;

    /** @throws \RuntimeException quando o arquivo não pode ser lido */
    public function extract(string $absolutePath, string $mime): ExtractedText;
}

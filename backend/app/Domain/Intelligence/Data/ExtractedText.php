<?php

namespace App\Domain\Intelligence\Data;

/** Texto lido de um documento e como foi obtido. */
final class ExtractedText
{
    public const PDF_TEXT = 'pdf_text';

    public const DOCX = 'docx';

    public const ODT = 'odt';

    public const OCR = 'ocr';

    public function __construct(public readonly string $text, public readonly string $method) {}

    public function isEmpty(): bool
    {
        return mb_strlen((string) preg_replace('/\s+/u', '', $this->text)) < 30;
    }
}

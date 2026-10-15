<?php

namespace App\Domain\Documents\Contracts;

/**
 * DOCX → PDF (§12.4). Standaard via Gotenberg (LibreOffice-route) in een aparte container.
 */
interface PdfConverter
{
    /**
     * @throws \RuntimeException als de conversie niet lukt
     */
    public function convert(string $docxPath, string $pdfPath): string;

    public function isAvailable(): bool;
}

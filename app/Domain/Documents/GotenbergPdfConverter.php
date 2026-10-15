<?php

namespace App\Domain\Documents;

use App\Domain\Documents\Contracts\PdfConverter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GotenbergPdfConverter implements PdfConverter
{
    public function __construct(private readonly string $baseUrl) {}

    public function convert(string $docxPath, string $pdfPath): string
    {
        try {
            $response = Http::timeout(120)
                ->attach('files', (string) file_get_contents($docxPath), basename($docxPath))
                ->post(rtrim($this->baseUrl, '/').'/forms/libreoffice/convert');
        } catch (ConnectionException) {
            throw new RuntimeException('Gotenberg is niet bereikbaar op '.$this->baseUrl.'. Start de container (docker compose up gotenberg).');
        }

        if (! $response->successful()) {
            throw new RuntimeException('PDF-conversie mislukt (HTTP '.$response->status().').');
        }

        if (! is_dir(dirname($pdfPath))) {
            mkdir(dirname($pdfPath), 0775, true);
        }

        file_put_contents($pdfPath, $response->body());

        return $pdfPath;
    }

    public function isAvailable(): bool
    {
        try {
            return Http::timeout(2)->get(rtrim($this->baseUrl, '/').'/health')->successful();
        } catch (ConnectionException) {
            return false;
        }
    }
}

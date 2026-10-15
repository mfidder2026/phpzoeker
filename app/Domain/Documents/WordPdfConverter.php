<?php

namespace App\Domain\Documents;

use App\Domain\Documents\Contracts\PdfConverter;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * PDF via Microsoft Word (Windows, COM-automatisering via PowerShell). Voor een
 * ontwikkelmachine zonder Docker/Gotenberg (D3); geeft dezelfde opmaak als Word zelf.
 */
class WordPdfConverter implements PdfConverter
{
    public function convert(string $docxPath, string $pdfPath): string
    {
        if (! is_dir(dirname($pdfPath))) {
            mkdir(dirname($pdfPath), 0775, true);
        }

        $in = str_replace("'", "''", (string) realpath($docxPath));
        $out = str_replace("'", "''", str_replace('/', '\\', $pdfPath));

        $script = <<<PS
            \$ErrorActionPreference = 'Stop'
            \$word = New-Object -ComObject Word.Application
            \$word.Visible = \$false
            \$word.DisplayAlerts = 0
            try {
                \$doc = \$word.Documents.Open('{$in}', \$false, \$true)
                \$doc.ExportAsFixedFormat('{$out}', 17)
                \$doc.Close(0)
            } finally {
                \$word.Quit()
                [System.Runtime.InteropServices.Marshal]::ReleaseComObject(\$word) | Out-Null
            }
            PS;

        $result = Process::timeout(180)->run(['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', $script]);

        if (! $result->successful() || ! is_file($pdfPath)) {
            throw new RuntimeException('PDF maken via Word is mislukt: '.trim($result->errorOutput() ?: $result->output()));
        }

        return $pdfPath;
    }

    public function isAvailable(): bool
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return false;
        }

        $result = Process::timeout(10)->run(['reg', 'query', 'HKLM\\SOFTWARE\\Classes\\Word.Application\\CurVer']);

        return $result->successful();
    }
}

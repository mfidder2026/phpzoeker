<?php

namespace App\Ai\Support;

use App\Domain\Candidates\Models\Candidate;
use Carbon\CarbonImmutable;

/**
 * Dataminimalisatie (§19.2, R4): geboortedatum, telefoonnummer en e-mailadres gaan nooit
 * naar het taalmodel. Wordt toegepast op elke tekst over een kandidaat vóór een AI-aanroep.
 */
class PiiScrubber
{
    public function scrub(string $text, ?Candidate $candidate = null): string
    {
        // Regels die expliciet over geboorte gaan, volledig weglaten.
        $text = (string) preg_replace('/^.*\b(geboren|geboortedatum|geb\.?\s*datum|date of birth|birth\s*date|born)\b.*$/imu', '[geboortedatum verwijderd]', $text);

        if ($candidate?->birth_date !== null) {
            foreach ($this->dateVariants($candidate->birth_date) as $variant) {
                $text = str_ireplace($variant, '[datum verwijderd]', $text);
            }
        }

        $text = (string) preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[e-mail verwijderd]', $text);

        // Nederlandse en internationale telefoonnummers (06-12345678, +31 6 1234 5678, 010-1234567).
        $text = (string) preg_replace('/(?<![\w-])(?:\+31|0031|0)[\s\-.]?(?:\(0\))?(?:\d[\s\-.]?){8,10}\d?(?![\w-])/u', '[telefoon verwijderd]', $text);

        if ($candidate?->phone !== null && $candidate->phone !== '') {
            $text = str_replace($candidate->phone, '[telefoon verwijderd]', $text);
        }

        return $text;
    }

    /**
     * @return list<string>
     */
    private function dateVariants(string $isoDate): array
    {
        try {
            $dutch = CarbonImmutable::parse($isoDate)->settings(['locale' => 'nl']);
            $english = CarbonImmutable::parse($isoDate)->settings(['locale' => 'en']);
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_unique([
            $dutch->format('Y-m-d'),
            $dutch->format('d-m-Y'),
            $dutch->format('j-n-Y'),
            $dutch->format('d/m/Y'),
            $dutch->format('j/n/Y'),
            $dutch->format('d.m.Y'),
            $dutch->translatedFormat('j F Y'),
            $dutch->translatedFormat('d F Y'),
            $english->translatedFormat('j F Y'),
            $english->translatedFormat('F j, Y'),
        ]));
    }
}

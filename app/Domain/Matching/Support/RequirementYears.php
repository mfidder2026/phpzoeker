<?php

namespace App\Domain\Matching\Support;

/**
 * Leest uit een eis hoeveel jaar ervaring met welke technologie gevraagd wordt, bijv.
 * "Minimaal 5 jaar ervaring met Laravel" → 5 jaar Laravel (§10.4, "eerlijk rekenen met jaren").
 */
final class RequirementYears
{
    /**
     * @param  array<string, mixed>  $requirement  eis uit Bijlage B
     * @param  list<string>  $knownTechnologies  kandidaten voor de technologie (opdracht + profiel)
     * @return array{years: float, technology: string|null}|null
     */
    public static function parse(array $requirement, array $knownTechnologies, Technologies $technologies): ?array
    {
        $text = (string) ($requirement['tekst'] ?? '');
        $years = is_numeric($requirement['min_jaren'] ?? null) ? (float) $requirement['min_jaren'] : null;

        if ($years === null && preg_match('/(\d+(?:[.,]\d+)?)\s*\+?\s*(?:jaar|jaren|years?)\b/iu', $text, $m)) {
            $years = (float) str_replace(',', '.', $m[1]);
        }

        if ($years === null || $years <= 0) {
            return null;
        }

        $technology = is_string($requirement['technologie'] ?? null) && trim($requirement['technologie']) !== ''
            ? $requirement['technologie']
            : self::technologyIn($text, $knownTechnologies, $technologies);

        return ['years' => $years, 'technology' => $technology];
    }

    /**
     * De langste bekende technologie die in de tekst staat ("Laravel" wint niet van "Laravel Nova").
     *
     * @param  list<string>  $knownTechnologies
     */
    private static function technologyIn(string $text, array $knownTechnologies, Technologies $technologies): ?string
    {
        usort($knownTechnologies, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($knownTechnologies as $technology) {
            if (mb_strlen($technology) >= 2 && $technologies->mentionedIn($technology, $text)) {
                return $technology;
            }
        }

        return null;
    }
}

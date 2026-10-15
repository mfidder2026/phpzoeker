<?php

namespace App\Domain\Assignments\Support;

use Illuminate\Support\Str;

/**
 * Trigram-similariteit zoals `pg_trgm` (Jaccard over trigrammen van woorden met opvulling),
 * database-onafhankelijk zodat de ontdubbeling ook op SQLite werkt (§9.4, regel 3).
 */
final class TitleSimilarity
{
    public static function normalize(string $title): string
    {
        $title = Str::of($title)->ascii()->lower()->replaceMatches('/\([^)]*\)/', ' ')->replaceMatches('/[^a-z0-9 ]+/', ' ')->squish()->toString();

        // Rolnamen die vaak verschillend geschreven worden.
        return str_replace(['back end', 'front end', 'full stack', 'ontwikkelaar'], ['backend', 'frontend', 'fullstack', 'developer'], $title);
    }

    public static function score(string $a, string $b): float
    {
        $ta = self::trigrams(self::normalize($a));
        $tb = self::trigrams(self::normalize($b));

        if ($ta === [] || $tb === []) {
            return 0.0;
        }

        $intersection = count(array_intersect_key($ta, $tb));
        $union = count($ta + $tb);

        return round($intersection / $union, 3);
    }

    /**
     * @return array<string, true>
     */
    private static function trigrams(string $text): array
    {
        $grams = [];

        foreach (preg_split('/\s+/', $text) ?: [] as $word) {
            if ($word === '') {
                continue;
            }

            $padded = '  '.$word.' ';

            for ($i = 0; $i < mb_strlen($padded) - 2; $i++) {
                $grams[mb_substr($padded, $i, 3)] = true;
            }
        }

        return $grams;
    }
}

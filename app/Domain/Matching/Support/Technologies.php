<?php

namespace App\Domain\Matching\Support;

use App\Settings\MatchingSettings;

/**
 * Vergelijkt technologienamen met de aliaslijst uit de instellingen (§10.3), zodat
 * "Postgres" en "PostgreSQL" als dezelfde technologie tellen.
 */
class Technologies
{
    /**
     * @param  array<string, string>  $aliases
     */
    public function __construct(private readonly array $aliases = []) {}

    public static function fromSettings(): self
    {
        return new self(app(MatchingSettings::class)->technology_aliases);
    }

    public function normalize(string $name): string
    {
        $key = mb_strtolower(trim($name));
        $key = (string) preg_replace('/\s+/u', ' ', $key);
        $key = (string) preg_replace('/\s*\((?:m\/v|v\/m)\)\s*$/u', '', $key);

        if (isset($this->aliases[$key])) {
            return $this->aliases[$key];
        }

        // "PHP 8.3" → "php", "Laravel 11" → "laravel" (versienummers tellen niet mee).
        $withoutVersion = (string) preg_replace('/\s+v?\d+(?:\.\d+|\.x)*$/u', '', $key);

        return $this->aliases[$withoutVersion] ?? $withoutVersion;
    }

    /**
     * @param  iterable<mixed>  $names
     * @return list<string>
     */
    public function set(iterable $names): array
    {
        $set = [];

        foreach ($names as $name) {
            if (is_string($name) && trim($name) !== '') {
                $set[$this->normalize($name)] = true;
            }
        }

        return array_keys($set);
    }

    /**
     * Deel van de gevraagde technologieën dat in het profiel voorkomt (0–1). Een Jaccard-overlap
     * zou een breed profiel straffen; daarom telt alleen wat de opdracht vraagt (D28).
     *
     * @param  list<string>  $requested  genormaliseerd
     * @param  list<string>  $offered  genormaliseerd
     */
    public function coverage(array $requested, array $offered): ?float
    {
        if ($requested === []) {
            return null;
        }

        return count(array_intersect($requested, $offered)) / count($requested);
    }

    /**
     * Komt de technologie (of een alias ervan) als los woord in de tekst voor?
     */
    public function mentionedIn(string $technology, string $text): bool
    {
        $haystack = mb_strtolower($text);

        foreach ($this->variants($this->normalize($technology)) as $variant) {
            $needle = preg_quote($variant, '/');

            if (preg_match('/(?<![\p{L}\p{N}])'.$needle.'(?![\p{L}\p{N}])/u', $haystack) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function variants(string $normalized): array
    {
        $variants = [$normalized];

        foreach ($this->aliases as $alias => $target) {
            if ($target === $normalized && mb_strlen((string) $alias) > 2) {
                $variants[] = (string) $alias;
            }
        }

        return array_values(array_unique($variants));
    }
}

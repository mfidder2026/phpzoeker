<?php

namespace App\Domain\Offers\Support;

use App\Domain\Matching\Support\ProfileFacts;
use App\Domain\Matching\Support\Technologies;

/**
 * Deterministische grounding-check van een getailord CV (§12.3 stap 1):
 * - werkervaring: elke entry (periode, organisatie, functie) bestaat in het master-profiel;
 * - opleidingen en certificaten: deelverzameling van het master-profiel;
 * - technologieën: elk token in kernexpertise, werkervaring en projecten komt voor in het profiel;
 * - niveaus: nooit hoger dan in het profiel;
 * - getallen: elk "N jaar" is niet meer dan de berekende jaren (en niet bij niveau "basis");
 * - CV-titel: een van de toegestane titels.
 * Daarnaast vervangt {@see self::restoreFixedFields()} vóór de check de velden die de tailor
 * niet mag wijzigen door die uit het master-profiel.
 */
class GroundingChecker
{
    /** @var array<string, int> */
    private const NUMBER_WORDS = [
        'een' => 1, 'één' => 1, 'twee' => 2, 'drie' => 3, 'vier' => 4, 'vijf' => 5, 'zes' => 6, 'zeven' => 7, 'acht' => 8,
        'negen' => 9, 'tien' => 10, 'elf' => 11, 'twaalf' => 12, 'dertien' => 13, 'veertien' => 14, 'vijftien' => 15, 'twintig' => 20,
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
        'eleven' => 11, 'twelve' => 12, 'fifteen' => 15, 'twenty' => 20,
    ];

    /** @var array<string, int> */
    private const LEVELS = ['basis' => 1, 'gevorderd' => 2, 'expert' => 3];

    public function __construct(private readonly Technologies $technologies) {}

    /**
     * Zet `id`, `van`, `tot`, `functie`, `organisatie` en `via` van de werkervaring terug naar het
     * master-profiel (op id) en houdt van opleidingen en certificaten alleen wat in het profiel
     * staat. Geeft de herstelde inhoud en de lijst met correcties terug.
     *
     * @param  array<string, mixed>  $tailored
     * @param  array<string, mixed>  $master
     * @return array{content: array<string, mixed>, restored: list<string>}
     */
    public function restoreFixedFields(array $tailored, array $master): array
    {
        $restored = [];
        $masterJobs = collect((array) ($master['werkervaring'] ?? []))->filter(fn (mixed $job): bool => is_array($job))->keyBy('id');

        foreach ((array) ($tailored['werkervaring'] ?? []) as $index => $job) {
            if (! is_array($job) || ! $masterJobs->has($job['id'] ?? '')) {
                continue;
            }

            $original = (array) $masterJobs->get($job['id']);

            foreach (['van', 'tot', 'functie', 'organisatie', 'via'] as $field) {
                if (($job[$field] ?? null) !== ($original[$field] ?? null)) {
                    $tailored['werkervaring'][$index][$field] = $original[$field] ?? null;
                    $restored[] = "werkervaring[{$index}].{$field} teruggezet naar het master-profiel";
                }
            }
        }

        foreach (['opleidingen' => ['periode', 'naam'], 'certificaten' => ['jaar', 'naam']] as $section => $keys) {
            $allowed = collect((array) ($master[$section] ?? []))->map(fn (mixed $row): string => $this->key((array) $row, $keys))->all();
            $kept = [];

            foreach ((array) ($tailored[$section] ?? []) as $row) {
                if (is_array($row) && in_array($this->key($row, $keys), $allowed, true)) {
                    $kept[] = $row;
                } elseif (is_array($row)) {
                    $restored[] = "{$section}: \"".($row['naam'] ?? '?').'" verwijderd (staat niet in het master-profiel)';
                }
            }

            $tailored[$section] = $kept;
        }

        return ['content' => $tailored, 'restored' => $restored];
    }

    /**
     * @param  array<string, mixed>  $tailored
     * @param  list<string>  $allowedTitles
     * @return list<array{check: string, passed: bool, detail: string}>
     */
    public function check(array $tailored, ProfileFacts $facts, array $allowedTitles): array
    {
        $master = $facts->profile;

        return [
            $this->workExperience($tailored, $master),
            $this->education($tailored, $master),
            $this->technologyTokens($tailored, $facts),
            $this->levels($tailored, $master),
            $this->years($tailored, $facts),
            $this->title($tailored, [...$allowedTitles, (string) ($master['cv_titel'] ?? '')]),
        ];
    }

    /**
     * @param  array<string, mixed>  $tailored
     * @param  array<string, mixed>  $master
     * @return array{check: string, passed: bool, detail: string}
     */
    private function workExperience(array $tailored, array $master): array
    {
        $known = collect((array) ($master['werkervaring'] ?? []))->map(fn (mixed $job): string => $this->key((array) $job, ['van', 'tot', 'functie', 'organisatie']))->all();
        $unknown = [];

        foreach ((array) ($tailored['werkervaring'] ?? []) as $index => $job) {
            if (! in_array($this->key((array) $job, ['van', 'tot', 'functie', 'organisatie']), $known, true)) {
                $unknown[] = "werkervaring[{$index}] (".($job['functie'] ?? '?').' bij '.($job['organisatie'] ?? '?').')';
            }
        }

        return $this->result('Werkervaring gelijk aan het master-profiel', $unknown);
    }

    /**
     * @param  array<string, mixed>  $tailored
     * @param  array<string, mixed>  $master
     * @return array{check: string, passed: bool, detail: string}
     */
    private function education(array $tailored, array $master): array
    {
        $problems = [];

        foreach (['opleidingen' => ['periode', 'naam', 'status'], 'certificaten' => ['jaar', 'naam']] as $section => $keys) {
            $known = collect((array) ($master[$section] ?? []))->map(fn (mixed $row): string => $this->key((array) $row, $keys))->all();

            foreach ((array) ($tailored[$section] ?? []) as $row) {
                if (! in_array($this->key((array) $row, $keys), $known, true)) {
                    $problems[] = $section.': '.($row['naam'] ?? '?');
                }
            }
        }

        return $this->result('Opleidingen en certificaten uit het master-profiel', $problems);
    }

    /**
     * @param  array<string, mixed>  $tailored
     * @return array{check: string, passed: bool, detail: string}
     */
    private function technologyTokens(array $tailored, ProfileFacts $facts): array
    {
        $vocabulary = $facts->technologies();
        $tokens = [];

        foreach ((array) ($tailored['kernexpertise'] ?? []) as $block) {
            foreach ((array) ($block['items'] ?? []) as $item) {
                $tokens[] = (string) ($item['naam'] ?? '');
            }
        }

        foreach (['werkervaring', 'projecten'] as $section) {
            foreach ((array) ($tailored[$section] ?? []) as $entry) {
                array_push($tokens, ...array_map('strval', (array) ($entry['technologieen'] ?? [])));
            }
        }

        $unknown = array_values(array_diff($this->technologies->set($tokens), $vocabulary));

        return $this->result('Technologieën komen voor in het master-profiel', $unknown);
    }

    /**
     * @param  array<string, mixed>  $tailored
     * @param  array<string, mixed>  $master
     * @return array{check: string, passed: bool, detail: string}
     */
    private function levels(array $tailored, array $master): array
    {
        $masterLevels = [];

        foreach ((array) ($master['kernexpertise'] ?? []) as $block) {
            foreach ((array) ($block['items'] ?? []) as $item) {
                $masterLevels[$this->technologies->normalize((string) ($item['naam'] ?? ''))] = self::LEVELS[$item['niveau'] ?? ''] ?? null;
            }
        }

        $raised = [];

        foreach ((array) ($tailored['kernexpertise'] ?? []) as $block) {
            foreach ((array) ($block['items'] ?? []) as $item) {
                $name = $this->technologies->normalize((string) ($item['naam'] ?? ''));
                $level = self::LEVELS[$item['niveau'] ?? ''] ?? null;
                $original = $masterLevels[$name] ?? null;

                if ($level !== null && ($original === null || $level > $original)) {
                    $raised[] = ($item['naam'] ?? '?').': '.$item['niveau'].' (profiel: '.(array_search($original, self::LEVELS, true) ?: 'geen niveau').')';
                }
            }
        }

        return $this->result('Niveaus niet hoger dan in het master-profiel', $raised);
    }

    /**
     * Elke "N jaar (ervaring) (met|in) X" moet kloppen met de berekende jaren (§12.3).
     *
     * @param  array<string, mixed>  $tailored
     * @return array{check: string, passed: bool, detail: string}
     */
    private function years(array $tailored, ProfileFacts $facts): array
    {
        $levels = [];

        foreach ((array) ($facts->profile['kernexpertise'] ?? []) as $block) {
            foreach ((array) ($block['items'] ?? []) as $item) {
                $levels[$this->technologies->normalize((string) ($item['naam'] ?? ''))] = $item['niveau'] ?? null;
            }
        }

        $known = array_values(array_unique([...$facts->technologies(), ...array_keys($levels)]));
        usort($known, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $numbers = implode('|', array_map(fn (string $word): string => preg_quote($word, '/'), array_keys(self::NUMBER_WORDS)));
        $problems = [];

        foreach ($this->texts($tailored) as $location => $text) {
            preg_match_all('/(?:ruim\s+|meer\s+dan\s+|over\s+|more\s+than\s+)?(\d+(?:[.,]\d+)?|'.$numbers.')\s*\+?\s*(?:jaar|jaren|years?)\b([^.;:\n]{0,80})/iu', $text, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $claimed = is_numeric(str_replace(',', '.', $match[1])) ? (float) str_replace(',', '.', $match[1]) : (float) (self::NUMBER_WORDS[mb_strtolower($match[1])] ?? 0);
                $technology = null;

                foreach ($known as $candidate) {
                    if (mb_strlen($candidate) >= 2 && $this->technologies->mentionedIn($candidate, $match[2])) {
                        $technology = $candidate;

                        break;
                    }
                }

                $actual = $technology !== null ? $facts->yearsWith($technology)['years'] : $facts->professionalYears();
                $claim = trim($match[0]);

                if ($claimed > $actual + 0.5) {
                    $problems[] = "{$location}: \"{$claim}\" — volgens het profiel ".str_replace('.', ',', (string) $actual).' jaar';
                } elseif ($technology !== null && ($levels[$technology] ?? null) === 'basis' && $claimed >= 3) {
                    $problems[] = "{$location}: \"{$claim}\" — {$technology} heeft niveau basis in het master-profiel";
                }
            }
        }

        return $this->result('Aantallen jaren kloppen met het master-profiel', $problems);
    }

    /**
     * @param  array<string, mixed>  $tailored
     * @param  list<string>  $allowed
     * @return array{check: string, passed: bool, detail: string}
     */
    private function title(array $tailored, array $allowed): array
    {
        $title = trim((string) ($tailored['cv_titel'] ?? ''));
        $allowed = array_map(fn (string $value): string => mb_strtolower(trim($value)), array_filter($allowed));

        return $this->result('CV-titel is een toegestane titel', $title === '' || in_array(mb_strtolower($title), $allowed, true) ? [] : [$title]);
    }

    /**
     * Vrije tekst in het CV, met het pad als sleutel.
     *
     * @param  array<string, mixed>  $tailored
     * @return array<string, string>
     */
    public static function texts(array $tailored): array
    {
        $texts = ['profieltekst' => (string) ($tailored['profieltekst'] ?? ''), 'tagline' => (string) ($tailored['tagline'] ?? '')];

        foreach ((array) ($tailored['werkervaring'] ?? []) as $index => $job) {
            foreach ((array) ($job['bullets'] ?? []) as $bulletIndex => $bullet) {
                $texts["werkervaring[{$index}].bullets[{$bulletIndex}]"] = (string) $bullet;
            }
        }

        foreach ((array) ($tailored['projecten'] ?? []) as $index => $project) {
            $texts["projecten[{$index}].omschrijving"] = (string) ($project['omschrijving'] ?? '');
        }

        foreach ((array) ($tailored['aansluiting_op_opdracht'] ?? []) as $index => $row) {
            $texts["aansluiting_op_opdracht[{$index}]"] = (string) ($row['invulling'] ?? '');
        }

        return $texts;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    private function key(array $row, array $keys): string
    {
        return implode('|', array_map(fn (string $key): string => mb_strtolower(trim((string) ($row[$key] ?? ''))), $keys));
    }

    /**
     * @param  list<string>  $problems
     * @return array{check: string, passed: bool, detail: string}
     */
    private function result(string $check, array $problems): array
    {
        return ['check' => $check, 'passed' => $problems === [], 'detail' => $problems === [] ? 'In orde.' : implode('; ', array_slice($problems, 0, 10))];
    }
}

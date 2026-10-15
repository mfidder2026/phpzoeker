<?php

namespace App\Domain\Matching\Support;

use App\Ai\Support\PiiScrubber;
use App\Domain\Candidates\Models\Candidate;
use Carbon\CarbonImmutable;

/**
 * Feiten uit het goedgekeurde master-profiel die de matching deterministisch berekent (§10.4):
 * technologieën en het aantal jaren ervaring per technologie. Het taalmodel krijgt deze jaren
 * mee en mag ze niet ophogen.
 */
class ProfileFacts
{
    /**
     * @param  array<string, mixed>  $profile
     */
    public function __construct(
        public readonly Candidate $candidate,
        public readonly array $profile,
        private readonly Technologies $technologies,
        private readonly CarbonImmutable $today,
    ) {}

    public static function for(Candidate $candidate, ?Technologies $technologies = null): ?self
    {
        $data = $candidate->activeProfile?->data;

        if (! is_array($data)) {
            return null;
        }

        return new self($candidate, $data, $technologies ?? Technologies::fromSettings(), CarbonImmutable::today());
    }

    public function profileVersion(): ?int
    {
        return $this->candidate->activeProfile?->version;
    }

    /**
     * Alle technologieën en vaardigheden uit het profiel, genormaliseerd.
     *
     * @return list<string>
     */
    public function technologies(): array
    {
        $names = [];

        foreach ($this->list('werkervaring') as $job) {
            array_push($names, ...array_filter((array) ($job['technologieen'] ?? []), 'is_string'));
        }

        foreach ($this->list('projecten') as $project) {
            array_push($names, ...array_filter((array) ($project['technologieen'] ?? []), 'is_string'));
        }

        foreach ($this->list('kernexpertise') as $block) {
            foreach ((array) ($block['items'] ?? []) as $item) {
                if (is_array($item) && is_string($item['naam'] ?? null)) {
                    $names[] = $item['naam'];
                }
            }
        }

        array_push($names, ...array_filter((array) ($this->profile['overige_vaardigheden'] ?? []), 'is_string'));

        return $this->technologies->set($names);
    }

    /**
     * Jaren werkervaring met een technologie: de vereniging van de periodes in `werkervaring`
     * waarin die technologie genoemd wordt, of het jaartal `sinds` in de kernexpertise als dat
     * eerder is. Stages en "eerdere ervaring" tellen niet mee.
     *
     * @return array{years: float, since: string|null, level: string|null, source: list<string>}
     */
    public function yearsWith(string $technology): array
    {
        $intervals = [];
        $sources = [];
        $level = null;

        foreach ($this->list('werkervaring') as $index => $job) {
            $text = implode(' ', [
                implode(' ', array_filter((array) ($job['technologieen'] ?? []), 'is_string')),
                (string) ($job['functie'] ?? ''),
                (string) ($job['subtitel'] ?? ''),
                implode(' ', array_filter((array) ($job['bullets'] ?? []), 'is_string')),
            ]);

            if (! $this->technologies->mentionedIn($technology, $text)) {
                continue;
            }

            $interval = $this->interval((string) ($job['van'] ?? ''), (string) ($job['tot'] ?? ''));

            if ($interval !== null) {
                $intervals[] = $interval;
                $sources[] = "werkervaring[{$index}]";
            }
        }

        foreach ($this->list('kernexpertise') as $blockIndex => $block) {
            foreach ((array) ($block['items'] ?? []) as $itemIndex => $item) {
                if (! is_array($item) || ! is_string($item['naam'] ?? null) || $this->technologies->normalize($item['naam']) !== $this->technologies->normalize($technology)) {
                    continue;
                }

                $level ??= is_string($item['niveau'] ?? null) ? $item['niveau'] : null;

                if (preg_match('/^\d{4}$/', (string) ($item['sinds'] ?? ''))) {
                    $intervals[] = [CarbonImmutable::parse($item['sinds'].'-01-01'), $this->today];
                    $sources[] = "kernexpertise[{$blockIndex}].items[{$itemIndex}]";
                }
            }
        }

        return [
            'years' => $this->unionYears($intervals),
            'since' => $intervals === [] ? null : collect($intervals)->min(fn (array $interval) => $interval[0])?->format('Y-m'),
            'level' => $level,
            'source' => $sources,
        ];
    }

    /**
     * Totale professionele werkervaring (vereniging van alle periodes in `werkervaring`).
     */
    public function professionalYears(): float
    {
        $intervals = [];

        foreach ($this->list('werkervaring') as $job) {
            $interval = $this->interval((string) ($job['van'] ?? ''), (string) ($job['tot'] ?? ''));

            if ($interval !== null) {
                $intervals[] = $interval;
            }
        }

        return $this->unionYears($intervals);
    }

    /**
     * Profiel zoals het naar het taalmodel gaat: zonder naam, contactgegevens of geboortedatum
     * (R4, §10.4).
     *
     * @return array<string, mixed>
     */
    public function forPrompt(): array
    {
        $profile = $this->profile;
        $profile['naam'] = 'De kandidaat';
        unset($profile['voetregel']);

        $json = (string) json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $json = app(PiiScrubber::class)->scrub($json, $this->candidate);
        $json = str_ireplace($this->candidate->name, 'De kandidaat', $json);

        return (array) json_decode($json, true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function list(string $key): array
    {
        return array_filter((array) ($this->profile[$key] ?? []), 'is_array');
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function interval(string $from, string $until): ?array
    {
        $start = $this->parseMonth($from, false);

        if ($start === null) {
            return null;
        }

        $end = $this->parseMonth($until, true) ?? $this->today;

        if ($end->greaterThan($this->today)) {
            $end = $this->today;
        }

        return $end->lessThan($start) ? null : [$start, $end];
    }

    private function parseMonth(string $value, bool $endOfPeriod): ?CarbonImmutable
    {
        $value = mb_strtolower(trim($value));

        if ($value === '' || in_array($value, ['heden', 'nu', 'present', 'now', 'huidig'], true)) {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{1,2})$/', $value, $m) || preg_match('/^(\d{1,2})[-\/](\d{4})$/', $value, $m)) {
            [$year, $month] = strlen($m[1]) === 4 ? [(int) $m[1], (int) $m[2]] : [(int) $m[2], (int) $m[1]];
            $date = CarbonImmutable::create($year, max(1, min(12, $month)), 1);

            return $endOfPeriod ? $date?->endOfMonth()->startOfDay() : $date;
        }

        if (preg_match('/^(\d{4})$/', $value, $m)) {
            return $endOfPeriod ? CarbonImmutable::create((int) $m[1], 12, 31) : CarbonImmutable::create((int) $m[1], 1, 1);
        }

        return null;
    }

    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $intervals
     */
    private function unionYears(array $intervals): float
    {
        if ($intervals === []) {
            return 0.0;
        }

        usort($intervals, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $days = 0;
        [$currentStart, $currentEnd] = $intervals[0];

        foreach (array_slice($intervals, 1) as [$start, $end]) {
            if ($start->lessThanOrEqualTo($currentEnd)) {
                if ($end->greaterThan($currentEnd)) {
                    $currentEnd = $end;
                }

                continue;
            }

            $days += $currentStart->diffInDays($currentEnd);
            [$currentStart, $currentEnd] = [$start, $end];
        }

        $days += $currentStart->diffInDays($currentEnd);

        return round($days / 365.25, 1);
    }
}

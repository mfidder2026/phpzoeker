<?php

namespace App\Domain\Assignments\Actions;

use App\Domain\Assignments\Models\Assignment;
use App\Domain\Assignments\Support\TitleSimilarity;
use App\Domain\Relations\Models\Organization;
use App\Settings\IngestSettings;
use Carbon\CarbonImmutable;

/**
 * Ontdubbelen over bronnen heen (§9.4). Regel 1 (exact: zelfde content-hash) gebeurt al bij de
 * ruwe publicatie; hier regel 2 (zelfde referentie) en regel 3 (fuzzy). Twijfelgevallen worden
 * als "mogelijk duplicaat" gemarkeerd voor menselijke controle.
 */
class FindDuplicate
{
    public function __construct(private readonly IngestSettings $settings) {}

    /**
     * @param  array<string, mixed>  $attributes  genormaliseerde opdrachtvelden
     * @return array{match: Assignment|null, possible: Assignment|null, rule: string|null}
     */
    public function handle(array $attributes): array
    {
        $candidates = Assignment::query()->open()
            ->where('created_at', '>=', now()->subMonths(6))
            ->latest('id')
            ->limit(500)
            ->get();

        $reference = self::normalizeReference($attributes['reference'] ?? null);
        $client = isset($attributes['client_name']) ? Organization::normalizeName((string) $attributes['client_name']) : '';
        $city = mb_strtolower(trim((string) ($attributes['city'] ?? '')));
        $title = (string) ($attributes['title'] ?? '');
        $possible = null;

        foreach ($candidates as $assignment) {
            $similarity = TitleSimilarity::score($title, $assignment->title);
            $sameClient = $client !== '' && $assignment->client_name !== null && Organization::normalizeName($assignment->client_name) === $client;

            // Regel 2: zelfde referentie- of aanvraagnummer bij dezelfde eindklant (of vergelijkbare titel).
            if ($reference !== null && self::normalizeReference($assignment->reference) === $reference && ($sameClient || $similarity >= 0.5)) {
                return ['match' => $assignment, 'possible' => null, 'rule' => 'referentie'];
            }

            $sameCity = $city === '' || $assignment->city === null || mb_strtolower($assignment->city) === $city;
            $startClose = $this->startWithinWindow($attributes['start_date'] ?? null, $assignment->start_date?->toDateString());

            // Regel 3: zelfde eindklant + titel-similariteit + startdatum dichtbij + zelfde plaats.
            if ($sameClient && $similarity >= $this->settings->dedupe_title_similarity && $startClose && $sameCity) {
                return ['match' => $assignment, 'possible' => null, 'rule' => 'eindklant+titel'];
            }

            // Twijfel: eindklant onbekend aan één kant, maar titel en plaats komen overeen.
            if ($possible === null && ! $sameClient && ($client === '' || $assignment->client_name === null)
                && $similarity >= $this->settings->dedupe_title_similarity && $city !== '' && $sameCity && $startClose) {
                $possible = $assignment;
            }
        }

        return ['match' => null, 'possible' => $possible, 'rule' => null];
    }

    public static function normalizeReference(mixed $reference): ?string
    {
        if (! is_string($reference)) {
            return null;
        }

        $normalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $reference));

        return strlen($normalized) >= 3 ? $normalized : null;
    }

    private function startWithinWindow(mixed $a, ?string $b): bool
    {
        if (! is_string($a) || $a === '' || $b === null) {
            return true;
        }

        return abs(CarbonImmutable::parse($a)->diffInDays(CarbonImmutable::parse($b))) <= $this->settings->dedupe_start_window_days;
    }
}

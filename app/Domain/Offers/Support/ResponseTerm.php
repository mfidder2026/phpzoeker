<?php

namespace App\Domain\Offers\Support;

use App\Domain\Offers\Models\Offer;
use App\Settings\RegisterSettings;
use Carbon\CarbonImmutable;

/**
 * Verwachte reactie (§11.6): basis = later van verzending en deadline; termijn = aanpassing op
 * de aanbieding → standaard van de opdrachtgever/intermediair → standaard van de bron →
 * globale standaard; resultaat = basis + N werkdagen om 17:00 (Europe/Amsterdam).
 */
class ResponseTerm
{
    public const TIMEZONE = 'Europe/Amsterdam';

    public function __construct(private readonly RegisterSettings $settings) {}

    /**
     * @return array{days: int, source: string}
     */
    public function termFor(Offer $offer, ?int $override = null): array
    {
        return match (true) {
            $override !== null => ['days' => $override, 'source' => 'offer'],
            $offer->organization?->default_response_term_days !== null => ['days' => (int) $offer->organization->default_response_term_days, 'source' => 'organization'],
            $offer->source?->default_response_term_days !== null => ['days' => (int) $offer->source->default_response_term_days, 'source' => 'source'],
            default => ['days' => $this->settings->default_response_term_days, 'source' => 'global'],
        };
    }

    public function expectedAt(CarbonImmutable $sentAt, ?CarbonImmutable $deadline, int $days): CarbonImmutable
    {
        $base = $sentAt->setTimezone(self::TIMEZONE);

        if ($deadline !== null && $deadline->setTimezone(self::TIMEZONE)->greaterThan($base)) {
            $base = $deadline->setTimezone(self::TIMEZONE);
        }

        return Workdays::add($base, $days, $this->settings->expected_response_time);
    }

    /**
     * Zet termijn, bron van de termijn en verwachte reactie op de aanbieding (niet opgeslagen).
     */
    public function apply(Offer $offer, ?int $override = null, ?string $reason = null): void
    {
        $term = $this->termFor($offer, $override);
        $sentAt = CarbonImmutable::parse($offer->sent_at ?? now());
        $deadline = $offer->assignment->deadline_at !== null ? CarbonImmutable::parse($offer->assignment->deadline_at) : null;

        $offer->forceFill([
            'response_term_days' => $term['days'],
            'response_term_source' => $term['source'],
            'response_term_override_reason' => $override !== null ? $reason : null,
            'expected_response_at' => $this->expectedAt($sentAt, $deadline, $term['days']),
        ]);
    }

    public static function sourceLabel(?string $source): string
    {
        return match ($source) {
            'offer' => 'aangepast op deze aanbieding',
            'organization' => 'standaard van de opdrachtgever/intermediair',
            'source' => 'standaard van de bron',
            'global' => 'globale standaard',
            default => '—',
        };
    }

    /**
     * Indicator voor het register: op schema, vandaag of te laat (met werkdagen).
     *
     * @return array{state: string, label: string}
     */
    public static function indicator(Offer $offer): array
    {
        if ($offer->expected_response_at === null || $offer->response_received_at !== null || ! $offer->status->isOpen()) {
            return ['state' => 'none', 'label' => '—'];
        }

        $expected = CarbonImmutable::parse($offer->expected_response_at)->setTimezone(self::TIMEZONE);
        $now = CarbonImmutable::now(self::TIMEZONE);

        if ($expected->isFuture() && ! $expected->isSameDay($now)) {
            return ['state' => 'ok', 'label' => $expected->format('d-m-Y')];
        }

        if ($expected->isFuture()) {
            return ['state' => 'today', 'label' => 'vandaag '.$expected->format('H:i')];
        }

        $late = max(1, (int) Workdays::between($expected, $now));

        return ['state' => 'late', 'label' => $late.' '.($late === 1 ? 'werkdag' : 'werkdagen').' te laat'];
    }
}

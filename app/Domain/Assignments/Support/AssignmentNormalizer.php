<?php

namespace App\Domain\Assignments\Support;

use App\Domain\Assignments\Enums\AssignmentContractType;
use App\Domain\Assignments\Enums\Sector;
use App\Domain\Assignments\Enums\WorkMode;
use App\Settings\GeneralSettings;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Normaliseert de extractie-output naar opdrachtvelden (§9.2). Het taalmodel levert getallen
 * en datums; de regels hieronder vangen ontbrekende waarden op uit de tekst en zetten alles in
 * een vaste vorm (Europe/Amsterdam, deadline zonder tijd → instelbaar tijdstip).
 */
class AssignmentNormalizer
{
    public const TIMEZONE = 'Europe/Amsterdam';

    public function __construct(private readonly GeneralSettings $settings) {}

    /**
     * @param  array<string, mixed>  $data  Bijlage B
     * @return array<string, mixed> attributen voor `assignments`
     */
    public function normalize(array $data, string $sourceText): array
    {
        $hoursMin = self::number($data['uren_per_week']['min'] ?? null);
        $hoursMax = self::number($data['uren_per_week']['max'] ?? null);

        if ($hoursMin === null && $hoursMax === null) {
            [$hoursMin, $hoursMax] = self::parseHours($sourceText);
        }

        if ($hoursMin !== null && $hoursMax !== null && $hoursMin > $hoursMax) {
            [$hoursMin, $hoursMax] = [$hoursMax, $hoursMin];
        }

        $zzpFromText = self::zzpAllowedFromText($sourceText);
        $zzpAllowed = $zzpFromText ?? (is_bool($data['zzp_toegestaan'] ?? null) ? $data['zzp_toegestaan'] : null);
        $contract = AssignmentContractType::tryFrom((string) ($data['contractvorm'] ?? '')) ?? AssignmentContractType::Onbekend;

        if ($zzpAllowed === true && $contract === AssignmentContractType::Detachering) {
            $contract = AssignmentContractType::Beide;
        }

        [$cityFromText, $provinceFromText] = self::locationFromText($sourceText);

        $rate = self::number($data['tarief']['max_per_uur'] ?? null) ?? self::parseRate((string) ($data['tarief']['originele_tekst'] ?? ''));
        $startAsap = (bool) ($data['start']['zo_snel_mogelijk'] ?? false) || self::mentionsAsap((string) ($data['start']['datum'] ?? ''));

        return [
            'title' => mb_substr(trim((string) ($data['titel'] ?? '')), 0, 255),
            'client_name' => self::text($data['eindklant'] ?? null),
            'intermediary_name' => self::text($data['intermediair'] ?? null),
            'reference' => self::text($data['referentie'] ?? null),
            'summary' => self::text($data['samenvatting'] ?? null),
            'language' => ($data['taal_opdracht'] ?? 'nl') === 'en' ? 'en' : 'nl',
            'location_text' => self::text($data['locatie']['tekst'] ?? null),
            'city' => self::text($data['locatie']['plaats'] ?? null) ?? $cityFromText,
            'province' => self::text($data['locatie']['provincie'] ?? null) ?? $provinceFromText,
            'work_mode' => (WorkMode::tryFrom((string) ($data['werkvorm'] ?? '')) ?? WorkMode::Onbekend)->value,
            'onsite_days_per_week' => self::number($data['dagen_op_locatie_per_week'] ?? null),
            'hours_min' => $hoursMin === null ? null : (int) round($hoursMin),
            'hours_max' => $hoursMax === null ? null : (int) round($hoursMax),
            'start_date' => self::date($data['start']['datum'] ?? null),
            'start_asap' => $startAsap,
            'end_date' => self::date($data['einde']['datum'] ?? null),
            'duration_months' => ($months = self::number($data['einde']['duur_maanden'] ?? null)) === null ? null : (int) round($months),
            'extension_option' => self::text($data['einde']['verlenging'] ?? null),
            'max_rate' => $rate,
            'rate_includes_margin' => is_bool($data['tarief']['incl_marge'] ?? null) ? $data['tarief']['incl_marge'] : null,
            'rate_text' => self::text($data['tarief']['originele_tekst'] ?? null),
            'contract_type' => $contract->value,
            'zzp_allowed' => $zzpAllowed,
            'sector' => (Sector::tryFrom((string) ($data['sector'] ?? '')) ?? Sector::Onbekend)->value,
            'seniority' => in_array($data['senioriteit'] ?? null, ['junior', 'medior', 'senior', 'lead'], true) ? $data['senioriteit'] : 'onbekend',
            'deadline_at' => $this->deadline($data['deadline'] ?? null),
            'requirements' => array_values((array) ($data['eisen'] ?? [])),
            'wishes' => array_values((array) ($data['wensen'] ?? [])),
            'technologies' => array_values(array_unique(array_map('strval', (array) ($data['technologieen'] ?? [])))),
            'screening' => (array) ($data['screening'] ?? []),
            'submission' => (array) ($data['indieningsinstructies'] ?? []),
            'is_relevant' => (bool) ($data['is_relevant_softwareontwikkeling'] ?? true),
        ];
    }

    /**
     * Deadline in Europe/Amsterdam; zonder tijd → instelbaar tijdstip (standaard 12:00).
     */
    public function deadline(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1) {
                return CarbonImmutable::parse(trim($value).' '.$this->settings->default_deadline_time, self::TIMEZONE);
            }

            return CarbonImmutable::parse($value, self::TIMEZONE);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * "32–40 uur" → [32, 40]; "4 dagen" → [32, 32]; "0,8 fte" → [32, 32]; "36 uur" → [36, 36].
     *
     * @return array{0: float|null, 1: float|null}
     */
    public static function parseHours(string $text): array
    {
        $text = mb_strtolower($text);

        if (preg_match('/(\d{1,2})\s*(?:-|–|—|tot|à|a)\s*(\d{1,2})\s*(?:uur|u\b|uren)/u', $text, $m) === 1) {
            return [(float) $m[1], (float) $m[2]];
        }

        if (preg_match('/(\d{1,2})\s*(?:uur|uren)\s*(?:per|p\/|\/)\s*w/u', $text, $m) === 1 || preg_match('/uren per week:?\s*(\d{1,2})/u', $text, $m) === 1) {
            return [(float) $m[1], (float) $m[1]];
        }

        if (preg_match('/(\d)\s*dag(?:en)?\s*(?:per|p\/|\/|in de)\s*w/u', $text, $m) === 1) {
            return [(float) $m[1] * 8, (float) $m[1] * 8];
        }

        if (preg_match('/(\d(?:[.,]\d+)?)\s*fte/u', $text, $m) === 1) {
            $hours = (float) str_replace(',', '.', $m[1]) * 40;

            return [$hours, $hours];
        }

        return [null, null];
    }

    /**
     * "max € 90,- p/u" → 90.0; "tot 95 euro" → 95.0; "€ 87,50" → 87.5.
     */
    public static function parseRate(string $text): ?float
    {
        if (preg_match('/(?:€|eur(?:o)?)\s*(\d{2,3}(?:[.,]\d{1,2})?)|(\d{2,3}(?:[.,]\d{1,2})?)\s*(?:€|euro|eur)\b/iu', $text, $m) !== 1) {
            return null;
        }

        $value = (string) ($m[1] !== '' ? $m[1] : $m[2]);

        return (float) str_replace(',', '.', $value);
    }

    /**
     * Expliciete tekst gaat boven het model: "ZZP is toegestaan" → true, "geen zzp" → false.
     */
    public static function zzpAllowedFromText(string $text): ?bool
    {
        $who = "(?:zzp(?:['’]?ers?)?|zelfstandigen)";

        if (preg_match('/\b'.$who.'\b[^.]{0,20}\bniet\b[^.]{0,15}\btoegestaan\b|\bgeen\s+zzp|\bzzp\b[^.]{0,10}\buitgesloten/iu', $text) === 1) {
            return false;
        }

        if (preg_match('/\b'.$who.'\b[^.]{0,20}\btoegestaan\b/iu', $text) === 1) {
            return true;
        }

        return null;
    }

    /**
     * "Locatie: Vlaardingen - Zuid-Holland" → ["Vlaardingen", "Zuid-Holland"].
     *
     * @return array{0: string|null, 1: string|null}
     */
    public static function locationFromText(string $text): array
    {
        $provinces = 'Groningen|Friesland|Fryslân|Drenthe|Overijssel|Flevoland|Gelderland|Utrecht|Noord-Holland|Zuid-Holland|Zeeland|Noord-Brabant|Limburg';
        // Meerdere woorden ("Den Haag", "Bergen op Zoom"), maar niet het volgende label ("Uren per week: …").
        $label = 'Uren|Contractvorm|Organisatie|Tarief|Regio|Startdatum|Start|Duur|Sluitingsdatum|Optie|Aanvraagnummer|Werkvorm|Hybride|Remote';
        $place = "[\\p{Lu}][\\p{L}'.]+(?: (?:op|aan|de|den|in|bij|(?!(?:{$label})\\b)[\\p{Lu}][\\p{L}'.]+)){0,3}";

        if (preg_match('/(?:Locatie|Standplaats|Werklocatie|Plaats)\s*:\s*('.$place.')\s*(?:[-–,]\s*('.$provinces.'))?(?=\s|$|,|\()/u', $text, $m) === 1) {
            return [trim($m[1]), $m[2] ?? null];
        }

        return [null, null];
    }

    public static function mentionsAsap(string $text): bool
    {
        return preg_match('/\b(z\.?s\.?m\.?|zo snel mogelijk|per direct|asap|direct)\b/iu', $text) === 1;
    }

    private static function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric(str_replace(',', '.', $value))) {
            return (float) str_replace(',', '.', $value);
        }

        return null;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }
}

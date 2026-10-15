<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class MatchingSettings extends Settings
{
    public int $strong_threshold;

    public int $possible_threshold;

    /**
     * Gewichten per component (§10.3): requirements, wishes, technology, seniority, rate, location, hours.
     *
     * @var array<string, int>
     */
    public array $weights;

    public int $start_date_tolerance_days;

    public float $embedding_prefilter_threshold;

    /**
     * Aliassen voor technologie-overlap, bijv. postgres → postgresql (§10.3).
     *
     * @var array<string, string>
     */
    public array $technology_aliases;

    /** Deel van het AI-dagbudget (%) dat vrij blijft voor extractie; matching pauzeert eerder (§18.3). */
    public int $extraction_budget_reserve_percent;

    /** Maximaal aantal AI-beoordelingen per herberekening van één kandidaat. */
    public int $max_assessments_per_run;

    public static function group(): string
    {
        return 'matching';
    }
}

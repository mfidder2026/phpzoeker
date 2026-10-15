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

    public static function group(): string
    {
        return 'matching';
    }
}

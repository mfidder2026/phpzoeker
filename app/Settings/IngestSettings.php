<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Instellingen voor ophalen, verwerken en ontdubbelen van opdrachten (§8.4, §9.4, FR-B6).
 */
class IngestSettings extends Settings
{
    public int $default_poll_minutes;

    public int $min_poll_minutes;

    public int $max_items_per_run;

    /**
     * Voorfilter op titel en tekst: zonder één van deze termen wordt een publicatie als
     * "niet relevant" gemarkeerd zonder AI-aanroep (FR-O4, §18.3).
     *
     * @var list<string>
     */
    public array $relevance_keywords;

    /** Onder dit aantal tekens haalt de app de volledige openbare opdrachtpagina op (§8.3). */
    public int $full_text_min_chars;

    public float $dedupe_title_similarity;

    public int $dedupe_start_window_days;

    public int $close_after_unseen_days;

    public int $health_empty_hours;

    public int $health_max_failures;

    public static function group(): string
    {
        return 'ingest';
    }
}

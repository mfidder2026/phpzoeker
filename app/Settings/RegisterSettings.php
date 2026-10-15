<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class RegisterSettings extends Settings
{
    public int $default_response_term_days;

    public int $follow_up_term_days;

    public int $no_response_after_follow_ups;

    public int $no_response_after_workdays;

    /** HH:MM */
    public string $expected_response_time;

    /** Waarschuwing bij een vergelijkbare aanbieding binnen dit aantal dagen (§11.4). */
    public int $similar_offer_days;

    /** Titelsimilariteit (0–1) voor die waarschuwing. */
    public float $similar_offer_title_similarity;

    /** Geldigheid van de akkoordlink voor de kandidaat (§11.3). */
    public int $consent_link_hours;

    public static function group(): string
    {
        return 'register';
    }
}

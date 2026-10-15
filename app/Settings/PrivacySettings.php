<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Bewaartermijnen (§19.3), in dagen of maanden zoals in de naam aangegeven.
 */
class PrivacySettings extends Settings
{
    public int $raw_postings_days;

    public int $closed_assignments_months;

    public int $matches_months;

    public int $offers_months;

    public int $contacts_months;

    public int $ai_runs_months;

    public int $web_research_days;

    public static function group(): string
    {
        return 'privacy';
    }
}

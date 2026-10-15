<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class NotificationSettings extends Settings
{
    /** HH:MM, werkdagen */
    public string $digest_time;

    /** @var list<string> */
    public array $digest_recipients;

    public bool $digest_to_candidates;

    public ?string $teams_webhook_url;

    /** Directe melding bij een sterke match ook per mail (naast de melding in de app). */
    public bool $direct_mail;

    /** Een sterke match met een deadline binnen dit aantal uur is urgent (FR-N2). */
    public int $urgent_deadline_hours;

    /** Herinnering voor shortlist-matches met een deadline binnen dit aantal uur (FR-N3). */
    public int $reminder_deadline_hours;

    public static function group(): string
    {
        return 'notifications';
    }
}

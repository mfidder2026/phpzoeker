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

    public static function group(): string
    {
        return 'notifications';
    }
}

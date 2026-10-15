<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public string $organization_name;

    public string $admin_email;

    /** Tijdstip dat een deadline zonder tijd krijgt (§9.2), HH:MM. */
    public string $default_deadline_time;

    /**
     * Landfilter; v1 alleen Nederland (§3).
     *
     * @var list<string>
     */
    public array $countries;

    public static function group(): string
    {
        return 'general';
    }
}

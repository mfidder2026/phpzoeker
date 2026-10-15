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

    /** Tenant-ID van Microsoft 365 waaruit ingelogd mag worden (gezet bij de eerste inlog van een beheerder). */
    public ?string $microsoft_tenant_id;

    public static function group(): string
    {
        return 'general';
    }
}

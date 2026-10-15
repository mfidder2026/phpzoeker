<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class CvSettings extends Settings
{
    /** fremen | candidate (§12.6) */
    public string $contact_line;

    public string $fremen_contact_name;

    public string $fremen_contact_email;

    public string $fremen_contact_phone;

    public bool $show_birth_date;

    public bool $show_city;

    /** always | onsite_hybrid | never */
    public string $show_driving_license;

    public string $footer_text;

    public static function group(): string
    {
        return 'cv';
    }
}

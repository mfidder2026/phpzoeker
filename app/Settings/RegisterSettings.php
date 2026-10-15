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

    public static function group(): string
    {
        return 'register';
    }
}

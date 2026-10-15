<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class MailSettings extends Settings
{
    /** graph | gmail | smtp */
    public string $provider;

    public string $sender_mailbox;

    public string $sender_name;

    /** @var list<string> */
    public array $default_offer_cc;

    public bool $skip_cc_to_sender;

    public ?string $bcc_archive;

    public string $signature_html;

    public string $signature_text;

    public bool $require_candidate_consent;

    public string $alerts_mailbox;

    public static function group(): string
    {
        return 'mail';
    }
}

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

    /** Maximale totale grootte van de bijlagen (§13.4). */
    public int $max_attachments_mb;

    /** Ook het DOCX van het CV standaard meesturen. */
    public bool $attach_docx;

    /** Vernieuwingstoken van de Microsoft 365-login voor verzenden (versleuteld, nooit naar de browser). */
    public ?string $graph_refresh_token;

    public ?string $graph_tenant;

    /** Account waarmee is ingelogd voor verzenden. */
    public ?string $graph_account;

    public ?string $graph_connected_at;

    /**
     * @return list<string>
     */
    public static function encrypted(): array
    {
        return ['graph_refresh_token'];
    }

    public static function group(): string
    {
        return 'mail';
    }
}

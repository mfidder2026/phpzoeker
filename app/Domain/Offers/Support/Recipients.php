<?php

namespace App\Domain\Offers\Support;

use App\Domain\Offers\Models\Offer;
use App\Settings\MailSettings;

/**
 * Ontvangers van de aanbiedingsmail (§13.1, R5). To komt alleen uit gevalideerde velden: een
 * adres dat letterlijk in de opdrachttekst of de bronmetadata staat, een bekende contactpersoon,
 * of een handmatig ingevoerd en bevestigd adres.
 */
class Recipients
{
    public const ORIGIN_SOURCE = 'bronmetadata';

    public const ORIGIN_TEXT = 'opdrachttekst';

    public const ORIGIN_CONTACT = 'contactpersoon';

    public const ORIGIN_MANUAL = 'handmatig';

    public function __construct(private readonly MailSettings $settings) {}

    /**
     * Voorgestelde To-adressen met hun herkomst.
     *
     * @return array<string, string> adres => herkomst
     */
    public function suggestions(Offer $offer): array
    {
        $offer->loadMissing(['assignmentSource', 'contact', 'organization.contacts', 'assignment.sources']);
        $suggestions = [];

        $add = function (?string $email, string $origin) use (&$suggestions): void {
            $email = self::normalize($email);

            if ($email !== null && ! isset($suggestions[$email])) {
                $suggestions[$email] = $origin;
            }
        };

        $add($offer->assignmentSource?->contact_email, self::ORIGIN_SOURCE);
        $add($offer->contact?->email, self::ORIGIN_CONTACT);

        foreach ($offer->assignment->sources as $source) {
            $add($source->contact_email, self::ORIGIN_SOURCE);
        }

        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', (string) $offer->assignment->description_text, $matches);

        foreach ($matches[0] as $email) {
            $add($email, self::ORIGIN_TEXT);
        }

        foreach ($offer->organization->contacts ?? [] as $contact) {
            $add($contact->email, self::ORIGIN_CONTACT);
        }

        return $suggestions;
    }

    /**
     * Standaard-CC (§13.1): de instelbare lijst, plus de kandidaat als die dat wil.
     *
     * @param  list<string>  $to
     * @return list<string>
     */
    public function defaultCc(Offer $offer, array $to = []): array
    {
        $cc = $this->settings->default_offer_cc;

        if ($offer->candidate->cc_self_on_offers && filled($offer->candidate->email)) {
            $cc[] = (string) $offer->candidate->email;
        }

        if ($this->settings->skip_cc_to_sender) {
            $cc = array_filter($cc, fn (string $email): bool => self::normalize($email) !== self::normalize($this->settings->sender_mailbox));
        }

        return self::dedupe($cc, $to);
    }

    /**
     * @return list<string>
     */
    public function defaultBcc(): array
    {
        $bcc = self::normalize($this->settings->bcc_archive);

        return $bcc === null ? [] : [$bcc];
    }

    /**
     * Herkomst per To-adres; null als een adres niet uit een gevalideerde bron komt en niet
     * handmatig is bevestigd.
     *
     * @param  list<string>  $to
     * @return array<string, string>|null
     */
    public function origins(Offer $offer, array $to, bool $manualConfirmed): ?array
    {
        $suggestions = $this->suggestions($offer);
        $origins = [];

        foreach ($to as $email) {
            $normalized = self::normalize($email);

            if ($normalized === null) {
                return null;
            }

            if (isset($suggestions[$normalized])) {
                $origins[$normalized] = $suggestions[$normalized];
            } elseif ($manualConfirmed) {
                $origins[$normalized] = self::ORIGIN_MANUAL;
            } else {
                return null;
            }
        }

        return $origins;
    }

    public static function normalize(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /**
     * Unieke, geldige adressen die niet al in `$exclude` staan (To wint van CC, §13.1).
     *
     * @param  array<int, string|null>  $emails
     * @param  array<int, string>  $exclude
     * @return list<string>
     */
    public static function dedupe(array $emails, array $exclude = []): array
    {
        $excluded = array_filter(array_map(self::normalize(...), $exclude));
        $result = [];

        foreach ($emails as $email) {
            $normalized = self::normalize($email);

            if ($normalized !== null && ! in_array($normalized, $excluded, true) && ! in_array($normalized, $result, true)) {
                $result[] = $normalized;
            }
        }

        return $result;
    }
}

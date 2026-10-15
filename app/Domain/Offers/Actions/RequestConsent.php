<?php

namespace App\Domain\Offers\Actions;

use App\Domain\Offers\Enums\OfferStatus;
use App\Domain\Offers\Enums\OutboundEmailStatus;
use App\Domain\Offers\Enums\OutboundEmailType;
use App\Domain\Offers\Models\Offer;
use App\Domain\Offers\Models\OutboundEmail;
use App\Domain\Offers\Support\Recipients;
use App\Models\User;
use App\Settings\MailSettings;
use App\Settings\RegisterSettings;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Vraagt de kandidaat om akkoord met een link die 72 uur geldig is (§11.3). Gaat direct na het
 * goedkeuren van de aanbiedingsmail (een menselijke actie) naar het eigen adres van de kandidaat.
 */
class RequestConsent
{
    public function __construct(
        private readonly SendOutboundEmail $sender,
        private readonly MailSettings $mail,
        private readonly RegisterSettings $register,
    ) {}

    public static function url(Offer $offer, int $hours): string
    {
        return URL::temporarySignedRoute('offers.consent', now()->addHours($hours), ['offer' => $offer->id]);
    }

    public function handle(Offer $offer, User $user): ?OutboundEmail
    {
        if ($offer->status !== OfferStatus::WachtOpKandidaat) {
            return null;
        }

        $to = Recipients::normalize($offer->candidate->email);

        if ($to === null) {
            throw new InvalidArgumentException("{$offer->candidate->name} heeft geen e-mailadres; registreer het akkoord handmatig.");
        }

        $url = self::url($offer, $this->register->consent_link_hours);
        $firstName = explode(' ', $offer->candidate->name)[0];
        $text = "Hoi {$firstName},\n\n"
            ."We willen je aanbieden op \"{$offer->assignment->title}\"".($offer->assignment->client_name ? " bij {$offer->assignment->client_name}" : '').' via '.$offer->viaName().".\n"
            .'Tarief: € '.number_format((float) $offer->offered_rate, 2, ',', '.')." per uur, exclusief btw.\n\n"
            .'Ga je akkoord? Geef het aan via deze link (geldig tot '.now()->addHours($this->register->consent_link_hours)->format('d-m-Y H:i')."):\n{$url}\n\n"
            ."Groet,\n{$this->mail->sender_name}";

        $email = $offer->emails()->create([
            'type' => OutboundEmailType::Consent,
            'from' => $this->mail->sender_mailbox,
            'from_name' => $this->mail->sender_name,
            'to' => [$to],
            'cc' => [],
            'bcc' => [],
            'recipient_origins' => [$to => 'kandidaat'],
            'subject' => "Akkoord gevraagd: aanbieding op {$offer->assignment->title}",
            'body_text' => $text."\n",
            'body_html' => OfferWorkflow::textToHtml($text),
            'status' => OutboundEmailStatus::Goedgekeurd,
            'approved_by' => $user->id,
            'approved_at' => now(),
            'send_key' => (string) Str::uuid(),
        ]);

        return $this->sender->handle($email, $user);
    }
}

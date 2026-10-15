<?php

namespace App\Domain\Offers\Actions;

use App\Domain\Offers\Enums\OutboundEmailStatus;
use App\Domain\Offers\Enums\OutboundEmailType;
use App\Domain\Offers\Models\Offer;
use App\Domain\Offers\Models\OutboundEmail;
use App\Settings\MailSettings;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * "Opvolgmail maken" (§11.6): concept aan dezelfde ontvangers en CC, als antwoord in dezelfde
 * conversatie. Versturen blijft een menselijke stap (R1).
 */
class CreateFollowUpMail
{
    public function __construct(private readonly MailSettings $settings) {}

    public function handle(Offer $offer): OutboundEmail
    {
        $original = $offer->emails()->where('type', OutboundEmailType::Offer->value)->where('status', OutboundEmailStatus::Verzonden->value)->first();

        if ($original === null) {
            throw new InvalidArgumentException('Er is geen verzonden aanbiedingsmail om op te volgen.');
        }

        $existing = $offer->emails()->where('type', OutboundEmailType::FollowUp->value)->where('status', OutboundEmailStatus::Concept->value)->first();

        if ($existing !== null) {
            return $existing;
        }

        $english = $offer->assignment->language === 'en';
        $greeting = (string) (preg_match('/^(.*?,)\s*$/m', $original->body_text, $match) ? $match[1] : ($english ? 'Dear Sir or Madam,' : 'Beste,'));
        $date = $offer->sent_at?->format('d-m-Y');
        $text = $english
            ? "{$greeting}\n\nOn {$date} we proposed {$offer->candidate->name} for \"{$offer->assignment->title}\". We would like to know whether you have had the opportunity to review the proposal, and whether an introductory meeting would be possible.\n\nKind regards,\n\n".($this->settings->signature_text ?: $this->settings->sender_name)
            : "{$greeting}\n\nOp {$date} hebben wij {$offer->candidate->name} aangeboden voor \"{$offer->assignment->title}\". Graag horen wij of u de aanbieding al heeft kunnen bekijken en of een kennismakingsgesprek mogelijk is.\n\nMet vriendelijke groet,\n\n".($this->settings->signature_text ?: $this->settings->sender_name);

        return $offer->emails()->create([
            'type' => OutboundEmailType::FollowUp,
            'from' => $original->from,
            'from_name' => $original->from_name,
            'to' => $original->to,
            'cc' => $original->cc,
            'bcc' => $original->bcc,
            'recipient_origins' => $original->recipient_origins,
            'subject' => str_starts_with(mb_strtolower($original->subject), 're:') ? $original->subject : 'Re: '.$original->subject,
            'body_text' => $text."\n",
            'body_html' => OfferWorkflow::textToHtml($text),
            'status' => OutboundEmailStatus::Concept,
            'send_key' => (string) Str::uuid(),
            'in_reply_to' => $original->internet_message_id,
            'conversation_id' => $original->conversation_id,
        ]);
    }
}

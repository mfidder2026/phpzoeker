<?php

namespace App\Domain\Offers\Actions;

use App\Domain\Offers\Enums\ConsentStatus;
use App\Domain\Offers\Enums\OfferActivityType;
use App\Domain\Offers\Enums\OfferChannel;
use App\Domain\Offers\Enums\OfferStatus;
use App\Domain\Offers\Enums\OutboundEmailStatus;
use App\Domain\Offers\Enums\OutboundEmailType;
use App\Domain\Offers\Models\Offer;
use App\Domain\Offers\Models\OfferSnapshot;
use App\Domain\Offers\Models\OutboundEmail;
use App\Domain\Offers\Models\TailoredCv;
use App\Domain\Offers\Support\Recipients;
use App\Domain\Offers\Support\ResponseTerm;
use App\Domain\Offers\Support\Workdays;
use App\Models\User;
use App\Settings\MailSettings;
use App\Settings\RegisterSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * De menselijke stappen in de wizard en het register (§11): goedkeuren, akkoord van de
 * kandidaat, indienen via een platform, notities, intake, reactietermijn en intrekken.
 */
class OfferWorkflow
{
    public function __construct(
        private readonly TransitionOffer $transitions,
        private readonly Recipients $recipients,
        private readonly MailSettings $mailSettings,
        private readonly RegisterSettings $registerSettings,
        private readonly ResponseTerm $responseTerm,
        private readonly CaptureOfferSnapshot $snapshots,
    ) {}

    /**
     * Goedkeuren kan alleen als de grounding-check is gehaald (FR-C2).
     */
    public function approveCv(TailoredCv $cv, User $user): void
    {
        if (! $cv->grounding_passed) {
            throw new InvalidArgumentException('Goedkeuren kan pas als de grounding-check is gehaald.');
        }

        if ($cv->pdf_path === null && $cv->docx_path === null) {
            throw new InvalidArgumentException('Er is nog geen Word- of PDF-bestand van dit CV.');
        }

        $cv->forceFill(['approved_by' => $user->id, 'approved_at' => now()])->save();
        TransitionOffer::record($cv->offer, OfferActivityType::CvApproved, $user, ['text' => "CV v{$cv->version} goedgekeurd", 'version' => $cv->version]);
    }

    /**
     * Mailvelden bewerken (FR-E2). To wordt gecontroleerd tegen gevalideerde bronnen (R5).
     *
     * @param  array<string, mixed>  $data  to, cc, bcc (lijsten met adressen), subject, body_text, body_html, manual_confirmed
     */
    public function updateMail(OutboundEmail $email, array $data): OutboundEmail
    {
        if (! $email->isEditable()) {
            throw new InvalidArgumentException('Deze mail is al goedgekeurd of verzonden.');
        }

        $to = Recipients::dedupe(array_values((array) ($data['to'] ?? [])));

        if ($to === []) {
            throw new InvalidArgumentException('Vul minimaal één geldige ontvanger in.');
        }

        $origins = $email->offer !== null && $email->type->value === 'offer'
            ? $this->recipients->origins($email->offer, $to, (bool) ($data['manual_confirmed'] ?? false))
            : array_fill_keys($to, 'interne cc-lijst');

        if ($origins === null) {
            throw new InvalidArgumentException('Een ontvanger staat niet in de opdracht of de bron. Bevestig dat je het adres zelf hebt gecontroleerd.');
        }

        $text = (string) ($data['body_text'] ?? '');
        $html = filled($data['body_html'] ?? null) && $text === $email->body_text
            ? (string) $data['body_html']
            : self::textToHtml($text);

        $email->forceFill([
            'to' => $to,
            'cc' => Recipients::dedupe(array_values((array) ($data['cc'] ?? [])), $to),
            'bcc' => Recipients::dedupe(array_values((array) ($data['bcc'] ?? [])), $to),
            'recipient_origins' => $origins,
            'subject' => trim((string) ($data['subject'] ?? '')),
            'body_text' => rtrim($text)."\n",
            'body_html' => $html,
        ])->save();

        return $email;
    }

    /**
     * Mail goedkeuren (§13.5 stap 2). Daarna: akkoord van de kandidaat vragen (§11.3) of klaar
     * voor verzending.
     */
    public function approveMail(OutboundEmail $email, User $user): void
    {
        if (! $email->isEditable()) {
            throw new InvalidArgumentException('Deze mail is al goedgekeurd of verzonden.');
        }

        if ($email->attachmentBytes() > $this->mailSettings->max_attachments_mb * 1024 * 1024) {
            throw new InvalidArgumentException("De bijlagen zijn samen groter dan {$this->mailSettings->max_attachments_mb} MB.");
        }

        $offer = $email->offer ?? throw new InvalidArgumentException('Deze mail hoort niet bij een aanbieding.');

        DB::transaction(function () use ($email, $user, $offer): void {
            $email->forceFill(['status' => OutboundEmailStatus::Goedgekeurd, 'approved_by' => $user->id, 'approved_at' => now()])->save();

            if (! in_array($email->type, [OutboundEmailType::Offer, OutboundEmailType::InternalPackage], true)) {
                return;
            }

            $offer->forceFill(['approved_by' => $user->id, 'approved_at' => now()])->save();
            TransitionOffer::record($offer, OfferActivityType::MailApproved, $user, ['text' => 'Mail goedgekeurd: '.$email->subject]);

            $needsConsent = $offer->consent_status === ConsentStatus::Gevraagd;
            $this->transitions->handle($offer, $needsConsent ? OfferStatus::WachtOpKandidaat : OfferStatus::KlaarVoorVerzending, $user);
        });
    }

    /**
     * Terug naar concept, bijv. om de mail nog aan te passen.
     */
    public function reopenMail(OutboundEmail $email, User $user): void
    {
        if ($email->status !== OutboundEmailStatus::Goedgekeurd) {
            return;
        }

        $email->forceFill(['status' => OutboundEmailStatus::Concept, 'approved_by' => null, 'approved_at' => null])->save();

        if ($email->offer !== null && in_array($email->offer->status, [OfferStatus::WachtOpKandidaat, OfferStatus::KlaarVoorVerzending], true)) {
            $this->transitions->handle($email->offer, OfferStatus::MailInReview, $user, 'Mail weer bewerkbaar gemaakt');
        }
    }

    /**
     * Akkoord (of weigering) van de kandidaat, via de link of mondeling (§11.3).
     */
    public function registerConsent(Offer $offer, ConsentStatus $status, ?User $user, ?string $note = null): void
    {
        if (! in_array($status, [ConsentStatus::Akkoord, ConsentStatus::Mondeling, ConsentStatus::Geweigerd], true)) {
            throw new InvalidArgumentException('Ongeldige keuze.');
        }

        $offer->forceFill(['consent_status' => $status, 'consent_at' => now(), 'consent_note' => $note])->save();
        TransitionOffer::record($offer, OfferActivityType::Consent, $user, ['text' => $status->getLabel().(filled($note) ? ' — '.$note : ''), 'status' => $status->value]);

        if ($offer->status !== OfferStatus::WachtOpKandidaat) {
            return;
        }

        $this->transitions->handle($offer, $status === ConsentStatus::Geweigerd ? OfferStatus::Ingetrokken : OfferStatus::KlaarVoorVerzending, $user, $status === ConsentStatus::Geweigerd ? 'Kandidaat niet akkoord' : null);
    }

    /**
     * Platformindiening afronden: pas na het externe referentienummer "verzonden" (§13.6).
     */
    public function markSubmitted(Offer $offer, string $reference, User $user): void
    {
        if ($offer->channel !== OfferChannel::Platform) {
            throw new InvalidArgumentException('Dit is geen platformindiening.');
        }

        if ($offer->status !== OfferStatus::KlaarVoorVerzending) {
            throw new InvalidArgumentException('Verstuur eerst de interne mail met het indieningspakket.');
        }

        $internal = $offer->offerEmail();

        if ($internal === null || $internal->status !== OutboundEmailStatus::Verzonden) {
            throw new InvalidArgumentException('Verstuur eerst de interne mail met het indieningspakket.');
        }

        DB::transaction(function () use ($offer, $reference, $user, $internal): void {
            $offer->forceFill(['external_reference' => trim($reference), 'sent_at' => now(), 'sent_by' => $user->id]);
            $this->responseTerm->apply($offer);
            $offer->save();

            $this->transitions->handle($offer, OfferStatus::Verzonden, $user, 'Ingediend via platform', ['external_reference' => $reference]);
            TransitionOffer::record($offer, OfferActivityType::Sent, $user, ['text' => 'Ingediend via '.$offer->viaName().', referentie '.$reference]);
            $this->snapshots->handle($offer->refresh(), $internal, $user, OfferSnapshot::KIND_PLATFORM);
        });
    }

    public function addNote(Offer $offer, string $text, User $user): void
    {
        TransitionOffer::record($offer, OfferActivityType::Note, $user, ['text' => trim($text)]);
    }

    /**
     * Verwachte reactiedatum aanpassen met reden (FR-AR5).
     */
    public function adjustResponseTerm(Offer $offer, int $days, string $reason, User $user): void
    {
        $this->responseTerm->apply($offer, $days, $reason);
        $offer->forceFill(['overdue_notified_at' => null])->save();

        TransitionOffer::record($offer, OfferActivityType::ResponseTerm, $user, [
            'expected_response_at' => $offer->expected_response_at?->setTimezone(ResponseTerm::TIMEZONE)->format('d-m-Y H:i'),
            'days' => $days,
            'reason' => $reason,
        ]);
    }

    /**
     * Reactie ontvangen (handmatig vastgelegd): reactietijd in werkdagen voor de statistiek (§11.6).
     */
    public function recordResponse(Offer $offer, User $user, ?string $note = null): void
    {
        // Een reactie die reply-tracking al vond, houdt haar eigen tijdstip.
        $offer->forceFill($offer->response_received_at !== null ? [] : [
            'response_received_at' => now(),
            'response_time_workdays' => $offer->sent_at !== null ? Workdays::between(CarbonImmutable::parse($offer->sent_at), CarbonImmutable::now()) : null,
        ])->save();

        TransitionOffer::record($offer, OfferActivityType::ReplyReceived, $user, ['text' => 'Reactie ontvangen'.(filled($note) ? ': '.$note : '')]);

        if (in_array($offer->status, [OfferStatus::Verzonden, OfferStatus::GeenReactie], true)) {
            $this->transitions->handle($offer, OfferStatus::ReactieOntvangen, $user, $note);
        }
    }

    /**
     * @param  array<string, mixed>  $data  at (datum en tijd), location, participants
     */
    public function recordIntake(Offer $offer, array $data, User $user): void
    {
        $at = CarbonImmutable::parse((string) $data['at'], ResponseTerm::TIMEZONE);

        if ($offer->response_received_at === null) {
            $offer->forceFill([
                'response_received_at' => now(),
                'response_time_workdays' => $offer->sent_at !== null ? Workdays::between(CarbonImmutable::parse($offer->sent_at), CarbonImmutable::now()) : null,
            ]);
        }

        $offer->forceFill(['intake_at' => $at, 'intake' => ['location' => $data['location'] ?? null, 'participants' => $data['participants'] ?? null]])->save();

        TransitionOffer::record($offer, OfferActivityType::Intake, $user, ['at' => $at->format('d-m-Y H:i'), 'location' => $data['location'] ?? null, 'participants' => $data['participants'] ?? null]);

        if ($offer->status !== OfferStatus::IntakeGepland) {
            $this->transitions->handle($offer, OfferStatus::IntakeGepland, $user);
        }
    }

    public function withdraw(Offer $offer, string $reason, User $user): void
    {
        $this->transitions->handle($offer, OfferStatus::Ingetrokken, $user, $reason);
    }

    /**
     * Vrije statuswijziging in het register (FR-AR7), langs de statusmachine.
     */
    public function changeStatus(Offer $offer, OfferStatus $status, ?string $reason, User $user): void
    {
        $this->transitions->handle($offer, $status, $user, $reason);
    }

    public static function textToHtml(string $text): string
    {
        $paragraphs = preg_split("/\n{2,}/", trim($text)) ?: [];

        return '<!DOCTYPE html><html><body style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 1.5;">'
            .implode('', array_map(fn (string $paragraph): string => '<p>'.nl2br(e($paragraph)).'</p>', $paragraphs))
            .'</body></html>';
    }

    /**
     * Na hoeveel werkdagen na de verwachte reactie de status "geen reactie" wordt voorgesteld.
     */
    public function suggestsNoResponse(Offer $offer): bool
    {
        return $offer->status === OfferStatus::Verzonden
            && $offer->follow_up_count >= $this->registerSettings->no_response_after_follow_ups
            && $offer->sent_at !== null
            && Workdays::between(CarbonImmutable::parse($offer->sent_at), CarbonImmutable::now()) >= $this->registerSettings->no_response_after_workdays;
    }
}

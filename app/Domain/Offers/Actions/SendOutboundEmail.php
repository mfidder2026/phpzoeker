<?php

namespace App\Domain\Offers\Actions;

use App\Domain\Offers\Enums\OfferActivityType;
use App\Domain\Offers\Enums\OfferStatus;
use App\Domain\Offers\Enums\OutboundEmailStatus;
use App\Domain\Offers\Enums\OutboundEmailType;
use App\Domain\Offers\Mailers\OutboundMailer;
use App\Domain\Offers\Models\OfferSnapshot;
use App\Domain\Offers\Models\OutboundEmail;
use App\Domain\Offers\Support\ResponseTerm;
use App\Domain\Offers\Support\Workdays;
use App\Models\User;
use App\Settings\RegisterSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Verstuurt een goedgekeurde mail (§13.5 stap 3–4). Alleen na menselijke goedkeuring (R1);
 * idempotent via `send_key` en een lock, zodat een herhaling nooit twee mails stuurt. Een
 * aanbiedingsmail zet de aanbieding op "verzonden" en legt in dezelfde transactie de snapshot
 * vast (§11.7).
 */
class SendOutboundEmail
{
    public function __construct(
        private readonly OutboundMailer $mailer,
        private readonly TransitionOffer $transitions,
        private readonly ResponseTerm $responseTerm,
        private readonly CaptureOfferSnapshot $snapshots,
        private readonly RegisterSettings $settings,
    ) {}

    public function handle(OutboundEmail $email, User $user): OutboundEmail
    {
        $lock = Cache::lock('outbound-email:'.$email->send_key, 120);

        if (! $lock->get()) {
            throw new RuntimeException('Deze mail wordt al verstuurd.');
        }

        try {
            $email->refresh();

            if ($email->status === OutboundEmailStatus::Verzonden) {
                return $email;
            }

            $this->guard($email);

            $email->forceFill(['status' => OutboundEmailStatus::Verzenden, 'error' => null])->save();

            try {
                $result = $this->mailer->send($email);
            } catch (RuntimeException $exception) {
                $email->forceFill(['status' => OutboundEmailStatus::Fout, 'error' => mb_substr($exception->getMessage(), 0, 2000)])->save();

                if ($email->offer !== null) {
                    TransitionOffer::record($email->offer, OfferActivityType::Error, $user, ['text' => 'Versturen mislukt: '.mb_substr($exception->getMessage(), 0, 300)]);
                }

                throw $exception;
            }

            // Eerst de mail als verzonden vastleggen: een herhaling mag nooit een tweede mail sturen.
            $email->forceFill([
                'status' => OutboundEmailStatus::Verzonden,
                'provider' => $result['provider'],
                'provider_message_id' => $result['provider_message_id'],
                'internet_message_id' => $result['internet_message_id'],
                'conversation_id' => $result['conversation_id'],
                'raw_mime' => $result['raw_mime'],
                'sent_by' => $user->id,
                'sent_at' => now(),
            ])->save();

            // Statuswijziging en snapshot in één transactie (§16.4).
            DB::transaction(fn () => $this->afterSend($email, $user));

            return $email;
        } finally {
            $lock->release();
        }
    }

    private function guard(OutboundEmail $email): void
    {
        if ($email->status !== OutboundEmailStatus::Goedgekeurd || $email->approved_by === null) {
            throw new InvalidArgumentException('Deze mail is nog niet goedgekeurd door een mens (R1).');
        }

        $offer = $email->offer;

        if ($offer === null) {
            return;
        }

        $allowed = match ($email->type) {
            OutboundEmailType::Offer, OutboundEmailType::InternalPackage => $offer->status === OfferStatus::KlaarVoorVerzending,
            OutboundEmailType::FollowUp => $offer->status->isOpen(),
            OutboundEmailType::Consent => $offer->status === OfferStatus::WachtOpKandidaat,
        };

        if (! $allowed) {
            throw new InvalidArgumentException("Versturen kan niet in de status \"{$offer->status->getLabel()}\".");
        }
    }

    private function afterSend(OutboundEmail $email, User $user): void
    {
        $offer = $email->offer;

        if ($offer === null) {
            return;
        }

        $recipients = ['to' => $email->to, 'cc' => $email->cc ?? []];

        match ($email->type) {
            OutboundEmailType::Offer => (function () use ($offer, $email, $user, $recipients): void {
                $offer->forceFill(['sent_at' => $email->sent_at, 'sent_by' => $user->id]);
                $this->responseTerm->apply($offer);
                $offer->save();

                $this->transitions->handle($offer, OfferStatus::Verzonden, $user);
                TransitionOffer::record($offer, OfferActivityType::Sent, $user, [...$recipients, 'message_id' => $email->internet_message_id]);
                $this->snapshots->handle($offer->refresh(), $email, $user, OfferSnapshot::KIND_EMAIL);
            })(),
            OutboundEmailType::FollowUp => (function () use ($offer, $user): void {
                $expected = Workdays::add(CarbonImmutable::now(ResponseTerm::TIMEZONE), $this->settings->follow_up_term_days, $this->settings->expected_response_time);
                $offer->forceFill([
                    'follow_up_count' => $offer->follow_up_count + 1,
                    'last_follow_up_at' => now(),
                    'expected_response_at' => $expected,
                    'overdue_notified_at' => null,
                ])->save();

                TransitionOffer::record($offer, OfferActivityType::FollowUp, $user, ['expected_response_at' => $expected->format('d-m-Y H:i')]);
            })(),
            OutboundEmailType::InternalPackage => TransitionOffer::record($offer, OfferActivityType::Note, $user, ['text' => 'Interne mail met indieningspakket verzonden naar '.implode(', ', $email->to)]),
            OutboundEmailType::Consent => TransitionOffer::record($offer, OfferActivityType::Consent, $user, ['text' => 'Akkoord gevraagd aan de kandidaat ('.implode(', ', $email->to).')']),
        };
    }
}

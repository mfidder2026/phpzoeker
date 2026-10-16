<?php

namespace App\Domain\Offers\Actions;

use App\Domain\Notifications\TeamsNotifier;
use App\Domain\Offers\Enums\OfferActivityType;
use App\Domain\Offers\Enums\OfferStatus;
use App\Domain\Offers\Enums\OutboundEmailStatus;
use App\Domain\Offers\Mailers\MicrosoftGraphMailer;
use App\Domain\Offers\Models\Offer;
use App\Domain\Offers\Models\OutboundEmail;
use App\Domain\Offers\Notifications\ReplyReceivedNotification;
use App\Domain\Offers\Support\Workdays;
use App\Models\User;
use App\Settings\GeneralSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Reply-tracking (§13.5, FR-E4): zoekt in de afzendermailbox naar berichten in de conversatie van
 * een verzonden aanbieding. Een nieuwe reactie komt in de tijdlijn, telt mee voor de reactietijd
 * en geeft een melding; de statuswijziging stelt de app alleen voor, een mens bevestigt.
 */
class TrackReplies
{
    /** Hoe lang na verzending de app naar reacties blijft zoeken. */
    public const TRACK_DAYS = 60;

    public function __construct(
        private readonly MicrosoftGraphMailer $mailer,
        private readonly GeneralSettings $settings,
    ) {}

    /**
     * @return int aantal nieuwe reacties
     */
    public function handle(): int
    {
        $found = 0;

        $emails = OutboundEmail::query()
            ->with('offer.candidate', 'offer.assignment', 'offer.sentBy', 'offer.organization', 'offer.assignmentSource')
            ->where('provider', 'graph')
            ->where('status', OutboundEmailStatus::Verzonden->value)
            ->whereNotNull('conversation_id')
            ->where('sent_at', '>=', now()->subDays(self::TRACK_DAYS))
            ->whereHas('offer', fn ($query) => $query->whereIn('status', [OfferStatus::Verzonden->value, OfferStatus::ReactieOntvangen->value, OfferStatus::IntakeGepland->value, OfferStatus::GeenReactie->value]))
            ->get()
            ->unique('conversation_id');

        foreach ($emails as $email) {
            try {
                $found += $this->track($email);
            } catch (RuntimeException $exception) {
                report($exception);
            }
        }

        return $found;
    }

    private function track(OutboundEmail $email): int
    {
        $offer = $email->offer ?? throw new RuntimeException('Mail zonder aanbieding.');
        $ours = OutboundEmail::query()->where('conversation_id', $email->conversation_id)->pluck('internet_message_id')->filter()->all();
        $known = $offer->activities()->where('type', OfferActivityType::ReplyReceived->value)->get()->pluck('payload.message_id')->filter()->all();
        $internal = '@'.mb_strtolower(Str::after($email->from, '@'));
        $new = 0;

        foreach ($this->mailer->conversationMessages($email->from, (string) $email->conversation_id) as $message) {
            $isOwn = in_array($message['internet_message_id'], $ours, true) || str_ends_with($message['from'], $internal) || $message['from'] === mb_strtolower($email->from);

            if ($isOwn || in_array($message['internet_message_id'], $known, true)) {
                continue;
            }

            $receivedAt = ($message['received_at'] !== '' ? CarbonImmutable::parse($message['received_at']) : CarbonImmutable::now())->setTimezone((string) config('app.timezone'));

            TransitionOffer::record($offer, OfferActivityType::ReplyReceived, null, [
                'text' => 'Reactie van '.$message['from'].': '.$message['subject'],
                'from' => $message['from'],
                'subject' => $message['subject'],
                'preview' => $message['preview'],
                'message_id' => $message['internet_message_id'],
                'suggested_status' => $offer->status === OfferStatus::Verzonden || $offer->status === OfferStatus::GeenReactie ? OfferStatus::ReactieOntvangen->value : null,
            ], $receivedAt);

            if ($offer->response_received_at === null) {
                $offer->forceFill([
                    'response_received_at' => $receivedAt,
                    'response_time_workdays' => $offer->sent_at !== null ? Workdays::between(CarbonImmutable::parse($offer->sent_at), $receivedAt) : null,
                ])->save();
            }

            $recipients = User::query()->whereKey(array_filter([$offer->sent_by]))->orWhere('email', $this->settings->admin_email)->get();

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new ReplyReceivedNotification($offer, $message['from'], $message['subject'], $message['preview']));
            }

            $notification = new ReplyReceivedNotification($offer, $message['from'], $message['subject'], $message['preview']);
            app(TeamsNotifier::class)->send($notification->title(), $message['from'].': '.$message['subject'], $notification->url(), 'Aanbieding openen');

            $new++;
        }

        return $new;
    }

    /**
     * De laatste reactie die nog niet tot een statuswijziging heeft geleid, voor het voorstel in
     * het register.
     *
     * @return array<string, mixed>|null
     */
    public static function pendingSuggestion(Offer $offer): ?array
    {
        if (! in_array($offer->status, [OfferStatus::Verzonden, OfferStatus::GeenReactie], true)) {
            return null;
        }

        $reply = $offer->activities()->where('type', OfferActivityType::ReplyReceived->value)->first();

        return $reply?->payload !== null && ($reply->payload['suggested_status'] ?? null) !== null ? [...$reply->payload, 'occurred_at' => $reply->occurred_at] : null;
    }
}

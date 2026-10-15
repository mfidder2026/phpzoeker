<?php

namespace App\Domain\Offers\Actions;

use App\Domain\Assignments\Enums\AssignmentStatus;
use App\Domain\Matching\Enums\MatchStatus;
use App\Domain\Offers\Enums\OfferActivityType;
use App\Domain\Offers\Enums\OfferStatus;
use App\Domain\Offers\Models\Offer;
use App\Domain\Offers\Models\OfferActivity;
use App\Models\User;
use InvalidArgumentException;

/**
 * Enige plek waar de status van een aanbieding verandert (§11.1). Weigert overgangen die de
 * statusmachine niet kent en zet elke overgang in de tijdlijn en het audit-log.
 */
class TransitionOffer
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(Offer $offer, OfferStatus $to, ?User $user = null, ?string $reason = null, array $payload = []): Offer
    {
        $from = $offer->status;

        if ($from === $to) {
            return $offer;
        }

        if (! $from->canTransitionTo($to)) {
            throw new InvalidArgumentException("Van \"{$from->getLabel()}\" naar \"{$to->getLabel()}\" is geen geldige stap.");
        }

        $offer->forceFill([
            'status' => $to,
            'status_reason' => $reason,
            'closed_at' => $to->isClosed() ? now() : $offer->closed_at,
        ])->save();

        self::record($offer, OfferActivityType::StatusChange, $user, [
            'from' => $from->value,
            'from_label' => $from->getLabel(),
            'to' => $to->value,
            'to_label' => $to->getLabel(),
            'reason' => $reason,
            ...$payload,
        ]);

        $this->syncMatchAndAssignment($offer, $to);

        return $offer;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function record(Offer $offer, OfferActivityType $type, ?User $user = null, array $payload = [], mixed $occurredAt = null): OfferActivity
    {
        return $offer->activities()->create([
            'type' => $type,
            'payload' => $payload,
            'user_id' => $user?->id,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }

    private function syncMatchAndAssignment(Offer $offer, OfferStatus $to): void
    {
        if ($to === OfferStatus::Verzonden) {
            $offer->match?->setStatus(MatchStatus::Aangeboden);
            $offer->assignment->forceFill(['status' => AssignmentStatus::Aangeboden])->save();
        }

        if ($to === OfferStatus::Ingetrokken && $offer->match !== null && $offer->match->status === MatchStatus::Aangeboden) {
            $offer->match->setStatus(MatchStatus::Shortlist);
        }
    }
}

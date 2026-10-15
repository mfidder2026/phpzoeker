<?php

namespace App\Domain\Offers\Models;

use App\Domain\Offers\Enums\OfferActivityType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Tijdlijn van een aanbieding (§11.5). Alleen toevoegen; notities worden niet gewijzigd.
 *
 * @property int $id
 * @property int $offer_id
 * @property OfferActivityType $type
 * @property array<string, mixed>|null $payload
 * @property int|null $user_id
 * @property Carbon $occurred_at
 * @property-read User|null $user
 * @property-read Offer $offer
 */
class OfferActivity extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => OfferActivityType::class,
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Tijdlijnregels worden niet gewijzigd.');
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Offer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function summary(): string
    {
        $payload = $this->payload ?? [];
        $list = fn (mixed $value): string => implode(', ', array_map('strval', (array) $value));

        return match ($this->type) {
            OfferActivityType::StatusChange => 'Status: '.($payload['from_label'] ?? '?').' → '.($payload['to_label'] ?? '?').(filled($payload['reason'] ?? null) ? ' — '.$payload['reason'] : ''),
            OfferActivityType::Sent => 'Verzonden naar '.$list($payload['to'] ?? []).(filled($payload['cc'] ?? null) ? ' · CC '.$list($payload['cc']) : ''),
            OfferActivityType::FollowUp => 'Opvolgmail verzonden'.(isset($payload['expected_response_at']) ? '; nieuwe verwachte reactie '.$payload['expected_response_at'] : ''),
            OfferActivityType::Intake => 'Intake '.($payload['at'] ?? '').(filled($payload['location'] ?? null) ? ' · '.$payload['location'] : ''),
            OfferActivityType::ResponseTerm => 'Verwachte reactie '.($payload['expected_response_at'] ?? '').(filled($payload['reason'] ?? null) ? ' — '.$payload['reason'] : ''),
            default => (string) ($payload['text'] ?? $this->type->getLabel()),
        };
    }
}

<?php

namespace App\Domain\Offers\Models;

use App\Domain\Offers\Enums\OutboundEmailStatus;
use App\Domain\Offers\Enums\OutboundEmailType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Uitgaande mail (§13.5). Wordt pas verstuurd na goedkeuring door een mens (R1); `send_key`
 * maakt verzenden idempotent.
 *
 * @property int $id
 * @property int|null $offer_id
 * @property OutboundEmailType $type
 * @property string $from
 * @property string|null $from_name
 * @property list<string> $to
 * @property list<string>|null $cc
 * @property list<string>|null $bcc
 * @property string $subject
 * @property string $body_html
 * @property string $body_text
 * @property list<array{path: string, name: string, mime: string, size: int}>|null $attachments
 * @property array<string, string>|null $recipient_origins
 * @property OutboundEmailStatus $status
 * @property string $send_key
 * @property string|null $provider
 * @property string|null $provider_message_id
 * @property string|null $internet_message_id
 * @property string|null $conversation_id
 * @property string|null $in_reply_to
 * @property string|null $raw_mime
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $sent_by
 * @property Carbon|null $sent_at
 * @property string|null $error
 * @property string|null $model
 * @property string|null $prompt_version
 * @property-read Offer|null $offer
 * @property-read User|null $approvedBy
 */
class OutboundEmail extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['raw_mime'];

    protected function casts(): array
    {
        return [
            'type' => OutboundEmailType::class,
            'to' => 'array',
            'cc' => 'array',
            'bcc' => 'array',
            'attachments' => 'array',
            'recipient_origins' => 'array',
            'status' => OutboundEmailStatus::class,
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Offer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isEditable(): bool
    {
        return $this->status === OutboundEmailStatus::Concept;
    }

    public function attachmentBytes(): int
    {
        return (int) array_sum(array_column($this->attachments ?? [], 'size'));
    }
}

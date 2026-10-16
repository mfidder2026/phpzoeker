<?php

namespace App\Domain\Assignments\Models;

use App\Domain\Relations\Models\Contact;
use App\Domain\Relations\Models\Organization;
use App\Domain\Sources\Enums\SourceType;
use App\Domain\Sources\Models\RawPosting;
use App\Domain\Sources\Models\Source;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Waar een opdracht is gezien: per bron de URL, de intermediair en het contact (§9.4).
 *
 * @property int $id
 * @property int $assignment_id
 * @property int $source_id
 * @property int|null $raw_posting_id
 * @property string|null $url
 * @property string|null $intermediary_name
 * @property int|null $organization_id
 * @property int|null $contact_id
 * @property string|null $contact_name
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $last_seen_at
 * @property-read Source $source
 * @property-read Assignment $assignment
 */
class AssignmentSource extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'contact_phone' => 'encrypted',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Assignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * @return BelongsTo<RawPosting, $this>
     */
    public function rawPosting(): BelongsTo
    {
        return $this->belongsTo(RawPosting::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Waar de opdracht echt staat: bij een job alert uit de aanvragen-mailbox het platform dat de
     * alert stuurde (bijv. Freelance.nl), anders de bron zelf.
     */
    public function platformName(): string
    {
        $senderDomain = $this->rawPosting?->meta['sender_domain'] ?? null;

        if ($this->source->type !== SourceType::MailboxAlert || ! is_string($senderDomain) || $senderDomain === '') {
            return $this->intermediary_name ?? $this->source->name;
        }

        $alert = Source::query()->where('type', SourceType::Alert->value)->get()
            ->first(fn (Source $source): bool => collect((array) $source->configValue('sender_domains', []))
                ->contains(fn (mixed $domain): bool => is_string($domain) && ($senderDomain === $domain || str_ends_with($senderDomain, '.'.$domain))));

        return $alert->name ?? $senderDomain;
    }

    /**
     * Kwam de opdracht binnen als alert-mail (alleen een samenvatting, zonder opdrachtgever en contact)?
     */
    public function isAlertMail(): bool
    {
        return ($this->rawPosting?->meta['kind'] ?? null) === 'alert_item' || ($this->rawPosting?->meta['kind'] ?? null) === 'alert_mail';
    }
}

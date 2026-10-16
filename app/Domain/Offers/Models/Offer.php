<?php

namespace App\Domain\Offers\Models;

use App\Domain\Assignments\Models\Assignment;
use App\Domain\Assignments\Models\AssignmentSource;
use App\Domain\Candidates\Models\Candidate;
use App\Domain\Matching\Models\CandidateMatch;
use App\Domain\Offers\Enums\ConsentStatus;
use App\Domain\Offers\Enums\OfferChannel;
use App\Domain\Offers\Enums\OfferStatus;
use App\Domain\Offers\Enums\OutboundEmailType;
use App\Domain\Relations\Models\Contact;
use App\Domain\Relations\Models\Organization;
use App\Domain\Sources\Models\Source;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Eén aanbieding van een kandidaat op een opdracht (§11, §17). Ook aanbiedingen buiten de app
 * (handmatig geregistreerd) staan hier, zodat het register compleet is.
 *
 * @property int $id
 * @property int|null $match_id
 * @property int $assignment_id
 * @property int $candidate_id
 * @property string $dedupe_group_id
 * @property int|null $source_id
 * @property int|null $assignment_source_id
 * @property int|null $organization_id
 * @property int|null $contact_id
 * @property OfferChannel $channel
 * @property bool $is_manual
 * @property OfferStatus $status
 * @property string|null $status_reason
 * @property array<string, mixed>|null $candidate_snapshot
 * @property array<string, mixed>|null $cv_options
 * @property string|null $offered_rate
 * @property ConsentStatus $consent_status
 * @property Carbon|null $consent_at
 * @property string|null $consent_note
 * @property string|null $external_reference
 * @property int|null $created_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $sent_by
 * @property Carbon|null $sent_at
 * @property int|null $response_term_days
 * @property string|null $response_term_source
 * @property string|null $response_term_override_reason
 * @property Carbon|null $expected_response_at
 * @property Carbon|null $response_received_at
 * @property string|null $response_time_workdays
 * @property int $follow_up_count
 * @property Carbon|null $last_follow_up_at
 * @property Carbon|null $overdue_notified_at
 * @property Carbon|null $intake_at
 * @property array<string, mixed>|null $intake
 * @property Carbon|null $closed_at
 * @property int|null $match_score
 * @property Carbon $created_at
 * @property-read Assignment $assignment
 * @property-read Candidate $candidate
 * @property-read CandidateMatch|null $match
 * @property-read Source|null $source
 * @property-read AssignmentSource|null $assignmentSource
 * @property-read Organization|null $organization
 * @property-read Contact|null $contact
 * @property-read User|null $sentBy
 * @property-read User|null $approvedBy
 * @property-read OfferSnapshot|null $snapshot
 */
class Offer extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'channel' => OfferChannel::class,
            'is_manual' => 'boolean',
            'status' => OfferStatus::class,
            'candidate_snapshot' => 'array',
            'cv_options' => 'array',
            'offered_rate' => 'decimal:2',
            'consent_status' => ConsentStatus::class,
            'consent_at' => 'datetime',
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
            'response_term_days' => 'integer',
            'expected_response_at' => 'datetime',
            'response_received_at' => 'datetime',
            'response_time_workdays' => 'decimal:1',
            'follow_up_count' => 'integer',
            'last_follow_up_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
            'intake_at' => 'datetime',
            'intake' => 'array',
            'closed_at' => 'datetime',
            'match_score' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('aanbiedingen')
            ->logOnly(['status', 'status_reason', 'offered_rate', 'channel', 'consent_status', 'external_reference', 'expected_response_at', 'response_term_days', 'response_term_override_reason', 'intake_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return BelongsTo<Assignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class)->withTrashed();
    }

    /**
     * @return BelongsTo<CandidateMatch, $this>
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(CandidateMatch::class, 'match_id');
    }

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * @return BelongsTo<AssignmentSource, $this>
     */
    public function assignmentSource(): BelongsTo
    {
        return $this->belongsTo(AssignmentSource::class);
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
     * @return BelongsTo<User, $this>
     */
    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<TailoredCv, $this>
     */
    public function tailoredCvs(): HasMany
    {
        return $this->hasMany(TailoredCv::class)->orderByDesc('version');
    }

    /**
     * @return HasOne<TailoredCv, $this>
     */
    public function latestCv(): HasOne
    {
        return $this->hasOne(TailoredCv::class)->latestOfMany('version');
    }

    /**
     * @return HasMany<OutboundEmail, $this>
     */
    public function emails(): HasMany
    {
        return $this->hasMany(OutboundEmail::class)->orderByDesc('id');
    }

    /**
     * De aanbiedingsmail (of bij platformindiening de interne mail) die nog verstuurd moet worden.
     */
    public function offerEmail(): ?OutboundEmail
    {
        return $this->emails()->whereIn('type', [OutboundEmailType::Offer->value, OutboundEmailType::InternalPackage->value])->first();
    }

    /**
     * @return HasMany<OfferActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(OfferActivity::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }

    /**
     * @return HasOne<OfferSnapshot, $this>
     */
    public function snapshot(): HasOne
    {
        return $this->hasOne(OfferSnapshot::class);
    }

    /**
     * Actief voor de blokkade op dubbele aanbiedingen (§11.4): alles behalve ingetrokken.
     *
     * @param  Builder<Offer>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', '!=', OfferStatus::Ingetrokken->value);
    }

    /**
     * @param  Builder<Offer>  $query
     */
    public function scopeSent(Builder $query): void
    {
        $query->whereIn('status', OfferStatus::sentValues());
    }

    /**
     * Verstuurd, nog open en de verwachte reactie is verstreken zonder reactie (§11.6).
     *
     * @param  Builder<Offer>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        self::whereOverdue($query);
    }

    /**
     * Zelfde voorwaarde als `overdue()`, voor queries waarvan het model niet bekend is (Filament).
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function whereOverdue(Builder $query): Builder
    {
        return $query->whereIn('status', [OfferStatus::Verzonden->value, OfferStatus::GeenReactie->value])
            ->whereNull('response_received_at')
            ->whereNotNull('expected_response_at')
            ->where('expected_response_at', '<', now());
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, [OfferStatus::Verzonden, OfferStatus::GeenReactie], true)
            && $this->response_received_at === null
            && $this->expected_response_at !== null
            && $this->expected_response_at->isPast();
    }

    /**
     * Naam van de partij die de aanbieding ontvangt ("Via" in het register).
     */
    public function viaName(): string
    {
        return $this->organization->name
            ?? $this->assignmentSource->intermediary_name
            ?? $this->assignment->intermediary_name
            ?? $this->assignment->client_name
            ?? '—';
    }
}

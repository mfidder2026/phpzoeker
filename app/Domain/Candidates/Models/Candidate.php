<?php

namespace App\Domain\Candidates\Models;

use App\Domain\Candidates\Enums\CandidateStatus;
use App\Domain\Candidates\Enums\Seniority;
use App\Domain\Candidates\Events\CandidateValuesChanged;
use App\Models\User;
use Database\Factories\CandidateFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Een Fremen-developer die wordt aangeboden. Alle vaste waardes staan hier (R6).
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $job_title
 * @property list<string>|null $cv_titles
 * @property Seniority $seniority
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $city
 * @property string|null $postcode
 * @property string|null $birth_date
 * @property bool $driving_license
 * @property bool|null $own_transport
 * @property string|null $nationality
 * @property Carbon|null $available_from
 * @property int $hours_per_week
 * @property string $hourly_rate
 * @property string $min_hourly_rate
 * @property string $rate_note
 * @property array<string, string>|null $languages
 * @property CandidateStatus $status
 * @property bool $cc_self_on_offers
 * @property string|null $photo_path
 * @property int|null $active_profile_id
 * @property-read CandidateProfile|null $activeProfile
 * @property-read CandidateSearchProfile|null $searchProfile
 */
#[UseFactory(CandidateFactory::class)]
class Candidate extends Model
{
    /** @use HasFactory<CandidateFactory> */
    use HasFactory;

    use LogsActivity;
    use SoftDeletes;

    /**
     * Velden die een herberekening van open matches veroorzaken (FR-K5).
     *
     * @var list<string>
     */
    public const MATCH_RELEVANT_FIELDS = [
        'seniority', 'city', 'postcode', 'driving_license', 'own_transport', 'available_from',
        'hours_per_week', 'hourly_rate', 'min_hourly_rate', 'status', 'languages', 'active_profile_id',
    ];

    protected $guarded = ['id'];

    /**
     * Geboortedatum en telefoon komen nooit mee in array- of JSON-output (R4).
     *
     * @var list<string>
     */
    protected $hidden = ['birth_date', 'phone'];

    protected function casts(): array
    {
        return [
            'cv_titles' => 'array',
            'seniority' => Seniority::class,
            'phone' => 'encrypted',
            'birth_date' => 'encrypted',
            'driving_license' => 'boolean',
            'own_transport' => 'boolean',
            'available_from' => 'date',
            'hours_per_week' => 'integer',
            'hourly_rate' => 'decimal:2',
            'min_hourly_rate' => 'decimal:2',
            'languages' => 'array',
            'status' => CandidateStatus::class,
            'cc_self_on_offers' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updated(function (Candidate $candidate): void {
            if ($candidate->wasChanged(self::MATCH_RELEVANT_FIELDS)) {
                CandidateValuesChanged::dispatch($candidate, array_keys($candidate->getChanges()));
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('kandidaten')
            ->logAll()
            ->logExcept(['birth_date', 'phone', 'updated_at', 'created_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return HasMany<CandidateAbsence, $this>
     */
    public function absences(): HasMany
    {
        return $this->hasMany(CandidateAbsence::class)->orderBy('starts_on');
    }

    /**
     * @return HasOne<CandidateSearchProfile, $this>
     */
    public function searchProfile(): HasOne
    {
        return $this->hasOne(CandidateSearchProfile::class);
    }

    /**
     * @return HasMany<CandidateProfile, $this>
     */
    public function profiles(): HasMany
    {
        return $this->hasMany(CandidateProfile::class)->orderByDesc('version');
    }

    /**
     * @return BelongsTo<CandidateProfile, $this>
     */
    public function activeProfile(): BelongsTo
    {
        return $this->belongsTo(CandidateProfile::class, 'active_profile_id');
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isAvailableImmediately(): bool
    {
        return $this->available_from === null || $this->available_from->isPast();
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $first = $parts[0] ?? '';
        $last = count($parts) > 1 ? $parts[count($parts) - 1] : '';

        return mb_strtoupper(mb_substr($first, 0, 1).mb_substr($last, 0, 1));
    }

    public function nextProfileVersion(): int
    {
        return ((int) $this->profiles()->max('version')) + 1;
    }

    /**
     * Kandidaatwaardes zoals ze bij een aanbieding worden vastgelegd (FR-K7).
     *
     * @return array{hourly_rate: string, min_hourly_rate: string, rate_note: string, hours_per_week: int, available_from: string|null, city: string|null, absences: list<array{type: string, starts_on: string, ends_on: string}>}
     */
    public function offerSnapshot(): array
    {
        return [
            'hourly_rate' => $this->hourly_rate,
            'min_hourly_rate' => $this->min_hourly_rate,
            'rate_note' => $this->rate_note,
            'hours_per_week' => $this->hours_per_week,
            'available_from' => $this->available_from?->toDateString(),
            'city' => $this->city,
            'absences' => array_values($this->absences->map(fn (CandidateAbsence $absence): array => [
                'type' => $absence->type->value,
                'starts_on' => $absence->starts_on->toDateString(),
                'ends_on' => $absence->ends_on->toDateString(),
            ])->all()),
        ];
    }
}

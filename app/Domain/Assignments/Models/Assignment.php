<?php

namespace App\Domain\Assignments\Models;

use App\Domain\Assignments\Enums\AssignmentContractType;
use App\Domain\Assignments\Enums\AssignmentStatus;
use App\Domain\Assignments\Enums\Sector;
use App\Domain\Assignments\Enums\WorkMode;
use App\Domain\Relations\Models\Organization;
use App\Domain\Sources\Models\RawPosting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Eén opdracht, ontdubbeld over bronnen heen (§9). Velden die een mens corrigeert, staan in
 * `locked_fields` en worden niet overschreven bij een nieuwe extractie (§9.1).
 *
 * @property int $id
 * @property string $dedupe_group_id
 * @property string $title
 * @property string|null $client_name
 * @property string|null $intermediary_name
 * @property int|null $client_organization_id
 * @property int|null $intermediary_organization_id
 * @property string|null $reference
 * @property string|null $description_text
 * @property string|null $summary
 * @property string $language
 * @property string|null $location_text
 * @property string|null $city
 * @property string|null $province
 * @property string $country
 * @property string|null $lat
 * @property string|null $lng
 * @property WorkMode $work_mode
 * @property string|null $onsite_days_per_week
 * @property int|null $hours_min
 * @property int|null $hours_max
 * @property Carbon|null $start_date
 * @property bool $start_asap
 * @property Carbon|null $end_date
 * @property int|null $duration_months
 * @property string|null $extension_option
 * @property string|null $max_rate
 * @property bool|null $rate_includes_margin
 * @property string|null $rate_text
 * @property AssignmentContractType $contract_type
 * @property bool|null $zzp_allowed
 * @property Sector $sector
 * @property string $seniority
 * @property Carbon|null $deadline_at
 * @property list<array<string, mixed>>|null $requirements
 * @property list<array<string, mixed>>|null $wishes
 * @property list<string>|null $technologies
 * @property array<string, mixed>|null $screening
 * @property array<string, mixed>|null $submission
 * @property bool $is_relevant
 * @property string|null $relevance_reason
 * @property bool $is_incomplete
 * @property list<string>|null $locked_fields
 * @property AssignmentStatus $status
 * @property string|null $ignore_reason
 * @property int|null $possible_duplicate_of_id
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $closed_at
 * @property string|null $extraction_model
 * @property string|null $extraction_prompt_version
 * @property Carbon $created_at
 * @property-read Organization|null $clientOrganization
 * @property-read Organization|null $intermediaryOrganization
 * @property-read Assignment|null $possibleDuplicateOf
 */
class Assignment extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'work_mode' => WorkMode::class,
            'contract_type' => AssignmentContractType::class,
            'sector' => Sector::class,
            'status' => AssignmentStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'deadline_at' => 'datetime',
            'start_asap' => 'boolean',
            'rate_includes_margin' => 'boolean',
            'zzp_allowed' => 'boolean',
            'is_relevant' => 'boolean',
            'is_incomplete' => 'boolean',
            'max_rate' => 'decimal:2',
            'onsite_days_per_week' => 'decimal:1',
            'hours_min' => 'integer',
            'hours_max' => 'integer',
            'duration_months' => 'integer',
            'requirements' => 'array',
            'wishes' => 'array',
            'technologies' => 'array',
            'screening' => 'array',
            'submission' => 'array',
            'locked_fields' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('opdrachten')
            ->logOnly(['title', 'status', 'ignore_reason', 'is_relevant', 'max_rate', 'hours_min', 'hours_max', 'deadline_at', 'locked_fields'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return HasMany<AssignmentSource, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(AssignmentSource::class);
    }

    /**
     * @return HasMany<RawPosting, $this>
     */
    public function rawPostings(): HasMany
    {
        return $this->hasMany(RawPosting::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function clientOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'client_organization_id');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function intermediaryOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'intermediary_organization_id');
    }

    /**
     * @return BelongsTo<Assignment, $this>
     */
    public function possibleDuplicateOf(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'possible_duplicate_of_id');
    }

    /**
     * Open = niet gesloten en niet genegeerd.
     *
     * @param  Builder<Assignment>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNotIn('status', [AssignmentStatus::Gesloten->value, AssignmentStatus::Genegeerd->value]);
    }

    public function isFieldLocked(string $field): bool
    {
        return in_array($field, $this->locked_fields ?? [], true);
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, [AssignmentStatus::Gesloten, AssignmentStatus::Genegeerd], true);
    }
}

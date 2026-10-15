<?php

namespace App\Domain\Sources\Models;

use App\Domain\Relations\Models\Organization;
use App\Domain\Sources\Enums\SourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Een plek waar opdrachten vandaan komen (§8). Een bron kan pas aan als de ToS-check is
 * ingevuld (FR-B2, R3). De configuratie (bijv. mailboxwachtwoord) staat versleuteld.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property SourceType $type
 * @property string|null $base_url
 * @property array<string, mixed>|null $config
 * @property bool $enabled
 * @property int $poll_interval_minutes
 * @property string|null $access_method
 * @property Carbon|null $tos_reviewed_at
 * @property string|null $tos_notes
 * @property int|null $organization_id
 * @property int|null $default_response_term_days
 * @property Carbon|null $last_run_at
 * @property Carbon|null $last_success_at
 * @property Carbon|null $last_new_posting_at
 * @property int $consecutive_failures
 * @property string|null $health_warning
 * @property-read Organization|null $organization
 */
class Source extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    /**
     * @var list<string>
     */
    protected $hidden = ['config'];

    protected function casts(): array
    {
        return [
            'type' => SourceType::class,
            'config' => 'encrypted:array',
            'enabled' => 'boolean',
            'poll_interval_minutes' => 'integer',
            'tos_reviewed_at' => 'datetime',
            'last_run_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_new_posting_at' => 'datetime',
            'consecutive_failures' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Source $source): void {
            if ($source->enabled && ! $source->hasToSCheck()) {
                throw new \DomainException('Een bron kan pas aan als de ToS-check is ingevuld (datum en notitie).');
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('bronnen')
            ->logOnly(['name', 'type', 'base_url', 'enabled', 'poll_interval_minutes', 'access_method', 'tos_reviewed_at', 'tos_notes'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function hasToSCheck(): bool
    {
        return $this->tos_reviewed_at !== null && filled($this->tos_notes);
    }

    public function isDue(): bool
    {
        return $this->enabled && ($this->last_run_at === null || $this->last_run_at->lte(now()->subMinutes($this->poll_interval_minutes)));
    }

    public function configValue(string $key, mixed $default = null): mixed
    {
        return ($this->config ?? [])[$key] ?? $default;
    }

    /**
     * @return HasMany<SourceRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(SourceRun::class)->latest('started_at');
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
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function hostname(): ?string
    {
        $host = parse_url((string) $this->base_url, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : null;
    }
}

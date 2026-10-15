<?php

namespace App\Domain\Candidates\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Master-profiel (Bijlage D), geversioneerd. Enige bron van waarheid voor CV's (R2, ADR-04).
 *
 * @property int $id
 * @property int $candidate_id
 * @property int $version
 * @property array<string, mixed> $data
 * @property string|null $source_file_path
 * @property list<string>|null $skills_vocabulary
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $model
 * @property string|null $prompt_version
 * @property Carbon $created_at
 * @property-read Candidate $candidate
 * @property-read User|null $approver
 */
class CandidateProfile extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'skills_vocabulary' => 'array',
            'approved_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Een goedgekeurde versie is bevroren: getailorde CV's verwijzen ernaar (§12.1).
        static::updating(function (CandidateProfile $profile): void {
            if ($profile->getOriginal('approved_at') !== null && $profile->isDirty('data')) {
                throw new \DomainException('Een goedgekeurde versie van het master-profiel kan niet worden gewijzigd. Maak een nieuwe versie.');
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('master-profiel')
            ->logOnly(['version', 'approved_by', 'approved_at'])
            ->logOnlyDirty();
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }
}

<?php

namespace App\Domain\Candidates\Models;

use App\Domain\Candidates\Events\CandidateValuesChanged;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $candidate_id
 * @property list<string>|null $roles
 * @property list<string>|null $include_keywords
 * @property list<string>|null $exclude_keywords
 * @property list<string>|null $preferred_tech
 * @property list<string>|null $excluded_tech
 * @property int $min_hours
 * @property int|null $max_travel_minutes
 * @property list<string>|null $accepted_contract_types
 * @property list<string>|null $regions
 * @property-read Candidate $candidate
 */
class CandidateSearchProfile extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'include_keywords' => 'array',
            'exclude_keywords' => 'array',
            'preferred_tech' => 'array',
            'excluded_tech' => 'array',
            'min_hours' => 'integer',
            'max_travel_minutes' => 'integer',
            'accepted_contract_types' => 'array',
            'regions' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updated(function (CandidateSearchProfile $profile): void {
            CandidateValuesChanged::dispatch($profile->candidate, ['search_profile']);
        });
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }
}

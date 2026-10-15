<?php

namespace App\Domain\Candidates\Models;

use App\Domain\Candidates\Enums\AbsenceType;
use App\Domain\Candidates\Events\CandidateValuesChanged;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $candidate_id
 * @property AbsenceType $type
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string|null $note
 */
class CandidateAbsence extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => AbsenceType::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        $changed = function (CandidateAbsence $absence): void {
            if ($absence->candidate !== null) {
                CandidateValuesChanged::dispatch($absence->candidate, ['absences']);
            }
        };

        static::saved($changed);
        static::deleted($changed);
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }
}

<?php

namespace App\Domain\Candidates\Models;

use App\Domain\Candidates\Enums\AbsenceType;
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

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }
}

<?php

namespace App\Domain\Matching\Models;

use App\Domain\Assignments\Models\Assignment;
use App\Domain\Candidates\Models\Candidate;
use App\Domain\Matching\Enums\AssessmentStatus;
use App\Domain\Matching\Enums\MatchLabel;
use App\Domain\Matching\Enums\MatchStatus;
use App\Domain\Matching\Enums\Recommendation;
use App\Domain\Offers\Models\Offer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Eén combinatie opdracht × kandidaat (§10, §17). `Match` is een gereserveerd woord in PHP,
 * vandaar de naam `CandidateMatch`; de tabel heet `matches`.
 *
 * @property int $id
 * @property int $assignment_id
 * @property int $candidate_id
 * @property bool $prefilter_passed
 * @property int $prefilter_score
 * @property list<string>|null $prefilter_reasons
 * @property bool $hard_filter_passed
 * @property list<array{key: string, label: string, outcome: string, detail: string}>|null $filter_results
 * @property int $score
 * @property array<string, array{label: string, weight: int, value: float, points: float, detail: string}>|null $score_breakdown
 * @property array<string, mixed>|null $assessment
 * @property AssessmentStatus $assessment_status
 * @property string|null $input_hash
 * @property MatchLabel $label
 * @property Recommendation|null $recommendation
 * @property list<string>|null $warnings
 * @property bool $knockout_failed
 * @property int|null $knockout_overridden_by
 * @property string|null $knockout_override_reason
 * @property MatchStatus $status
 * @property Carbon|null $status_changed_at
 * @property string|null $rejection_reason
 * @property string|null $feedback
 * @property string|null $feedback_reason
 * @property int|null $feedback_by
 * @property Carbon|null $feedback_at
 * @property string|null $candidate_interest
 * @property string|null $candidate_interest_reason
 * @property string|null $model
 * @property string|null $prompt_version
 * @property int|null $profile_version
 * @property string|null $ai_run_id
 * @property Carbon|null $computed_at
 * @property Carbon|null $notified_at
 * @property Carbon|null $digested_at
 * @property Carbon|null $deadline_reminded_at
 * @property Carbon $created_at
 * @property-read Assignment $assignment
 * @property-read Candidate $candidate
 */
class CandidateMatch extends Model
{
    use LogsActivity;

    public const FEEDBACK_UP = 'up';

    public const FEEDBACK_DOWN = 'down';

    protected $table = 'matches';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'prefilter_passed' => 'boolean',
            'prefilter_score' => 'integer',
            'prefilter_reasons' => 'array',
            'hard_filter_passed' => 'boolean',
            'filter_results' => 'array',
            'score' => 'integer',
            'score_breakdown' => 'array',
            'assessment' => 'array',
            'assessment_status' => AssessmentStatus::class,
            'label' => MatchLabel::class,
            'recommendation' => Recommendation::class,
            'warnings' => 'array',
            'knockout_failed' => 'boolean',
            'status' => MatchStatus::class,
            'status_changed_at' => 'datetime',
            'feedback_at' => 'datetime',
            'profile_version' => 'integer',
            'computed_at' => 'datetime',
            'notified_at' => 'datetime',
            'digested_at' => 'datetime',
            'deadline_reminded_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('matches')
            ->logOnly(['status', 'rejection_reason', 'feedback', 'feedback_reason', 'candidate_interest', 'candidate_interest_reason', 'knockout_override_reason'])
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
        return $this->belongsTo(Candidate::class);
    }

    /**
     * @return HasMany<Offer, $this>
     */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class, 'match_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function knockoutOverriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'knockout_overridden_by');
    }

    /**
     * Sterke en mogelijke matches die de harde filters halen.
     *
     * @param  Builder<CandidateMatch>  $query
     */
    public function scopePromising(Builder $query): void
    {
        $query->where('hard_filter_passed', true)->whereIn('label', [MatchLabel::Sterk->value, MatchLabel::Mogelijk->value]);
    }

    /**
     * @param  Builder<CandidateMatch>  $query
     */
    public function scopeForOpenAssignments(Builder $query): void
    {
        $query->whereHas('assignment', fn (Builder $assignment) => $assignment->open());
    }

    public function isKnockoutBlocking(): bool
    {
        return $this->knockout_failed && $this->knockout_override_reason === null;
    }

    public function setStatus(MatchStatus $status): void
    {
        $this->forceFill(['status' => $status, 'status_changed_at' => now()])->save();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function requirementRows(): array
    {
        return $this->assessmentRows('eisen', $this->assignment->requirements ?? []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function wishRows(): array
    {
        return $this->assessmentRows('wensen', $this->assignment->wishes ?? []);
    }

    /**
     * Eisen of wensen uit de opdracht naast het oordeel uit de beoordeling.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function assessmentRows(string $key, array $items): array
    {
        $verdicts = [];

        foreach ((array) ($this->assessment[$key] ?? []) as $verdict) {
            if (is_array($verdict) && is_string($verdict['id'] ?? null)) {
                $verdicts[$verdict['id']] = $verdict;
            }
        }

        return array_map(fn (array $item): array => [
            ...$item,
            'oordeel' => $verdicts[$item['id'] ?? '']['oordeel'] ?? 'onbekend',
            'bewijs' => $verdicts[$item['id'] ?? '']['bewijs'] ?? null,
            'bron_in_profiel' => $verdicts[$item['id'] ?? '']['bron_in_profiel'] ?? null,
            'gecorrigeerd' => $verdicts[$item['id'] ?? '']['gecorrigeerd'] ?? false,
        ], $items);
    }
}

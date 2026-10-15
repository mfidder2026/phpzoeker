<?php

namespace App\Domain\Matching\Jobs;

use App\Domain\Assignments\Models\Assignment;
use App\Domain\Candidates\Models\Candidate;
use App\Domain\Matching\Models\CandidateMatch;
use App\Settings\MatchingSettings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Herberekent de matches van één kandidaat met alle open opdrachten (§10.6): na een wijziging
 * van kandidaatwaardes, zoekprofiel of master-profiel, en elke nacht. Een AI-aanroep gebeurt
 * alleen als de invoer veranderd is of de beoordeling nog ontbreekt.
 */
class RematchCandidateJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public function __construct(public int $candidateId) {}

    public function uniqueId(): string
    {
        return (string) $this->candidateId;
    }

    public function handle(MatchingSettings $settings): void
    {
        $candidate = Candidate::query()->find($this->candidateId);

        if ($candidate === null) {
            return;
        }

        $matchable = $candidate->status->isMatchable() && $candidate->active_profile_id !== null;
        $existing = CandidateMatch::query()->where('candidate_id', $candidate->id)->pluck('assignment_id')->all();

        $assignmentIds = Assignment::query()
            ->open()
            ->where(fn ($query) => $matchable ? $query->where('is_relevant', true)->orWhereIn('id', $existing) : $query->whereIn('id', $existing))
            ->orderByRaw('deadline_at is null')
            ->orderBy('deadline_at')
            ->pluck('id');

        $budget = max(0, $settings->max_assessments_per_run);

        foreach ($assignmentIds as $index => $assignmentId) {
            MatchAssignmentJob::dispatch($assignmentId, $candidate->id, assess: $matchable && $index < $budget);
        }
    }
}

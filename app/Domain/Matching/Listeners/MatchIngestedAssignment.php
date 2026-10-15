<?php

namespace App\Domain\Matching\Listeners;

use App\Domain\Assignments\Events\AssignmentIngested;
use App\Domain\Candidates\Enums\CandidateStatus;
use App\Domain\Candidates\Models\Candidate;
use App\Domain\Matching\Jobs\MatchAssignmentJob;

/**
 * Nieuwe of bijgewerkte opdracht → matchen met alle actieve kandidaten (§6 stap 5).
 */
class MatchIngestedAssignment
{
    public function handle(AssignmentIngested $event): void
    {
        $assignment = $event->assignment;

        if (! $assignment->is_relevant || ! $assignment->isOpen()) {
            return;
        }

        Candidate::query()
            ->whereIn('status', [CandidateStatus::Beschikbaar->value, CandidateStatus::InProcedure->value])
            ->whereNotNull('active_profile_id')
            ->pluck('id')
            ->each(fn (int $candidateId) => MatchAssignmentJob::dispatch($assignment->id, $candidateId));
    }
}

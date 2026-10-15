<?php

namespace App\Domain\Matching\Jobs;

use App\Domain\Assignments\Models\Assignment;
use App\Domain\Candidates\Models\Candidate;
use App\Domain\Matching\Actions\MatchCandidate;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Matcht één opdracht met één kandidaat (§16.4). Idempotent: dezelfde invoer leidt niet tot een
 * nieuwe AI-aanroep.
 */
class MatchAssignmentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(
        public int $assignmentId,
        public int $candidateId,
        public bool $assess = true,
        public bool $force = false,
        public ?int $startedBy = null,
    ) {
        $this->onQueue('ai');
    }

    public function uniqueId(): string
    {
        return "{$this->assignmentId}:{$this->candidateId}";
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(MatchCandidate $match): void
    {
        $assignment = Assignment::query()->find($this->assignmentId);
        $candidate = Candidate::query()->find($this->candidateId);

        if ($assignment === null || $candidate === null || (! $assignment->isOpen() && ! $this->force)) {
            return;
        }

        $match->handle(
            $assignment,
            $candidate,
            assess: $this->assess,
            force: $this->force,
            startedBy: $this->startedBy !== null ? User::query()->find($this->startedBy) : null,
        );
    }
}

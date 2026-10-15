<?php

namespace App\Domain\Assignments\Jobs;

use App\Domain\Assignments\Actions\ReextractAssignment;
use App\Domain\Assignments\Models\Assignment;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReextractAssignmentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $assignmentId, public string $runId, public ?int $userId = null)
    {
        $this->onQueue('ai');
    }

    public function handle(ReextractAssignment $reextract): void
    {
        $assignment = Assignment::query()->find($this->assignmentId);

        if ($assignment !== null) {
            $reextract->handle($assignment, $this->userId !== null ? User::query()->find($this->userId) : null, $this->runId);
        }
    }
}

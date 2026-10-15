<?php

namespace App\Domain\Sources\Jobs;

use App\Ai\Runs\RunTracker;
use App\Domain\Assignments\Actions\ProcessRawPosting;
use App\Domain\Sources\Models\RawPosting;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Verwerkt één ruwe publicatie tot opdracht (§16.4). Idempotent: een al verwerkte publicatie
 * wordt overgeslagen.
 */
class ProcessRawPostingJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public int $rawPostingId, public ?string $runId = null)
    {
        $this->onQueue('ai');
    }

    public function uniqueId(): string
    {
        return (string) $this->rawPostingId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(ProcessRawPosting $process): void
    {
        $posting = RawPosting::query()->with('source')->find($this->rawPostingId);

        if ($posting === null) {
            return;
        }

        $process->handle($posting, $this->runId !== null ? RunTracker::resume($this->runId) : null);
    }
}

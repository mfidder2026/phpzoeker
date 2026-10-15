<?php

namespace App\Domain\Sources\Jobs;

use App\Domain\Sources\Actions\RunSource;
use App\Domain\Sources\Models\Source;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Haalt één bron op (§16.4, queue `ingest`). Eén run per bron tegelijk.
 */
class FetchSourceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $sourceId)
    {
        $this->onQueue('ingest');
    }

    public function uniqueId(): string
    {
        return (string) $this->sourceId;
    }

    public function handle(RunSource $runSource): void
    {
        $source = Source::query()->find($this->sourceId);

        if ($source !== null) {
            $runSource->handle($source);
        }
    }
}

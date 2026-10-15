<?php

namespace App\Domain\Sources\Jobs;

use App\Domain\Sources\Models\Source;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Elke minuut: zet de actieve bronnen waarvan het interval verstreken is in de wachtrij.
 */
class DispatchDueSourcesJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        foreach (Source::query()->where('enabled', true)->get() as $source) {
            if ($source->isDue()) {
                FetchSourceJob::dispatch($source->id);
            }
        }
    }
}

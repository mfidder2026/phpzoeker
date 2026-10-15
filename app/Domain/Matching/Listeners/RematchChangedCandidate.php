<?php

namespace App\Domain\Matching\Listeners;

use App\Domain\Candidates\Events\CandidateValuesChanged;
use App\Domain\Matching\Jobs\RematchCandidateJob;

/**
 * Kandidaatwaardes, zoekprofiel of master-profiel gewijzigd → open matches herberekenen (FR-K5).
 */
class RematchChangedCandidate
{
    public function handle(CandidateValuesChanged $event): void
    {
        RematchCandidateJob::dispatch($event->candidate->id);
    }
}

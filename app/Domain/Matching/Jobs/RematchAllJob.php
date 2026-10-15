<?php

namespace App\Domain\Matching\Jobs;

use App\Domain\Candidates\Models\Candidate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Nachtelijke herberekening (02:00, §16.4): pakt ook beoordelingen op die wachtten op het
 * AI-budget of mislukten.
 */
class RematchAllJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Candidate::query()->pluck('id')->each(fn (int $id) => RematchCandidateJob::dispatch($id));
    }
}

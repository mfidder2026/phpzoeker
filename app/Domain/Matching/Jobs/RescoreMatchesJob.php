<?php

namespace App\Domain\Matching\Jobs;

use App\Domain\Matching\Actions\MatchCandidate;
use App\Domain\Matching\Models\CandidateMatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Na een wijziging van gewichten of drempels: alleen de score en het label opnieuw berekenen,
 * zonder AI-aanroep (§10.6).
 */
class RescoreMatchesJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function handle(MatchCandidate $action): void
    {
        CandidateMatch::query()
            ->forOpenAssignments()
            ->with(['assignment', 'candidate'])
            ->chunkById(100, function ($matches) use ($action): void {
                foreach ($matches as $match) {
                    $action->handle($match->assignment, $match->candidate, assess: false);
                }
            });
    }
}

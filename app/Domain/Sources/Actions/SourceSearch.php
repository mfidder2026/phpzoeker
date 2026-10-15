<?php

namespace App\Domain\Sources\Actions;

use App\Domain\Matching\Enums\MatchLabel;
use App\Domain\Matching\Models\CandidateMatch;
use App\Domain\Sources\Enums\RawPostingStatus;
use App\Domain\Sources\Enums\SourceRunStatus;
use App\Domain\Sources\Enums\SourceType;
use App\Domain\Sources\Jobs\FetchSourceJob;
use App\Domain\Sources\Models\RawPosting;
use App\Domain\Sources\Models\Source;
use App\Domain\Sources\Models\SourceRun;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Nu zoeken": haalt alle actieve bronnen op en houdt bij hoe ver die zoekronde is
 * (ophalen → opdrachten lezen met AI → matchen), voor het voortgangspaneel.
 *
 * @phpstan-type Search array{id: string, started_at: string, source_ids: list<int>}
 * @phpstan-type SourceProgress array{name: string, status: string, fetched: int, new: int, errors: int, message: ?string}
 * @phpstan-type Progress array{finished: bool, percent: int, elapsed_seconds: int, remaining_seconds: ?int, phase: string, sources: list<SourceProgress>, postings_total: int, postings_done: int, postings_failed: int, assignments: int, matches: int, strong_matches: int, matching_queued: int, worker_warning: ?string}
 */
class SourceSearch
{
    private const int TTL_HOURS = 6;

    /**
     * @return Search|null null als er geen actieve bronnen zijn
     */
    public function start(User $user): ?array
    {
        $sources = Source::query()->where('enabled', true)->whereIn('type', SourceType::searchable())->orderBy('name')->get();

        if ($sources->isEmpty()) {
            return null;
        }

        foreach ($sources as $source) {
            FetchSourceJob::dispatch($source->id);
        }

        $search = [
            'id' => (string) Str::uuid(),
            'started_at' => now()->toIso8601String(),
            'source_ids' => array_values($sources->pluck('id')->map(fn ($id): int => (int) $id)->all()),
        ];

        Cache::put($this->key($user), $search, now()->addHours(self::TTL_HOURS));

        return $search;
    }

    /**
     * @return Search|null
     */
    public function current(User $user): ?array
    {
        $search = Cache::get($this->key($user));

        if (! is_array($search) || ! is_string($search['id'] ?? null) || ! is_string($search['started_at'] ?? null) || ! is_array($search['source_ids'] ?? null)) {
            return null;
        }

        return [
            'id' => $search['id'],
            'started_at' => $search['started_at'],
            'source_ids' => array_values(array_map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $search['source_ids'])),
        ];
    }

    public function forget(User $user): void
    {
        Cache::forget($this->key($user));
    }

    /**
     * @param  Search  $search
     * @return Progress
     */
    public function progress(array $search): array
    {
        $startedAt = Carbon::parse($search['started_at']);
        $elapsed = (int) max(0, $startedAt->diffInSeconds(now()));

        $sources = Source::query()->whereKey($search['source_ids'])->orderBy('name')->get();
        $runs = SourceRun::query()
            ->whereIn('source_id', $search['source_ids'])
            ->where('started_at', '>=', $startedAt->copy()->subSecond())
            ->where('dry_run', false)
            ->orderBy('id')
            ->get()
            ->keyBy('source_id');

        $sourceRows = [];
        $sourcesDone = 0;

        foreach ($sources as $source) {
            $run = $runs->get($source->id);
            $status = match (true) {
                $run === null => 'pending',
                $run->status === SourceRunStatus::Running => 'running',
                $run->status === SourceRunStatus::Failed => 'failed',
                default => 'done',
            };

            if (in_array($status, ['done', 'failed'], true)) {
                $sourcesDone++;
            }

            $sourceRows[] = [
                'name' => $source->name,
                'status' => $status,
                'fetched' => (int) ($run->fetched_count ?? 0),
                'new' => (int) ($run->new_count ?? 0),
                'errors' => (int) ($run->error_count ?? 0),
                'message' => $status === 'failed' ? Str::limit(trim((string) ($run->log ?? '')), 180) : null,
            ];
        }

        $postings = RawPosting::query()
            ->whereIn('source_id', $search['source_ids'])
            ->where('received_at', '>=', $startedAt->copy()->subSecond());

        $postingsTotal = (clone $postings)->count();
        $postingsPending = (clone $postings)->where('status', RawPostingStatus::Pending->value)->count();
        $postingsFailed = (clone $postings)->where('status', RawPostingStatus::Failed->value)->count();
        $assignmentIds = (clone $postings)->whereNotNull('assignment_id')->distinct()->pluck('assignment_id');

        $matches = CandidateMatch::query()->whereIn('assignment_id', $assignmentIds);
        $matchCount = (clone $matches)->count();
        $strongCount = (clone $matches)->where('label', MatchLabel::Sterk->value)->count();

        $matchingQueued = $this->queuedJobs('MatchAssignmentJob');
        $extractionQueued = $this->queuedJobs('ProcessRawPostingJob');
        $fetchQueued = $this->queuedJobs('FetchSourceJob');

        $sourceCount = max(1, count($sourceRows));
        $fetchShare = $sourcesDone / $sourceCount;
        $extractShare = $postingsTotal === 0 ? ($sourcesDone === count($sourceRows) ? 1.0 : 0.0) : ($postingsTotal - $postingsPending) / $postingsTotal;
        $matchShare = $sourcesDone === count($sourceRows) && $postingsPending === 0 && $matchingQueued === 0 ? 1.0 : 0.0;

        $finished = $sourcesDone === count($sourceRows) && $postingsPending === 0 && $extractionQueued === 0 && $matchingQueued === 0;
        $percent = $finished ? 100 : (int) min(99, round(100 * (0.2 * $fetchShare + 0.65 * $extractShare + 0.15 * $matchShare)));

        $remaining = null;

        if (! $finished && $percent >= 5 && $elapsed > 0) {
            $remaining = (int) round($elapsed * (100 - $percent) / $percent);
        }

        $phase = match (true) {
            $finished => 'Klaar',
            $sourcesDone < count($sourceRows) => 'Bronnen ophalen',
            $postingsPending > 0 => 'Opdrachten lezen met AI',
            default => 'Kandidaten matchen',
        };

        $workerWarning = null;

        if (! $finished && $elapsed >= 30 && $runs->isEmpty() && $fetchQueued > 0) {
            $workerWarning = 'Er is na '.$elapsed.' seconden nog niets gestart. Draait de wachtrij-worker (php artisan queue:work)?';
        }

        return [
            'finished' => $finished,
            'percent' => $percent,
            'elapsed_seconds' => $elapsed,
            'remaining_seconds' => $remaining,
            'phase' => $phase,
            'sources' => $sourceRows,
            'postings_total' => $postingsTotal,
            'postings_done' => $postingsTotal - $postingsPending,
            'postings_failed' => $postingsFailed,
            'assignments' => $assignmentIds->count(),
            'matches' => $matchCount,
            'strong_matches' => $strongCount,
            'matching_queued' => $matchingQueued,
            'worker_warning' => $workerWarning,
        ];
    }

    /**
     * Aantal jobs van deze klasse dat nog in de (database-)wachtrij staat of bezig is.
     */
    private function queuedJobs(string $jobClass): int
    {
        if (config('queue.default') !== 'database') {
            return 0;
        }

        try {
            return DB::table((string) config('queue.connections.database.table', 'jobs'))
                ->where('payload', 'like', '%'.$jobClass.'%')
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }

    private function key(User $user): string
    {
        return 'source-search:'.$user->id;
    }
}

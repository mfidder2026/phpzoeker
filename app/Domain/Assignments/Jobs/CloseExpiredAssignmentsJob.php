<?php

namespace App\Domain\Assignments\Jobs;

use App\Domain\Assignments\Enums\AssignmentStatus;
use App\Domain\Assignments\Models\Assignment;
use App\Settings\IngestSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Elk uur (§9.5, FR-O5): status `gesloten` als de deadline is verstreken of als de opdracht
 * een instelbaar aantal dagen in geen enkele bron meer is gezien.
 */
class CloseExpiredAssignmentsJob implements ShouldQueue
{
    use Queueable;

    public function handle(IngestSettings $settings): int
    {
        $closed = 0;

        $expired = Assignment::query()->open()
            ->where('status', '!=', AssignmentStatus::Aangeboden->value)
            ->where(function ($query) use ($settings): void {
                $query->where('deadline_at', '<', now())
                    ->orWhere('last_seen_at', '<', now()->subDays($settings->close_after_unseen_days));
            })
            ->get();

        foreach ($expired as $assignment) {
            $assignment->update(['status' => AssignmentStatus::Gesloten, 'closed_at' => now()]);
            $closed++;
        }

        return $closed;
    }
}

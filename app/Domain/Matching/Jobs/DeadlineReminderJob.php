<?php

namespace App\Domain\Matching\Jobs;

use App\Domain\Matching\Enums\MatchStatus;
use App\Domain\Matching\Models\CandidateMatch;
use App\Domain\Matching\Notifications\DeadlineReminderNotification;
use App\Domain\Matching\Notifications\MatchNotifier;
use App\Settings\NotificationSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

/**
 * Elk uur (FR-N3): shortlist-matches waarvan de deadline binnen 24 uur valt en die nog niet zijn
 * aangeboden, krijgen één herinnering.
 */
class DeadlineReminderJob implements ShouldQueue
{
    use Queueable;

    public function handle(NotificationSettings $settings): void
    {
        $matches = CandidateMatch::query()
            ->with(['assignment', 'candidate'])
            ->where('status', MatchStatus::Shortlist->value)
            ->whereNull('deadline_reminded_at')
            ->whereHas('assignment', fn (Builder $query) => $query->whereBetween('deadline_at', [now(), now()->addHours($settings->reminder_deadline_hours)]))
            ->get();

        $recipients = MatchNotifier::accountManagers();

        foreach ($matches as $match) {
            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new DeadlineReminderNotification($match, $settings->direct_mail));
            }

            $match->forceFill(['deadline_reminded_at' => now()])->saveQuietly();
        }
    }
}

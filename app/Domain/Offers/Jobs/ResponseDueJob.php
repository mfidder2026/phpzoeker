<?php

namespace App\Domain\Offers\Jobs;

use App\Domain\Offers\Models\Offer;
use App\Domain\Offers\Notifications\ResponseOverdueNotification;
use App\Domain\Offers\Support\Workdays;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

/**
 * Elk uur op werkdagen (§11.6, §14): aanbiedingen waarvan de verwachte reactie is verstreken
 * geven één melding aan de aanbieder en de beheerder (Michel), met de knop "Opvolgmail maken".
 */
class ResponseDueJob implements ShouldQueue
{
    use Queueable;

    public function handle(GeneralSettings $settings): void
    {
        if (! Workdays::isWorkday(now())) {
            return;
        }

        $offers = Offer::query()->overdue()->whereNull('overdue_notified_at')->with(['candidate', 'assignment', 'sentBy', 'organization', 'assignmentSource'])->get();

        foreach ($offers as $offer) {
            $recipients = User::query()
                ->whereKey(array_filter([$offer->sent_by]))
                ->orWhere('email', $settings->admin_email)
                ->get();

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new ResponseOverdueNotification($offer));
            }

            $offer->forceFill(['overdue_notified_at' => now()])->saveQuietly();
        }
    }
}

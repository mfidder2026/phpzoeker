<?php

namespace App\Domain\Offers\Jobs;

use App\Domain\Offers\Actions\TrackReplies;
use App\Domain\Offers\Mailers\SenderConnection;
use App\Settings\MailSettings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Elke 15 minuten (§16.4): reacties op aanbiedingsmails zoeken. Alleen als verzenden via
 * Microsoft 365 is verbonden; bij SMTP is er geen mailbox om in te kijken.
 */
class ReplyTrackerJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 900;

    public function __construct()
    {
        $this->onQueue('mail');
    }

    public function handle(MailSettings $settings, TrackReplies $tracker): void
    {
        if ($settings->provider !== 'graph' || (new SenderConnection($settings))->refreshToken() === null) {
            return;
        }

        $tracker->handle();
    }
}

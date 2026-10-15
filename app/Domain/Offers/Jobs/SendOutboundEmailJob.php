<?php

namespace App\Domain\Offers\Jobs;

use App\Domain\Offers\Actions\SendOutboundEmail;
use App\Domain\Offers\Models\OutboundEmail;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Verstuurt één goedgekeurde mail (§13.5). Geen automatische retry: na een fout staat de mail op
 * "fout" en probeert een mens het opnieuw.
 */
class SendOutboundEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $emailId, public int $userId)
    {
        $this->onQueue('mail');
    }

    public function uniqueId(): string
    {
        return (string) $this->emailId;
    }

    public function handle(SendOutboundEmail $action): void
    {
        $email = OutboundEmail::query()->findOrFail($this->emailId);
        $user = User::query()->findOrFail($this->userId);

        try {
            $action->handle($email, $user);

            Notification::make()->success()->title('Verzonden: '.$email->subject)->sendToDatabase($user);
        } catch (Throwable $exception) {
            Notification::make()->danger()->title('Versturen mislukt')->body($exception->getMessage())->sendToDatabase($user);
        }
    }
}

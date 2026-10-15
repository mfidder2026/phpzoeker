<?php

namespace App\Domain\Offers\Jobs;

use App\Ai\Runs\RunTracker;
use App\Domain\Offers\Actions\PrepareOfferMail;
use App\Domain\Offers\Models\Offer;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * "Mailtekst maken" op de achtergrond (§15.4).
 */
class PrepareOfferMailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $offerId, public string $runId, public ?int $userId = null)
    {
        $this->onQueue('ai');
    }

    public function handle(PrepareOfferMail $action): void
    {
        $offer = Offer::query()->findOrFail($this->offerId);
        $run = RunTracker::resume($this->runId);

        try {
            $action->handle($offer, $run, $this->userId !== null ? User::query()->find($this->userId) : null);
        } catch (Throwable $exception) {
            if ($run->run->status->isOpen()) {
                $run->fail($exception);
            }

            report($exception);
        }
    }
}

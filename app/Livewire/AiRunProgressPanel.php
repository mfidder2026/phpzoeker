<?php

namespace App\Livewire;

use App\Ai\Runs\AiRun;
use App\Ai\Runs\AiRunStatus;
use App\Filament\Support\Access;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Voortgangspaneel bij een AI-actie die de gebruiker zelf start (§15.4, FR-AI6): meter,
 * stappenlijst, annuleren en "op de achtergrond verder".
 */
class AiRunProgressPanel extends Component
{
    public ?string $runId = null;

    public bool $open = false;

    public bool $finishedNotified = false;

    #[On('ai-run-started')]
    public function show(string $runId): void
    {
        $run = $this->findRun($runId);

        if ($run === null) {
            return;
        }

        $this->runId = $runId;
        $this->open = true;
        $this->finishedNotified = false;
    }

    public function cancel(): void
    {
        $run = $this->run();

        if ($run === null || ! $run->status->isOpen()) {
            return;
        }

        if ($run->status === AiRunStatus::Queued) {
            $run->forceFill(['status' => AiRunStatus::Cancelled, 'cancel_requested' => true, 'finished_at' => now()])->save();
        } else {
            // De job controleert deze vlag tussen stappen en stopt netjes.
            $run->forceFill(['cancel_requested' => true])->save();
        }

        $this->dispatch('ai-run-finished');
    }

    public function background(): void
    {
        $this->open = false;

        Notification::make()
            ->info()
            ->title('De AI-taak loopt op de achtergrond verder')
            ->body('De AI-meter rechtsboven laat de voortgang zien.')
            ->send();
    }

    public function close(): void
    {
        $this->open = false;
        $this->runId = null;
    }

    private function findRun(string $runId): ?AiRun
    {
        $run = AiRun::query()->find($runId);

        if ($run === null) {
            return null;
        }

        $user = Access::user();

        return Access::isBeheerder() || $run->started_by === $user?->id ? $run : null;
    }

    private function run(): ?AiRun
    {
        return $this->runId !== null ? $this->findRun($this->runId) : null;
    }

    public function render(): View
    {
        $run = $this->open ? $this->run() : null;

        if ($run !== null && ! $run->status->isOpen() && ! $this->finishedNotified) {
            $this->finishedNotified = true;
            $this->dispatch('ai-run-finished');
        }

        return view('livewire.ai-run-progress-panel', ['run' => $run]);
    }
}

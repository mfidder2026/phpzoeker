<?php

namespace App\Livewire;

use App\Domain\Sources\Mailbox\MicrosoftLogin;
use App\Domain\Sources\Models\Source;
use App\Filament\Support\Access;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Throwable;

/**
 * Inlogvenster voor de Microsoft 365-mailbox: toont de code en de knop naar de inlogpagina van
 * Microsoft, en controleert zelf of het inloggen gelukt is.
 */
class MicrosoftLoginPanel extends Component
{
    public int $sourceId;

    /** idle | waiting | connected | expired | error */
    public string $state = 'idle';

    public ?string $userCode = null;

    public ?string $verificationUri = null;

    public ?string $message = null;

    public function mount(int $sourceId): void
    {
        abort_unless(Access::isBeheerder(), 403);

        $this->sourceId = $sourceId;
        $this->start();
    }

    public function start(): void
    {
        try {
            $result = app(MicrosoftLogin::class)->start($this->source());
            $this->userCode = $result['user_code'];
            $this->verificationUri = $result['verification_uri'];
            $this->state = 'waiting';
            $this->message = null;
        } catch (Throwable $exception) {
            $this->state = 'error';
            $this->message = $exception->getMessage();
        }
    }

    public function check(): void
    {
        if ($this->state !== 'waiting') {
            return;
        }

        try {
            $status = app(MicrosoftLogin::class)->poll($this->source());
        } catch (Throwable $exception) {
            $this->state = 'error';
            $this->message = $exception->getMessage();

            return;
        }

        if ($status === 'connected') {
            $this->state = 'connected';
            $account = (string) ($this->source()->refresh()->config['connected_account'] ?? '');

            Notification::make()->success()->title('Verbonden met Microsoft 365')
                ->body($account !== '' ? 'Ingelogd als '.$account.'.' : null)->send();

            $this->dispatch('microsoft-connected');
        } elseif ($status === 'expired') {
            $this->state = 'expired';
        }
    }

    private function source(): Source
    {
        return Source::query()->findOrFail($this->sourceId);
    }

    public function render(): View
    {
        return view('livewire.microsoft-login-panel', [
            'mailbox' => (string) ($this->source()->config['mailbox'] ?? ''),
        ]);
    }
}

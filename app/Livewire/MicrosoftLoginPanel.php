<?php

namespace App\Livewire;

use App\Domain\Offers\Mailers\SenderConnection;
use App\Domain\Sources\Mailbox\MicrosoftConnection;
use App\Domain\Sources\Mailbox\MicrosoftLogin;
use App\Domain\Sources\Mailbox\SourceConnection;
use App\Domain\Sources\Models\Source;
use App\Filament\Support\Access;
use App\Settings\MailSettings;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Throwable;

/**
 * Inlogvenster voor Microsoft 365: toont de code en de knop naar de inlogpagina van Microsoft,
 * en controleert zelf of het inloggen gelukt is. Voor een mailbox-bron (lezen) of voor de
 * afzendermailbox (versturen).
 */
class MicrosoftLoginPanel extends Component
{
    public ?int $sourceId = null;

    /** source | sender */
    public string $target = 'source';

    /** idle | waiting | connected | expired | error */
    public string $state = 'idle';

    public ?string $userCode = null;

    public ?string $verificationUri = null;

    public ?string $message = null;

    public function mount(?int $sourceId = null, string $target = 'source'): void
    {
        abort_unless(Access::isBeheerder(), 403);

        $this->sourceId = $sourceId;
        $this->target = $target === 'sender' ? 'sender' : 'source';
        $this->start();
    }

    public function start(): void
    {
        try {
            $result = app(MicrosoftLogin::class)->start($this->connection());
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
            $status = app(MicrosoftLogin::class)->poll($this->connection());
        } catch (Throwable $exception) {
            $this->state = 'error';
            $this->message = $exception->getMessage();

            return;
        }

        if ($status === 'connected') {
            $this->state = 'connected';
            $account = (string) $this->connection()->account();

            Notification::make()->success()->title('Verbonden met Microsoft 365')
                ->body($account !== '' ? 'Ingelogd als '.$account.'.' : null)->send();

            $this->dispatch('microsoft-connected');
        } elseif ($status === 'expired') {
            $this->state = 'expired';
        }
    }

    private function connection(): MicrosoftConnection
    {
        if ($this->target === 'sender') {
            app()->forgetInstance(MailSettings::class);

            return new SenderConnection(app(MailSettings::class));
        }

        return new SourceConnection(Source::query()->findOrFail($this->sourceId));
    }

    public function render(): View
    {
        return view('livewire.microsoft-login-panel', [
            'mailbox' => $this->connection()->mailbox(),
            'sending' => $this->target === 'sender',
        ]);
    }
}

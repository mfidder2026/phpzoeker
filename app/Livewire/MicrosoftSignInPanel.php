<?php

namespace App\Livewire;

use App\Domain\Auth\MicrosoftSignInConnection;
use App\Domain\Auth\SignInWithMicrosoft;
use App\Domain\Sources\Mailbox\MicrosoftLogin;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * "Inloggen met Microsoft" op de inlogpagina: toont een code voor de inlogpagina van Microsoft
 * en logt de gebruiker in zodra Microsoft het inloggen bevestigt.
 */
class MicrosoftSignInPanel extends Component
{
    /** Sleutel van deze inlogpoging (server-side voor de cache van de code). */
    public string $attempt = '';

    /** idle | waiting | error | expired */
    public string $state = 'idle';

    public ?string $userCode = null;

    public ?string $verificationUri = null;

    public ?string $message = null;

    public function mount(): void
    {
        $this->attempt = Str::random(40);
    }

    public function start(): void
    {
        if (Filament::auth()->check()) {
            $this->redirect(Filament::getUrl());

            return;
        }

        $limiter = 'microsoft-sign-in:'.request()->ip();

        if (RateLimiter::tooManyAttempts($limiter, 5)) {
            $this->state = 'error';
            $this->message = 'Te veel pogingen. Probeer het over een minuut opnieuw.';

            return;
        }

        RateLimiter::hit($limiter, 60);

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

        $connection = $this->connection();

        try {
            $status = app(MicrosoftLogin::class)->poll($connection);

            if ($status === 'expired') {
                $this->state = 'expired';

                return;
            }

            if ($status !== 'connected') {
                return;
            }

            $result = app(SignInWithMicrosoft::class)->handle($connection);
        } catch (RuntimeException $exception) {
            $this->state = 'error';
            $this->message = $exception->getMessage();

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->state = 'error';
            $this->message = 'Inloggen met Microsoft is mislukt.';

            return;
        }

        Filament::auth()->login($result['user'], remember: true);
        session()->regenerate();

        if ($result['connected'] !== []) {
            Notification::make()->success()->title('Ook gekoppeld met Microsoft 365')
                ->body(ucfirst(implode(' en ', $result['connected'])).'. Stuur een testmail via Instellingen → Mail.')
                ->persistent()->send();
        }

        $this->redirect(Filament::getUrl());
    }

    private function connection(): MicrosoftSignInConnection
    {
        return new MicrosoftSignInConnection($this->attempt);
    }

    public function render(): View
    {
        return view('livewire.microsoft-sign-in-panel');
    }
}

<?php

namespace App\Domain\Offers\Notifications;

use App\Domain\Offers\Models\Offer;
use App\Filament\Resources\Offers\OfferResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Reactie ontvangen" (§14): reply-tracking vond een antwoord op een aanbiedingsmail.
 */
class ReplyReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Offer $offer, public string $from, public string $subject, public string $preview)
    {
        $this->onQueue('mail');
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return filled($notifiable->email) ? ['database', 'mail'] : ['database'];
    }

    public function title(): string
    {
        return 'Reactie ontvangen — '.$this->offer->candidate->name.' × '.$this->offer->assignment->title;
    }

    public function url(): string
    {
        return OfferResource::getUrl('view', ['record' => $this->offer]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return FilamentNotification::make()
            ->info()
            ->icon('heroicon-o-inbox-arrow-down')
            ->title($this->title())
            ->body($this->from.': '.$this->subject)
            ->actions([Action::make('bekijken')->label('Aanbieding openen')->url($this->url())])
            ->getDatabaseMessage();
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[PHP Zoeker] '.$this->title())
            ->greeting('Reactie ontvangen')
            ->line('Van **'.$this->from.'**: '.$this->subject)
            ->line($this->preview)
            ->action('Aanbieding openen', $this->url())
            ->salutation('PHP Zoeker');
    }
}

<?php

namespace App\Domain\Offers\Notifications;

use App\Domain\Offers\Models\Offer;
use App\Domain\Offers\Support\ResponseTerm;
use App\Filament\Resources\Offers\OfferResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Reactie verlopen" (§14): de verwachte reactie is verstreken zonder reactie.
 */
class ResponseOverdueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Offer $offer)
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
        return 'Reactie verlopen — '.$this->offer->candidate->name.' × '.$this->offer->assignment->title;
    }

    public function body(): string
    {
        return $this->offer->viaName().' · '.ResponseTerm::indicator($this->offer)['label'];
    }

    public function url(): string
    {
        return OfferResource::getUrl('view', ['record' => $this->offer]).'?opvolgen=1';
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return FilamentNotification::make()
            ->warning()
            ->icon('heroicon-o-clock')
            ->title($this->title())
            ->body($this->body())
            ->actions([Action::make('opvolgen')->label('Opvolgmail maken')->url($this->url())])
            ->getDatabaseMessage();
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[PHP Zoeker] '.$this->title())
            ->greeting('Reactie verlopen')
            ->line('**'.$this->offer->candidate->name.'** op **'.$this->offer->assignment->title.'** via '.$this->offer->viaName().'.')
            ->line($this->body())
            ->action('Opvolgmail maken', $this->url())
            ->salutation('PHP Zoeker');
    }
}

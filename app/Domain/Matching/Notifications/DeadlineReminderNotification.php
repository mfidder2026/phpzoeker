<?php

namespace App\Domain\Matching\Notifications;

use App\Domain\Matching\Models\CandidateMatch;
use App\Filament\Resources\Matches\MatchResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Herinnering: een match staat op de shortlist en de deadline valt binnen 24 uur (FR-N3).
 */
class DeadlineReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public CandidateMatch $match, public bool $mail = true)
    {
        $this->onQueue('mail');
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $this->mail && filled($notifiable->email) ? ['database', 'mail'] : ['database'];
    }

    public function title(): string
    {
        return 'Nog niet aangeboden — deadline '.$this->match->assignment->deadline_at?->format('d-m H:i');
    }

    public function body(): string
    {
        return $this->match->candidate->name.' × '.$this->match->assignment->title;
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
            ->actions([Action::make('bekijken')->label('Bekijken')->url(MatchResource::getUrl('view', ['record' => $this->match]))])
            ->getDatabaseMessage();
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[PHP Zoeker] '.$this->title())
            ->greeting('Deadline binnenkort')
            ->line('**'.$this->body().'** staat op de shortlist maar is nog niet aangeboden.')
            ->action('Match bekijken', MatchResource::getUrl('view', ['record' => $this->match]))
            ->salutation('PHP Zoeker');
    }
}

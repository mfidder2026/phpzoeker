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
 * Directe melding bij een sterke match (FR-N2); urgent als de deadline binnen 48 uur valt.
 */
class StrongMatchNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public CandidateMatch $match, public bool $urgent, public bool $mail = true)
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
        return ($this->urgent ? 'Urgent: ' : '').'sterke match — '.$this->match->candidate->name.' × '.$this->match->assignment->title;
    }

    public function body(): string
    {
        $assignment = $this->match->assignment;

        return collect([
            'Score '.$this->match->score,
            $assignment->client_name ?? $assignment->intermediary_name,
            $assignment->deadline_at ? 'deadline '.$assignment->deadline_at->format('d-m-Y H:i') : null,
        ])->filter()->implode(' · ');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return FilamentNotification::make()
            ->success()
            ->icon('heroicon-o-sparkles')
            ->title($this->title())
            ->body($this->body())
            ->actions([Action::make('bekijken')->label('Bekijken')->url(MatchResource::getUrl('view', ['record' => $this->match]))])
            ->getDatabaseMessage();
    }

    public function toMail(User $notifiable): MailMessage
    {
        $assessment = $this->match->assessment ?? [];

        return (new MailMessage)
            ->subject('[PHP Zoeker] '.ucfirst($this->title()))
            ->greeting($this->urgent ? 'Sterke match met een deadline binnenkort' : 'Nieuwe sterke match')
            ->line('**'.$this->match->assignment->title.'** voor **'.$this->match->candidate->name.'**')
            ->line($this->body())
            ->line((string) ($assessment['toelichting'] ?? ''))
            ->action('Match bekijken', MatchResource::getUrl('view', ['record' => $this->match]))
            ->salutation('PHP Zoeker');
    }
}

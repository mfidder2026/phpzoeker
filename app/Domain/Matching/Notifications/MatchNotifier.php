<?php

namespace App\Domain\Matching\Notifications;

use App\Domain\Matching\Models\CandidateMatch;
use App\Domain\Notifications\TeamsNotifier;
use App\Enums\Role;
use App\Filament\Resources\Matches\MatchResource;
use App\Models\User;
use App\Settings\NotificationSettings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Interne meldingen over matches (§14). Gaat alleen naar gebruikers van de app; er gaat nooit
 * mail naar externe partijen (R1).
 */
class MatchNotifier
{
    public function __construct(private readonly NotificationSettings $settings) {}

    /**
     * @return Collection<int, User>
     */
    public static function accountManagers(): Collection
    {
        // whereHas in plaats van User::role(): dat gooit een fout zolang de rollen nog niet zijn aangemaakt.
        return User::query()->whereHas('roles', fn ($query) => $query->whereIn('name', [Role::Accountmanager->value, Role::Beheerder->value]))->get();
    }

    public function strongMatch(CandidateMatch $match): void
    {
        $recipients = self::accountManagers();
        $deadline = $match->assignment->deadline_at;
        $urgent = $deadline !== null && $deadline->isFuture() && $deadline->lessThanOrEqualTo(now()->addHours($this->settings->urgent_deadline_hours));

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new StrongMatchNotification($match, $urgent, $this->settings->direct_mail));
        }

        $notification = new StrongMatchNotification($match, $urgent, false);
        app(TeamsNotifier::class)->send($notification->title(), $notification->body(), MatchResource::getUrl('view', ['record' => $match]), 'Match bekijken');

        $match->forceFill(['notified_at' => now()])->saveQuietly();
    }
}

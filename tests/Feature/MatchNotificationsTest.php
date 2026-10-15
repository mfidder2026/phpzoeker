<?php

use App\Ai\Budget\AiBudgetGuard;
use App\Ai\Runs\AiRun;
use App\Domain\Matching\Actions\MatchCandidate;
use App\Domain\Matching\Enums\MatchStatus;
use App\Domain\Matching\Jobs\DailyDigestJob;
use App\Domain\Matching\Jobs\DeadlineReminderJob;
use App\Domain\Matching\Mail\DailyDigestMail;
use App\Domain\Matching\Notifications\DeadlineReminderNotification;
use App\Domain\Matching\Notifications\StrongMatchNotification;
use App\Enums\Role;
use App\Settings\NotificationSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Carbon::setTestNow('2026-10-15 10:00:00');
    aiProfile();
    Http::fake(['ollama.test/*' => Http::response(ollamaReply(assessmentJson()))]);
});

it('notifies account managers once about a new strong match', function () {
    Notification::fake();
    $manager = userWithRoles(Role::Accountmanager);
    $reviewer = userWithRoles(Role::Reviewer);
    $assignment = openAssignment();
    $candidate = matchableCandidate();

    $match = app(MatchCandidate::class)->handle($assignment, $candidate);
    app(MatchCandidate::class)->handle($assignment, $candidate);

    Notification::assertSentToTimes($manager, StrongMatchNotification::class, 1);
    Notification::assertNotSentTo($reviewer, StrongMatchNotification::class);
    Notification::assertSentTo($manager, fn (StrongMatchNotification $notification, array $channels): bool => ! $notification->urgent && in_array('mail', $channels, true));

    expect($match->refresh()->notified_at)->not->toBeNull();
});

it('marks a strong match as urgent when the deadline is within 48 hours', function () {
    Notification::fake();
    $manager = userWithRoles(Role::Accountmanager);

    app(MatchCandidate::class)->handle(openAssignment(['deadline_at' => now()->addHours(30)]), matchableCandidate());

    Notification::assertSentTo($manager, fn (StrongMatchNotification $notification): bool => $notification->urgent && str_starts_with($notification->title(), 'Urgent'));
});

it('sends the daily digest on workdays with new strong and possible matches', function () {
    Mail::fake();
    $settings = app(NotificationSettings::class);
    $settings->digest_recipients = ['team@fremen.test'];
    $settings->save();

    $match = app(MatchCandidate::class)->handle(openAssignment(), matchableCandidate());

    (new DailyDigestJob)->handle(app(NotificationSettings::class));
    (new DailyDigestJob)->handle(app(NotificationSettings::class));

    Mail::assertSent(DailyDigestMail::class, fn (DailyDigestMail $mail): bool => $mail->hasTo('team@fremen.test') && $mail->matches->count() === 1);
    Mail::assertSent(DailyDigestMail::class, fn (DailyDigestMail $mail): bool => $mail->matches->isEmpty());

    expect($match->refresh()->digested_at)->not->toBeNull();

    $rendered = (new DailyDigestMail(collect([$match->refresh()->load('assignment', 'candidate')]), '15-10-2026'))->render();
    expect($rendered)->toContain('Laravel Developer')->toContain('Sterke match');
});

it('skips the digest on weekends and Dutch public holidays', function (string $date) {
    Mail::fake();
    Carbon::setTestNow($date);

    (new DailyDigestJob)->handle(app(NotificationSettings::class));

    Mail::assertNothingSent();
})->with(['zaterdag' => '2026-10-17 08:00:00', 'eerste kerstdag' => '2026-12-25 08:00:00', 'koningsdag' => '2027-04-27 08:00:00']);

it('schedules the digest at 08:00 on weekdays', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => $event->description === 'dagelijkse-digest');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 8 * * 1-5')
        ->and($event->timezone)->toBe('Europe/Amsterdam');
});

it('reminds once about a shortlisted match with a deadline within 24 hours', function () {
    Notification::fake();
    $manager = userWithRoles(Role::Accountmanager);
    $match = app(MatchCandidate::class)->handle(openAssignment(['deadline_at' => now()->addHours(20)]), matchableCandidate());
    $match->setStatus(MatchStatus::Shortlist);

    (new DeadlineReminderJob)->handle(app(NotificationSettings::class));
    (new DeadlineReminderJob)->handle(app(NotificationSettings::class));

    Notification::assertSentToTimes($manager, DeadlineReminderNotification::class, 1);
});

it('warns the beheerder once at 80% of the AI budget', function () {
    $beheerder = userWithRoles(Role::Beheerder);
    AiRun::query()->create(['agent' => 'test', 'task' => 'extract', 'status' => 'succeeded', 'cost_eur' => 8.2, 'queued_at' => now()]);

    app(AiBudgetGuard::class)->notifyThresholds();
    app(AiBudgetGuard::class)->notifyThresholds();

    expect($beheerder->notifications()->count())->toBe(1)
        ->and($beheerder->notifications()->first()?->data['title'])->toBe('AI-dagbudget bijna op');
});

<?php

use App\Domain\Assignments\Jobs\CloseExpiredAssignmentsJob;
use App\Domain\Matching\Jobs\DailyDigestJob;
use App\Domain\Matching\Jobs\DeadlineReminderJob;
use App\Domain\Matching\Jobs\RematchAllJob;
use App\Domain\Sources\Jobs\DispatchDueSourcesJob;
use App\Domain\Sources\Jobs\SourceHealthCheckJob;
use App\Settings\NotificationSettings;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Planning (§16.4)
|--------------------------------------------------------------------------
|
| Draait via `php artisan schedule:work` (Docker: dienst `scheduler`). Het interval per bron
| staat in de bron zelf; deze taak kijkt elke minuut welke bronnen aan de beurt zijn.
|
*/

$timezone = 'Europe/Amsterdam';

try {
    $digestTime = app(NotificationSettings::class)->digest_time;
} catch (Throwable) {
    // Nog geen database of instellingen (bijv. tijdens de eerste migratie).
    $digestTime = '08:00';
}

Schedule::job(new DispatchDueSourcesJob)->everyMinute()->name('bronnen-ophalen')->withoutOverlapping();
Schedule::job(new CloseExpiredAssignmentsJob)->hourly()->name('opdrachten-sluiten');
Schedule::job(new SourceHealthCheckJob)->dailyAt('07:00')->timezone($timezone)->name('bronnen-gezondheid');
Schedule::job(new RematchAllJob)->dailyAt('02:00')->timezone($timezone)->name('matches-herberekenen');
Schedule::job(new DeadlineReminderJob)->hourly()->name('deadline-herinneringen');
Schedule::job(new DailyDigestJob)->weekdays()->dailyAt(preg_match('/^\d{2}:\d{2}$/', $digestTime) ? $digestTime : '08:00')->timezone($timezone)->name('dagelijkse-digest');

<?php

use App\Domain\Assignments\Jobs\CloseExpiredAssignmentsJob;
use App\Domain\Sources\Jobs\DispatchDueSourcesJob;
use App\Domain\Sources\Jobs\SourceHealthCheckJob;
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

Schedule::job(new DispatchDueSourcesJob)->everyMinute()->name('bronnen-ophalen')->withoutOverlapping();
Schedule::job(new CloseExpiredAssignmentsJob)->hourly()->name('opdrachten-sluiten');
Schedule::job(new SourceHealthCheckJob)->dailyAt('07:00')->timezone('Europe/Amsterdam')->name('bronnen-gezondheid');

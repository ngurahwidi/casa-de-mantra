<?php

use App\Actions\Maintenance\CheckSystemHealth;
use App\Actions\Maintenance\PruneTemporaryUploads;
use App\Actions\Outbox\ProcessOutboxEvents;
use App\Actions\Reservation\CompleteReservation;
use App\Actions\Reservation\ExpireStaleReservations;
use App\Models\NotificationLog;
use App\Models\OutboxEvent;
use App\Services\Notification\ReservationReminders;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled jobs (T9.1)
|--------------------------------------------------------------------------
|
| Each command only calls its action; the rules live there. They can also be
| run by hand. Production runs `php artisan schedule:run` from cron every
| minute (T12.2); emails the reminders queue need the queue worker.
|
*/

Artisan::command('reservations:expire', function (ExpireStaleReservations $expire): void {
    $this->info("Expired {$expire->handle()} reservation(s).");
})->purpose('Expire unpaid reservations whose payment window has ended');

Artisan::command('reservations:complete', function (CompleteReservation $complete): void {
    $this->info("Completed {$complete->completeCheckedOut()} reservation(s).");
})->purpose('Complete confirmed reservations whose check-out date has passed');

Artisan::command('reservations:remind', function (ReservationReminders $reminders): void {
    $this->info("Queued {$reminders->remindAdminOfUnansweredRequests()} unanswered request reminder(s).");
    $this->info("Queued {$reminders->remindGuestsOfPaymentDue()} payment reminder(s).");
    $this->info("Queued {$reminders->sendPreArrivalReminders()} pre-arrival reminder(s).");
})->purpose('Queue the admin, payment and pre-arrival reminder emails that are due');

Artisan::command('outbox:process', function (ProcessOutboxEvents $process): void {
    $this->info("Processed {$process->handle()} outbox event(s).");
})->purpose('Process pending outbox events');

Artisan::command('uploads:prune', function (PruneTemporaryUploads $prune): void {
    ['proofs' => $proofs, 'temporary' => $temporary] = $prune->handle();

    $this->info("Deleted {$proofs} unsaved transfer proof(s) and {$temporary} temporary upload(s).");
})->purpose('Delete uploaded files that were never saved to a record');

Artisan::command('system:health', function (CheckSystemHealth $check): void {
    $problems = $check->handle();

    foreach ($problems as $problem) {
        report($problem);
        $this->error($problem->getMessage());
    }

    if ($problems === []) {
        $this->info('All system health checks passed.');
    }
})->purpose('Check disk space, the database, failed jobs and the queue, and report problems to Sentry');

Schedule::command('reservations:expire')->everyTenMinutes()->withoutOverlapping();

/*
 * Hourly rather than daily: the action itself works out "today" in the property timezone, which
 * an admin can change, so an hourly run completes each stay within an hour of the property's
 * midnight whatever the timezone. A reservation is only ever completed once.
 */
Schedule::command('reservations:complete')->hourly()->withoutOverlapping();

Schedule::command('reservations:remind')->everyFifteenMinutes()->withoutOverlapping();

Schedule::command('outbox:process')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('model:prune', [
    '--model' => [NotificationLog::class, OutboxEvent::class],
])->daily()->withoutOverlapping();

Schedule::command('uploads:prune')->daily()->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Monitoring and backups (T12.4)
|--------------------------------------------------------------------------
|
| system:health reports each problem it finds as its own Sentry issue and still exits successfully,
| so its Sentry cron monitor only answers "is the scheduler running?". It is the single monitor
| included in Sentry's free plan: when cron, the scheduler or the server stops, Sentry raises a
| missed check-in. The jobs above have no monitor of their own; their exceptions still reach
| Sentry as issues.
|
| Backups run at night in the property's region (the times are UTC). backup:run makes a gzipped
| MySQL dump plus the uploaded files, and backup:monitor checks that the newest backup is less
| than a day old. Failures are emailed (config/backup.php) and reported to Sentry
| (App\Listeners\ReportBackupProblems).
|
*/

Schedule::command('system:health')
    ->hourly()
    ->withoutOverlapping()
    ->sentryMonitor(monitorSlug: 'system-health', checkInMargin: 15, maxRuntime: 10);

Schedule::command('backup:clean')->dailyAt('17:00')->withoutOverlapping();

Schedule::command('backup:run')->dailyAt('17:30')->withoutOverlapping();

Schedule::command('backup:monitor')->dailyAt('19:00')->withoutOverlapping();

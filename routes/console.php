<?php

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

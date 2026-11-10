<?php

namespace App\Jobs;

use App\Models\NotificationLog;
use App\Services\Notification\ReservationNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Delivers one queued notification; each email has its own job, so a retry cannot resend another.
 *
 * The timeout stays below the database queue's retry_after (90 seconds).
 */
class SendReservationNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 60;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public readonly NotificationLog $notificationLog) {}

    /**
     * @return list<int> seconds to wait before each retry, long enough to ride out a mail server outage
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(ReservationNotifier $notifier): void
    {
        $notifier->deliver($this->notificationLog);
    }

    public function failed(?Throwable $exception): void
    {
        app(ReservationNotifier::class)->recordFailure(
            $this->notificationLog,
            $exception?->getMessage() ?? 'The notification could not be delivered.',
        );
    }
}

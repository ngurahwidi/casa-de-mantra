<?php

namespace App\Listeners;

use App\Events\ReservationStatusChanged;
use App\Services\Notification\ReservationNotifier;

class QueueReservationNotifications
{
    public function __construct(private readonly ReservationNotifier $notifier) {}

    public function handle(ReservationStatusChanged $event): void
    {
        $this->notifier->queueForStatusChange($event->reservation, $event->statusLog);
    }
}

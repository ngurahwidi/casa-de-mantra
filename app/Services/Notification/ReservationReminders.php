<?php

namespace App\Services\Notification;

use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Models\PropertyProfile;
use App\Models\Reservation;
use App\Models\ReservationStatusLog;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Finds the reservations that are due a reminder and queues it through ReservationNotifier.
 *
 * Each method may run as often as the scheduler likes (T9.1): a reminder is queued once per time
 * the reservation entered the status it is about, so running again sends nothing new, while a
 * reservation that is reactivated can be reminded about its new payment window. As with the
 * status change emails, only reservations made on the website are reminded.
 */
class ReservationReminders
{
    /**
     * A payment reminder goes out this long before the payment window ends, or halfway through
     * the window when it is shorter than twice this, so it never follows right after the approval.
     */
    public const PAYMENT_REMINDER_HOURS = 12;

    /** The pre-arrival reminder goes out this many days before check-in (H-3). */
    public const PRE_ARRIVAL_DAYS = 3;

    public function __construct(private readonly ReservationNotifier $notifier) {}

    /**
     * Reminds the property about requests still pending pending_reminder_hours after they came in.
     *
     * @return int the number of reminders queued
     */
    public function remindAdminOfUnansweredRequests(): int
    {
        $reservations = $this->websiteReservations(ReservationStatus::Pending)
            ->where('created_at', '<=', now()->subHours(PropertyProfile::current()->pending_reminder_hours))
            ->orderBy('created_at')
            ->get();

        return $this->queue($reservations, ReservationStatus::Pending, ReservationNotifier::ADMIN_PENDING_REMINDER);
    }

    /**
     * Reminds guests to pay before their payment window ends.
     *
     * Reservations that cannot expire are left out: those with a deposit, and those with a
     * transfer proof waiting for an admin, whose guest has already done their part.
     *
     * @return int the number of reminders queued
     */
    public function remindGuestsOfPaymentDue(): int
    {
        $reservations = $this->websiteReservations(ReservationStatus::AwaitingPayment)
            ->where('payment_status', ReservationPaymentStatus::Unpaid)
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addHours(self::PAYMENT_REMINDER_HOURS))
            ->whereDoesntHave('pendingPaymentProofs')
            ->orderBy('expires_at')
            ->get();

        return $this->queue(
            $reservations,
            ReservationStatus::AwaitingPayment,
            ReservationNotifier::PAYMENT_REMINDER,
            fn (Reservation $reservation, ReservationStatusLog $approved): bool => now()->getTimestamp()
                >= ($approved->created_at->getTimestamp() + $reservation->expires_at->getTimestamp()) / 2,
        );
    }

    /**
     * Reminds guests of a confirmed stay that starts within PRE_ARRIVAL_DAYS, in the property
     * timezone. A run that was missed is caught up on until the day before check-in.
     *
     * A stay confirmed on or after its reminder day is left out: the confirmation email the guest
     * has just received already says everything the reminder would.
     *
     * @return int the number of reminders queued
     */
    public function sendPreArrivalReminders(): int
    {
        $timezone = PropertyProfile::current()->timezone;
        $today = now($timezone)->toDateString();

        $reservations = $this->websiteReservations(ReservationStatus::Confirmed)
            ->where('check_in', '>', $today)
            ->where('check_in', '<=', Carbon::parse($today)->addDays(self::PRE_ARRIVAL_DAYS)->toDateString())
            ->orderBy('check_in')
            ->get();

        return $this->queue(
            $reservations,
            ReservationStatus::Confirmed,
            ReservationNotifier::PRE_ARRIVAL_REMINDER,
            fn (Reservation $reservation, ReservationStatusLog $confirmed): bool => $confirmed->created_at->lt(
                Carbon::parse($reservation->check_in->toDateString(), $timezone)->subDays(self::PRE_ARRIVAL_DAYS),
            ),
        );
    }

    /**
     * @return Builder<Reservation>
     */
    private function websiteReservations(ReservationStatus $status): Builder
    {
        return Reservation::query()
            ->where('status', $status)
            ->where('source', ReservationSource::DirectWeb);
    }

    /**
     * Queues the reminder for each reservation that is due it, keyed to the status log of the
     * change that put the reservation in its current status.
     *
     * @param  Collection<int, Reservation>  $reservations  all in $status
     * @param  (Closure(Reservation, ReservationStatusLog): bool)|null  $isDue
     * @return int the number of reminders queued
     */
    private function queue(Collection $reservations, ReservationStatus $status, string $code, ?Closure $isDue = null): int
    {
        $enteredStatus = ReservationStatusLog::query()
            ->whereIn('reservation_id', $reservations->modelKeys())
            ->where('to_status', $status)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->keyBy('reservation_id');

        $queued = 0;

        foreach ($reservations as $reservation) {
            $statusLog = $enteredStatus->get($reservation->getKey());

            if ($statusLog === null || ($isDue !== null && ! $isDue($reservation, $statusLog))) {
                continue;
            }

            $queued += (int) $this->notifier->queueReminder($reservation, $statusLog, $code);
        }

        return $queued;
    }
}

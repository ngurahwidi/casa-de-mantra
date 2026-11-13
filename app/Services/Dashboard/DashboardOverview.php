<?php

namespace App\Services\Dashboard;

use App\Data\Dashboard\Occupancy;
use App\Enums\PaymentStatus;
use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\Payment;
use App\Models\PropertyProfile;
use App\Models\Reservation;
use App\Models\ReservationNight;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the admin dashboard shows: the reservations that need an admin now and how full the
 * current month is. "Today" and "this month" are in the property timezone.
 */
class DashboardOverview
{
    /** Unpaid reservations awaiting payment are flagged this long before their payment window ends. */
    public const PAYMENT_DUE_WITHIN_HOURS = 12;

    /**
     * @return Builder<Reservation> oldest request first
     */
    public function pendingRequests(): Builder
    {
        return Reservation::query()
            ->where('status', ReservationStatus::Pending)
            ->orderBy('created_at');
    }

    public function oldestPendingRequestedAt(): ?CarbonImmutable
    {
        return $this->pendingRequests()->first(['created_at'])?->created_at?->toImmutable();
    }

    /**
     * Unpaid reservations awaiting payment whose payment window ends within
     * PAYMENT_DUE_WITHIN_HOURS, or has ended but ExpireStaleReservations has not run yet.
     * Reservations with a deposit or a transfer proof waiting do not expire, so they are left out.
     *
     * @return Builder<Reservation> soonest to expire first
     */
    public function paymentsDue(): Builder
    {
        return Reservation::query()
            ->where('status', ReservationStatus::AwaitingPayment)
            ->where('payment_status', ReservationPaymentStatus::Unpaid)
            ->where('expires_at', '<=', now()->addHours(self::PAYMENT_DUE_WITHIN_HOURS))
            ->whereDoesntHave('pendingPaymentProofs')
            ->orderBy('expires_at');
    }

    /**
     * Reservations with a transfer proof from the guest that an admin still has to check.
     *
     * @return Builder<Reservation>
     */
    public function proofsToVerify(): Builder
    {
        return Reservation::query()->whereHas('pendingPaymentProofs');
    }

    /**
     * Reservations holding their dates (awaiting payment, confirmed) that check in today.
     *
     * @return Builder<Reservation>
     */
    public function checkInsToday(): Builder
    {
        return $this->holdingDates()->where('check_in', $this->today());
    }

    /**
     * Reservations holding their dates (awaiting payment, confirmed) that check out today.
     *
     * @return Builder<Reservation>
     */
    public function checkOutsToday(): Builder
    {
        return $this->holdingDates()->where('check_out', $this->today());
    }

    /**
     * Check-ins and check-outs of today together.
     *
     * @return Builder<Reservation> check-outs first, as guests leave before others arrive
     */
    public function todaysStays(): Builder
    {
        $today = $this->today();

        return $this->holdingDates()
            ->where(fn (Builder $query): Builder => $query->where('check_in', $today)->orWhere('check_out', $today))
            ->orderBy('check_in')
            ->orderBy('code');
    }

    /**
     * Confirmed stays, current or upcoming, whose dates are not yet closed on the OTA calendars.
     *
     * @return Builder<Reservation> by check-in
     */
    public function notBlockedOnOta(): Builder
    {
        return Reservation::query()
            ->where('status', ReservationStatus::Confirmed)
            ->whereNull('ota_blocked_at')
            ->where('check_out', '>', $this->today())
            ->orderBy('check_in');
    }

    /**
     * Completed stays that are not paid in full, from every source, e.g. a guest who was to settle
     * the balance at check-out. Each comes with a `balance` attribute: the total less its paid
     * payments, worked out in SQL so the decimals stay exact.
     *
     * @return Builder<Reservation> longest outstanding first
     */
    public function outstandingBalances(): Builder
    {
        $balance = Payment::query()
            ->selectRaw('reservations.total - COALESCE(SUM(payments.amount), 0)')
            ->whereColumn('payments.reservation_id', 'reservations.id')
            ->where('payments.status', PaymentStatus::Paid);

        return Reservation::query()
            ->select('reservations.*')
            ->selectSub($balance, 'balance')
            ->where('status', ReservationStatus::Completed)
            ->whereIn('payment_status', [ReservationPaymentStatus::Unpaid, ReservationPaymentStatus::Deposit])
            ->orderBy('check_out')
            ->orderBy('code');
    }

    /**
     * Nights of confirmed and completed stays in the current month, out of every night of the
     * month on each active unit. Blocks are not sales and do not count.
     */
    public function occupancyThisMonth(): Occupancy
    {
        $first = CarbonImmutable::parse($this->today())->startOfMonth();
        $end = $first->addMonth();

        $sold = ReservationNight::query()
            ->join('reservation_rooms', 'reservation_rooms.id', '=', 'reservation_nights.reservation_room_id')
            ->join('reservations', 'reservations.id', '=', 'reservation_rooms.reservation_id')
            ->whereIn('reservations.status', [ReservationStatus::Confirmed, ReservationStatus::Completed])
            ->where('reservation_nights.night_date', '>=', $first->toDateString())
            ->where('reservation_nights.night_date', '<', $end->toDateString())
            ->count();

        return new Occupancy(
            $first->toDateString(),
            $sold,
            Unit::query()->active()->count() * $first->daysInMonth,
        );
    }

    /**
     * @return Builder<Reservation>
     */
    private function holdingDates(): Builder
    {
        return Reservation::query()->whereIn('status', ReservationStatus::blockingAvailability());
    }

    private function today(): string
    {
        return now(PropertyProfile::current()->timezone)->toDateString();
    }
}

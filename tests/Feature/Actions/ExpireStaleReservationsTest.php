<?php

namespace Tests\Feature\Actions;

use App\Actions\Reservation\ExpireStaleReservations;
use App\Contracts\AvailabilityProvider;
use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\OutboxEvent;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\ReservationStatusLog;
use App\Models\RoomType;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ExpireStaleReservationsTest extends TestCase
{
    use RefreshDatabase;

    private ExpireStaleReservations $expire;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-01 08:00:00');

        $this->expire = $this->app->make(ExpireStaleReservations::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_unpaid_reservation_past_its_deadline_expires(): void
    {
        $reservation = $this->awaitingPayment('2026-11-01 07:59:59');

        $this->assertSame(1, $this->expire->handle());

        $fresh = $reservation->fresh();
        $this->assertSame(ReservationStatus::Expired, $fresh->status);
        $this->assertSame('2026-11-01 07:59:59', $fresh->expires_at->toDateTimeString());

        $log = ReservationStatusLog::query()->sole();
        $this->assertNull($log->admin_user_id);
        $this->assertSame(ReservationStatus::AwaitingPayment, $log->from_status);
        $this->assertSame(ReservationStatus::Expired, $log->to_status);
        $this->assertSame(ExpireStaleReservations::NOTE, $log->note);

        $event = OutboxEvent::query()->sole();
        $this->assertSame('reservation.expired', $event->type);
        $this->assertSame($reservation->id, $event->payload['reservation_id']);
    }

    public function test_reservation_whose_deadline_is_now_or_later_is_kept(): void
    {
        $atDeadline = $this->awaitingPayment('2026-11-01 08:00:00');
        $later = $this->awaitingPayment('2026-11-02 08:00:00');

        $this->assertSame(0, $this->expire->handle());

        $this->assertSame(ReservationStatus::AwaitingPayment, $atDeadline->fresh()->status);
        $this->assertSame(ReservationStatus::AwaitingPayment, $later->fresh()->status);
        $this->assertDatabaseCount('reservation_status_logs', 0);
    }

    public function test_reservation_with_a_payment_is_left_for_an_admin(): void
    {
        $deposit = $this->awaitingPayment('2026-10-31 08:00:00', ReservationPaymentStatus::Deposit);
        $paid = $this->awaitingPayment('2026-10-31 08:00:00', ReservationPaymentStatus::Paid);

        $this->assertSame(0, $this->expire->handle());

        $this->assertSame(ReservationStatus::AwaitingPayment, $deposit->fresh()->status);
        $this->assertSame(ReservationStatus::AwaitingPayment, $paid->fresh()->status);
    }

    public function test_only_reservations_awaiting_payment_expire(): void
    {
        $others = collect(ReservationStatus::cases())
            ->reject(fn (ReservationStatus $status) => $status === ReservationStatus::AwaitingPayment)
            ->map(fn (ReservationStatus $status) => Reservation::factory()->create([
                'status' => $status,
                'expires_at' => '2026-10-31 08:00:00',
            ]));

        $this->assertSame(0, $this->expire->handle());

        foreach ($others as $reservation) {
            $this->assertSame($reservation->status, $reservation->fresh()->status);
        }
    }

    public function test_every_due_reservation_expires_in_one_run(): void
    {
        $due = [
            $this->awaitingPayment('2026-10-30 08:00:00'),
            $this->awaitingPayment('2026-11-01 07:00:00'),
        ];
        $kept = $this->awaitingPayment('2026-11-01 09:00:00');

        $this->assertSame(2, $this->expire->handle());

        foreach ($due as $reservation) {
            $this->assertSame(ReservationStatus::Expired, $reservation->fresh()->status);
        }
        $this->assertSame(ReservationStatus::AwaitingPayment, $kept->fresh()->status);

        // A second run finds nothing left to do.
        $this->assertSame(0, $this->expire->handle());
        $this->assertDatabaseCount('reservation_status_logs', 2);
    }

    public function test_expired_reservation_releases_its_dates_but_keeps_its_unit(): void
    {
        $roomType = RoomType::factory()->published()->create();
        $unit = Unit::factory()->for($roomType)->create();
        $reservation = $this->awaitingPayment('2026-11-01 07:00:00');
        ReservationRoom::factory()->for($reservation)->create([
            'room_type_id' => $roomType->id,
            'unit_id' => $unit->id,
            'rate_plan_id' => RatePlan::factory()->for($roomType),
        ]);
        $availability = $this->app->make(AvailabilityProvider::class);

        $this->assertFalse($availability->isAvailable($roomType, $reservation->check_in, $reservation->check_out));

        $this->expire->handle();

        $this->assertTrue($availability->isAvailable($roomType, $reservation->check_in, $reservation->check_out));
        $this->assertSame($unit->id, ReservationRoom::query()->sole()->unit_id);
    }

    private function awaitingPayment(
        string $expiresAt,
        ReservationPaymentStatus $paymentStatus = ReservationPaymentStatus::Unpaid,
    ): Reservation {
        return Reservation::factory()->awaitingPayment()->create([
            'expires_at' => $expiresAt,
            'payment_status' => $paymentStatus,
        ]);
    }
}

<?php

namespace Tests\Feature\Actions;

use App\Actions\Reservation\ApproveReservation;
use App\Enums\OutboxEventStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\Reservation\DatesNotAvailableException;
use App\Exceptions\Reservation\InvalidStatusTransitionException;
use App\Models\AvailabilityBlock;
use App\Models\OutboxEvent;
use App\Models\PropertyProfile;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\ReservationStatusLog;
use App\Models\RoomType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class ApproveReservationTest extends TestCase
{
    use RefreshDatabase;

    private ApproveReservation $approve;

    private User $admin;

    private RoomType $roomType;

    private RatePlan $ratePlan;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        // 16:00 on 2026-11-01 in the property timezone (Asia/Makassar, UTC+8).
        Carbon::setTestNow('2026-11-01 08:00:00');

        PropertyProfile::factory()->create(['timezone' => 'Asia/Makassar', 'payment_window_hours' => 12]);

        $this->approve = $this->app->make(ApproveReservation::class);
        $this->admin = User::factory()->create();
        $this->roomType = RoomType::factory()->published()->create();
        $this->ratePlan = RatePlan::factory()->for($this->roomType)->create();
        $this->unit = Unit::factory()->for($this->roomType)->create(['code' => 'A']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, array{ReservationStatus}>
     */
    public static function notPending(): array
    {
        return collect(ReservationStatus::cases())
            ->reject(fn (ReservationStatus $status) => $status === ReservationStatus::Pending)
            ->mapWithKeys(fn (ReservationStatus $status) => [$status->value => [$status]])
            ->all();
    }

    public function test_approval_assigns_a_unit_and_waits_for_payment(): void
    {
        $reservation = $this->pending();

        $result = $this->approve->handle($reservation, $this->admin, 'Checked Booking.com, dates are free.');

        $this->assertSame($reservation, $result);
        $this->assertSame(ReservationStatus::AwaitingPayment, $reservation->status);
        $this->assertSame($this->unit->id, $reservation->reservationRooms->sole()->unit_id);

        $fresh = $reservation->fresh();
        $this->assertSame(ReservationStatus::AwaitingPayment, $fresh->status);
        // payment_window_hours = 12 from now (UTC).
        $this->assertSame('2026-11-01 20:00:00', $fresh->expires_at->toDateTimeString());
        $this->assertNull($fresh->confirmed_at);
        $this->assertSame($this->unit->id, ReservationRoom::query()->sole()->unit_id);

        $log = ReservationStatusLog::query()->sole();
        $this->assertSame($this->admin->id, $log->admin_user_id);
        $this->assertSame(ReservationStatus::Pending, $log->from_status);
        $this->assertSame(ReservationStatus::AwaitingPayment, $log->to_status);
        $this->assertSame('Checked Booking.com, dates are free.', $log->note);

        $event = OutboxEvent::query()->sole();
        $this->assertSame('reservation.awaiting_payment', $event->type);
        $this->assertSame(OutboxEventStatus::Pending, $event->status);
        $this->assertSame($reservation->id, $event->payload['reservation_id']);
    }

    public function test_the_first_free_unit_is_assigned(): void
    {
        $unitB = Unit::factory()->for($this->roomType)->create(['code' => 'B']);
        Unit::factory()->for($this->roomType)->inactive()->create(['code' => '0']);
        AvailabilityBlock::factory()->for($this->unit)->create(['start_date' => '2026-12-26', 'end_date' => '2026-12-28']);

        $reservation = $this->approve->handle($this->pending(), $this->admin);

        $this->assertSame($unitB->id, $reservation->reservationRooms->sole()->unit_id);
    }

    public function test_dates_held_by_a_confirmed_reservation_cannot_be_approved(): void
    {
        $this->hold(Reservation::factory()->confirmed()->create(['check_in' => '2026-12-26', 'check_out' => '2026-12-29']));

        $this->assertNotApproved($this->pending(), DatesNotAvailableException::class, 'The selected dates are not available.');
    }

    public function test_dates_held_by_an_awaiting_payment_reservation_cannot_be_approved(): void
    {
        $this->hold(Reservation::factory()->awaitingPayment()->create(['check_in' => '2026-12-20', 'check_out' => '2026-12-25']));

        $this->assertNotApproved($this->pending(), DatesNotAvailableException::class, 'The selected dates are not available.');
    }

    public function test_blocked_dates_cannot_be_approved(): void
    {
        AvailabilityBlock::factory()->for($this->unit)->create(['start_date' => '2026-12-25', 'end_date' => '2026-12-26']);

        $this->assertNotApproved($this->pending(), DatesNotAvailableException::class, 'The selected dates are not available.');
    }

    public function test_an_inactive_unit_is_never_assigned(): void
    {
        $this->unit->update(['status' => 'inactive']);

        $this->assertNotApproved($this->pending(), DatesNotAvailableException::class);
    }

    public function test_another_pending_request_for_the_same_dates_does_not_prevent_approval(): void
    {
        $this->pending();

        $reservation = $this->approve->handle($this->pending(), $this->admin);

        $this->assertSame(ReservationStatus::AwaitingPayment, $reservation->fresh()->status);
    }

    public function test_stay_starting_on_another_guest_check_out_day_can_be_approved(): void
    {
        $this->hold(Reservation::factory()->confirmed()->create(['check_in' => '2026-12-20', 'check_out' => '2026-12-24']));
        $this->hold(Reservation::factory()->confirmed()->create(['check_in' => '2026-12-27', 'check_out' => '2026-12-30']));

        $reservation = $this->approve->handle($this->pending(), $this->admin);

        $this->assertSame(ReservationStatus::AwaitingPayment, $reservation->fresh()->status);
    }

    public function test_once_one_request_is_approved_an_overlapping_one_cannot_be(): void
    {
        $first = $this->pending();
        $second = $this->pending('2026-12-26', '2026-12-28');

        $this->approve->handle($first, $this->admin);

        $this->assertNotApproved($second, DatesNotAvailableException::class);
        $this->assertSame(ReservationStatus::AwaitingPayment, $first->fresh()->status);
    }

    public function test_each_room_of_a_reservation_gets_its_own_unit(): void
    {
        $unitB = Unit::factory()->for($this->roomType)->create(['code' => 'B']);
        $reservation = $this->pending();
        $this->room($reservation);

        $this->approve->handle($reservation, $this->admin);

        $this->assertEqualsCanonicalizing(
            [$this->unit->id, $unitB->id],
            ReservationRoom::query()->whereBelongsTo($reservation)->pluck('unit_id')->all(),
        );
    }

    public function test_reservation_with_more_rooms_than_free_units_cannot_be_approved(): void
    {
        $reservation = $this->pending();
        $this->room($reservation);

        $this->assertNotApproved($reservation, DatesNotAvailableException::class);
    }

    #[DataProvider('notPending')]
    public function test_only_pending_reservations_can_be_approved(ReservationStatus $status): void
    {
        $reservation = $this->pending();
        $reservation->update(['status' => $status]);

        $exception = $this->assertNotApproved(
            $reservation,
            InvalidStatusTransitionException::class,
            "Only pending reservations can be approved; this one is {$status->value}.",
            $status,
        );

        $this->assertSame($status, $exception->from);
        $this->assertSame(ReservationStatus::AwaitingPayment, $exception->to);
    }

    public function test_status_is_read_from_the_database_not_from_a_stale_model(): void
    {
        $reservation = $this->pending();
        Reservation::query()->whereKey($reservation->id)->update(['status' => ReservationStatus::Rejected]);

        $this->assertNotApproved($reservation, InvalidStatusTransitionException::class, null, ReservationStatus::Rejected);
    }

    public function test_check_in_before_today_in_the_property_timezone_cannot_be_approved(): void
    {
        // Still 2026-11-01 in UTC, already 2026-11-02 04:00 in Asia/Makassar.
        Carbon::setTestNow('2026-11-01 20:00:00');

        $this->assertNotApproved($this->pending('2026-11-01', '2026-11-03'), DatesNotAvailableException::class, 'Check-in cannot be in the past.');

        $reservation = $this->approve->handle($this->pending('2026-11-02', '2026-11-03'), $this->admin);

        $this->assertSame(ReservationStatus::AwaitingPayment, $reservation->fresh()->status);
    }

    public function test_failure_part_way_rolls_back_the_unit_assignment(): void
    {
        $reservation = $this->pending();
        OutboxEvent::creating(fn () => throw new RuntimeException('Outbox unavailable.'));

        $this->assertNotApproved($reservation, RuntimeException::class, 'Outbox unavailable.');
    }

    private function pending(string $checkIn = '2026-12-24', string $checkOut = '2026-12-27'): Reservation
    {
        $reservation = Reservation::factory()->create(['check_in' => $checkIn, 'check_out' => $checkOut]);
        $this->room($reservation);

        return $reservation;
    }

    private function room(Reservation $reservation, ?Unit $unit = null): ReservationRoom
    {
        return ReservationRoom::factory()->for($reservation)->create([
            'room_type_id' => $this->roomType->id,
            'unit_id' => $unit?->id,
            'rate_plan_id' => $this->ratePlan->id,
        ]);
    }

    private function hold(Reservation $reservation): void
    {
        $this->room($reservation, $this->unit);
    }

    /**
     * Expects approving to fail with the given exception and to leave the reservation untouched.
     *
     * @template T of Throwable
     *
     * @param  class-string<T>  $exception
     * @return T
     */
    private function assertNotApproved(
        Reservation $reservation,
        string $exception,
        ?string $message = null,
        ReservationStatus $status = ReservationStatus::Pending,
    ): Throwable {
        try {
            $this->approve->handle($reservation, $this->admin);
        } catch (Throwable $e) {
            $this->assertInstanceOf($exception, $e);

            if ($message !== null) {
                $this->assertSame($message, $e->getMessage());
            }

            $fresh = $reservation->fresh();
            $this->assertSame($status, $fresh->status);
            $this->assertNull($fresh->expires_at);
            $this->assertSame([null], ReservationRoom::query()->whereBelongsTo($reservation)->pluck('unit_id')->unique()->values()->all());
            $this->assertSame(0, ReservationStatusLog::query()->whereBelongsTo($reservation)->count());
            $this->assertSame(0, OutboxEvent::query()->where('payload->reservation_id', $reservation->id)->count());

            return $e;
        }

        $this->fail("Expected {$exception}.");
    }
}

<?php

namespace Tests\Feature\Actions;

use App\Actions\Reservation\ReactivateReservation;
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

class ReactivateReservationTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_DEADLINE = '2026-10-31 08:00:00';

    private ReactivateReservation $reactivate;

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

        $this->reactivate = $this->app->make(ReactivateReservation::class);
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
    public static function notExpired(): array
    {
        return collect(ReservationStatus::cases())
            ->reject(fn (ReservationStatus $status) => $status === ReservationStatus::Expired)
            ->mapWithKeys(fn (ReservationStatus $status) => [$status->value => [$status]])
            ->all();
    }

    public function test_expired_reservation_gets_a_new_payment_window_and_its_unit_back(): void
    {
        $reservation = $this->expired();

        $result = $this->reactivate->handle($reservation, $this->admin, 'Guest asked for more time.');

        $this->assertSame($reservation, $result);
        $this->assertSame(ReservationStatus::AwaitingPayment, $reservation->status);
        $this->assertSame($this->unit->id, $reservation->reservationRooms->sole()->unit_id);

        $fresh = $reservation->fresh();
        $this->assertSame(ReservationStatus::AwaitingPayment, $fresh->status);
        // payment_window_hours = 12 from now (UTC).
        $this->assertSame('2026-11-01 20:00:00', $fresh->expires_at->toDateTimeString());

        $log = ReservationStatusLog::query()->sole();
        $this->assertSame($this->admin->id, $log->admin_user_id);
        $this->assertSame(ReservationStatus::Expired, $log->from_status);
        $this->assertSame(ReservationStatus::AwaitingPayment, $log->to_status);
        $this->assertSame('Guest asked for more time.', $log->note);

        $event = OutboxEvent::query()->sole();
        $this->assertSame('reservation.awaiting_payment', $event->type);
        $this->assertSame(OutboxEventStatus::Pending, $event->status);
        $this->assertSame('expired', $event->payload['from_status']);
    }

    public function test_room_moves_to_another_free_unit_when_its_own_was_taken(): void
    {
        $unitB = Unit::factory()->for($this->roomType)->create(['code' => 'B']);
        $this->hold(Reservation::factory()->confirmed()->create(['check_in' => '2026-12-26', 'check_out' => '2026-12-28']), $this->unit);

        $reservation = $this->reactivate->handle($this->expired(), $this->admin);

        $this->assertSame(ReservationStatus::AwaitingPayment, $reservation->fresh()->status);
        $this->assertSame($unitB->id, $reservation->reservationRooms->sole()->unit_id);
    }

    public function test_dates_taken_since_expiry_cannot_be_reactivated(): void
    {
        $this->hold(Reservation::factory()->awaitingPayment()->create(['check_in' => '2026-12-20', 'check_out' => '2026-12-25']), $this->unit);

        $this->assertNotReactivated($this->expired(), DatesNotAvailableException::class, 'The selected dates are not available.');
    }

    public function test_dates_blocked_since_expiry_cannot_be_reactivated(): void
    {
        AvailabilityBlock::factory()->for($this->unit)->create(['start_date' => '2026-12-26', 'end_date' => '2026-12-27']);

        $this->assertNotReactivated($this->expired(), DatesNotAvailableException::class, 'The selected dates are not available.');
    }

    public function test_pending_requests_and_back_to_back_stays_do_not_prevent_reactivation(): void
    {
        $this->hold(Reservation::factory()->create());
        $this->hold(Reservation::factory()->confirmed()->create(['check_in' => '2026-12-20', 'check_out' => '2026-12-24']), $this->unit);
        $this->hold(Reservation::factory()->confirmed()->create(['check_in' => '2026-12-27', 'check_out' => '2026-12-30']), $this->unit);

        $reservation = $this->reactivate->handle($this->expired(), $this->admin);

        $this->assertSame(ReservationStatus::AwaitingPayment, $reservation->fresh()->status);
    }

    public function test_check_in_before_today_in_the_property_timezone_cannot_be_reactivated(): void
    {
        // Still 2026-11-01 in UTC, already 2026-11-02 04:00 in Asia/Makassar.
        Carbon::setTestNow('2026-11-01 20:00:00');

        $this->assertNotReactivated($this->expired('2026-11-01', '2026-11-03'), DatesNotAvailableException::class, 'Check-in cannot be in the past.');

        $reservation = $this->reactivate->handle($this->expired('2026-11-02', '2026-11-03'), $this->admin);

        $this->assertSame(ReservationStatus::AwaitingPayment, $reservation->fresh()->status);
    }

    #[DataProvider('notExpired')]
    public function test_only_expired_reservations_can_be_reactivated(ReservationStatus $status): void
    {
        $reservation = $this->expired();
        $reservation->update(['status' => $status]);

        $exception = $this->assertNotReactivated(
            $reservation,
            InvalidStatusTransitionException::class,
            "Only expired reservations can be reactivated; this one is {$status->value}.",
            $status,
        );

        $this->assertSame($status, $exception->from);
        $this->assertSame(ReservationStatus::AwaitingPayment, $exception->to);
    }

    public function test_failure_part_way_rolls_back_the_unit_assignment(): void
    {
        Unit::factory()->for($this->roomType)->create(['code' => 'B']);
        AvailabilityBlock::factory()->for($this->unit)->create(['start_date' => '2026-12-25', 'end_date' => '2026-12-26']);
        $reservation = $this->expired();
        OutboxEvent::creating(fn () => throw new RuntimeException('Outbox unavailable.'));

        $this->assertNotReactivated($reservation, RuntimeException::class, 'Outbox unavailable.');
    }

    /**
     * An expired reservation whose room still names unit A.
     */
    private function expired(string $checkIn = '2026-12-24', string $checkOut = '2026-12-27'): Reservation
    {
        $reservation = Reservation::factory()->create([
            'status' => ReservationStatus::Expired,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'expires_at' => self::OLD_DEADLINE,
        ]);
        $this->hold($reservation, $this->unit);

        return $reservation;
    }

    private function hold(Reservation $reservation, ?Unit $unit = null): ReservationRoom
    {
        return ReservationRoom::factory()->for($reservation)->create([
            'room_type_id' => $this->roomType->id,
            'unit_id' => $unit?->id,
            'rate_plan_id' => $this->ratePlan->id,
        ]);
    }

    /**
     * Expects reactivating to fail with the given exception and to leave the reservation untouched.
     *
     * @template T of Throwable
     *
     * @param  class-string<T>  $exception
     * @return T
     */
    private function assertNotReactivated(
        Reservation $reservation,
        string $exception,
        ?string $message = null,
        ReservationStatus $status = ReservationStatus::Expired,
    ): Throwable {
        try {
            $this->reactivate->handle($reservation, $this->admin);
        } catch (Throwable $e) {
            $this->assertInstanceOf($exception, $e);

            if ($message !== null) {
                $this->assertSame($message, $e->getMessage());
            }

            $fresh = $reservation->fresh();
            $this->assertSame($status, $fresh->status);
            $this->assertSame(self::OLD_DEADLINE, $fresh->expires_at->toDateTimeString());
            $this->assertSame($this->unit->id, ReservationRoom::query()->whereBelongsTo($reservation)->value('unit_id'));
            $this->assertSame(0, ReservationStatusLog::query()->whereBelongsTo($reservation)->count());
            $this->assertSame(0, OutboxEvent::query()->where('payload->reservation_id', $reservation->id)->count());

            return $e;
        }

        $this->fail("Expected {$exception}.");
    }
}

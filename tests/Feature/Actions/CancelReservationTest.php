<?php

namespace Tests\Feature\Actions;

use App\Actions\Reservation\CancelReservation;
use App\Contracts\AvailabilityProvider;
use App\Enums\PaymentStatus;
use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\Reservation\InvalidStatusTransitionException;
use App\Exceptions\Reservation\ReasonRequiredException;
use App\Models\OutboxEvent;
use App\Models\Payment;
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
use Tests\TestCase;
use Throwable;

class CancelReservationTest extends TestCase
{
    use RefreshDatabase;

    private CancelReservation $cancel;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-01 08:00:00');

        $this->cancel = $this->app->make(CancelReservation::class);
        $this->admin = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, array{ReservationStatus}>
     */
    public static function cancellable(): array
    {
        return [
            'pending' => [ReservationStatus::Pending],
            'awaiting_payment' => [ReservationStatus::AwaitingPayment],
            'confirmed' => [ReservationStatus::Confirmed],
        ];
    }

    /**
     * @return array<string, array{ReservationStatus}>
     */
    public static function notCancellable(): array
    {
        return [
            'completed' => [ReservationStatus::Completed],
            'rejected' => [ReservationStatus::Rejected],
            'cancelled' => [ReservationStatus::Cancelled],
            'expired' => [ReservationStatus::Expired],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blankReasons(): array
    {
        return [
            'empty' => [''],
            'whitespace' => [" \n\t "],
        ];
    }

    #[DataProvider('cancellable')]
    public function test_cancellation_writes_the_reason_to_the_log_and_outbox_event(ReservationStatus $status): void
    {
        $reservation = Reservation::factory()->create(['status' => $status]);

        $result = $this->cancel->handle($reservation, $this->admin, '  Guest changed their plans.  ');

        $this->assertSame($reservation, $result);
        $this->assertSame(ReservationStatus::Cancelled, $reservation->fresh()->status);

        $log = ReservationStatusLog::query()->sole();
        $this->assertSame($this->admin->id, $log->admin_user_id);
        $this->assertSame($status, $log->from_status);
        $this->assertSame(ReservationStatus::Cancelled, $log->to_status);
        $this->assertSame('Guest changed their plans.', $log->note);

        $event = OutboxEvent::query()->sole();
        $this->assertSame('reservation.cancelled', $event->type);
        $this->assertSame('Guest changed their plans.', $event->payload['note']);
    }

    public function test_cancelling_a_paid_reservation_refunds_nothing_and_says_so_in_the_log(): void
    {
        $reservation = Reservation::factory()->confirmed()->create();
        $payment = Payment::factory()->for($reservation)->create(['amount' => 4500000, 'status' => PaymentStatus::Paid]);

        $this->cancel->handle($reservation, $this->admin, 'Guest cannot travel.');

        $fresh = $reservation->fresh();
        $this->assertSame(ReservationStatus::Cancelled, $fresh->status);
        $this->assertSame(ReservationPaymentStatus::Paid, $fresh->payment_status);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(
            "Guest cannot travel.\n".CancelReservation::NO_REFUND_NOTE,
            ReservationStatusLog::query()->sole()->note,
        );
    }

    public function test_cancelling_after_a_deposit_also_notes_that_nothing_is_refunded(): void
    {
        $reservation = Reservation::factory()->awaitingPayment()->create(['payment_status' => ReservationPaymentStatus::Deposit]);

        $this->cancel->handle($reservation, $this->admin, 'Balance never arrived.');

        $this->assertSame(ReservationPaymentStatus::Deposit, $reservation->fresh()->payment_status);
        $this->assertStringEndsWith(CancelReservation::NO_REFUND_NOTE, ReservationStatusLog::query()->sole()->note);
    }

    public function test_a_system_action_can_cancel_without_an_admin(): void
    {
        $reservation = Reservation::factory()->create();

        $this->cancel->handle($reservation, null, 'Cancelled on the OTA.');

        $this->assertNull(ReservationStatusLog::query()->sole()->admin_user_id);
    }

    public function test_cancelled_reservation_releases_its_dates(): void
    {
        $roomType = RoomType::factory()->published()->create();
        $unit = Unit::factory()->for($roomType)->create();
        $reservation = Reservation::factory()->confirmed()->create();
        ReservationRoom::factory()->for($reservation)->create([
            'room_type_id' => $roomType->id,
            'unit_id' => $unit->id,
            'rate_plan_id' => RatePlan::factory()->for($roomType),
        ]);
        $availability = $this->app->make(AvailabilityProvider::class);

        $this->assertFalse($availability->isAvailable($roomType, $reservation->check_in, $reservation->check_out));

        $this->cancel->handle($reservation, $this->admin, 'Guest cancelled.');

        $this->assertTrue($availability->isAvailable($roomType, $reservation->check_in, $reservation->check_out));
        // The unit stays on the room for the record.
        $this->assertSame($unit->id, ReservationRoom::query()->sole()->unit_id);
    }

    #[DataProvider('blankReasons')]
    public function test_a_reason_is_required(string $reason): void
    {
        $reservation = Reservation::factory()->confirmed()->create();

        $this->assertNotCancelled($reservation, $reason, ReasonRequiredException::class, 'A reason is required to cancel a reservation.', ReservationStatus::Confirmed);
    }

    #[DataProvider('notCancellable')]
    public function test_only_pending_awaiting_payment_and_confirmed_reservations_can_be_cancelled(ReservationStatus $status): void
    {
        $reservation = Reservation::factory()->create(['status' => $status]);

        $this->assertNotCancelled(
            $reservation,
            'Too late.',
            InvalidStatusTransitionException::class,
            "A reservation cannot change from {$status->value} to cancelled.",
            $status,
        );
    }

    public function test_status_is_read_from_the_database_not_from_a_stale_model(): void
    {
        $reservation = Reservation::factory()->create();
        Reservation::query()->whereKey($reservation->id)->update(['status' => ReservationStatus::Rejected]);

        $this->assertNotCancelled($reservation, 'Too late.', InvalidStatusTransitionException::class, null, ReservationStatus::Rejected);
    }

    /**
     * @param  class-string<Throwable>  $exception
     */
    private function assertNotCancelled(
        Reservation $reservation,
        string $reason,
        string $exception,
        ?string $message,
        ReservationStatus $status,
    ): void {
        $thrown = null;

        try {
            $this->cancel->handle($reservation, $this->admin, $reason);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf($exception, $thrown, "Expected {$exception}.");

        if ($message !== null) {
            $this->assertSame($message, $thrown->getMessage());
        }

        $this->assertSame($status, $reservation->fresh()->status);
        $this->assertDatabaseCount('reservation_status_logs', 0);
        $this->assertDatabaseCount('outbox_events', 0);
    }
}

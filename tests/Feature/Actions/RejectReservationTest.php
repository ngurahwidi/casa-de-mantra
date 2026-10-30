<?php

namespace Tests\Feature\Actions;

use App\Actions\Reservation\RejectReservation;
use App\Enums\ReservationStatus;
use App\Exceptions\Reservation\InvalidStatusTransitionException;
use App\Exceptions\Reservation\ReasonRequiredException;
use App\Models\OutboxEvent;
use App\Models\Reservation;
use App\Models\ReservationStatusLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

class RejectReservationTest extends TestCase
{
    use RefreshDatabase;

    private RejectReservation $reject;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-01 08:00:00');

        $this->reject = $this->app->make(RejectReservation::class);
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
    public static function notPending(): array
    {
        return collect(ReservationStatus::cases())
            ->reject(fn (ReservationStatus $status) => $status === ReservationStatus::Pending)
            ->mapWithKeys(fn (ReservationStatus $status) => [$status->value => [$status]])
            ->all();
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

    public function test_rejection_stores_the_reason_and_writes_log_and_outbox_event(): void
    {
        $reservation = Reservation::factory()->create();

        $result = $this->reject->handle($reservation, $this->admin, '  The villa is closed for renovation.  ');

        $this->assertSame($reservation, $result);
        $this->assertSame(ReservationStatus::Rejected, $reservation->status);

        $fresh = $reservation->fresh();
        $this->assertSame(ReservationStatus::Rejected, $fresh->status);
        $this->assertSame('The villa is closed for renovation.', $fresh->reject_reason);
        $this->assertNull($fresh->expires_at);

        $log = ReservationStatusLog::query()->sole();
        $this->assertSame($this->admin->id, $log->admin_user_id);
        $this->assertSame(ReservationStatus::Pending, $log->from_status);
        $this->assertSame(ReservationStatus::Rejected, $log->to_status);
        $this->assertSame('The villa is closed for renovation.', $log->note);

        $event = OutboxEvent::query()->sole();
        $this->assertSame('reservation.rejected', $event->type);
        $this->assertSame('The villa is closed for renovation.', $event->payload['note']);
    }

    #[DataProvider('blankReasons')]
    public function test_a_reason_is_required(string $reason): void
    {
        $reservation = Reservation::factory()->create();

        $this->assertNotRejected($reservation, $reason, ReasonRequiredException::class, 'A reason is required to reject a reservation.');
    }

    #[DataProvider('notPending')]
    public function test_only_pending_reservations_can_be_rejected(ReservationStatus $status): void
    {
        $reservation = Reservation::factory()->create(['status' => $status]);

        $this->assertNotRejected(
            $reservation,
            'Too late.',
            InvalidStatusTransitionException::class,
            "A reservation cannot change from {$status->value} to rejected.",
            $status,
        );
    }

    public function test_status_is_read_from_the_database_not_from_a_stale_model(): void
    {
        $reservation = Reservation::factory()->create();
        Reservation::query()->whereKey($reservation->id)->update(['status' => ReservationStatus::AwaitingPayment]);

        $this->assertNotRejected($reservation, 'Too late.', InvalidStatusTransitionException::class, null, ReservationStatus::AwaitingPayment);
    }

    /**
     * @param  class-string<Throwable>  $exception
     */
    private function assertNotRejected(
        Reservation $reservation,
        string $reason,
        string $exception,
        ?string $message = null,
        ReservationStatus $status = ReservationStatus::Pending,
    ): void {
        $thrown = null;

        try {
            $this->reject->handle($reservation, $this->admin, $reason);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf($exception, $thrown, "Expected {$exception}.");

        if ($message !== null) {
            $this->assertSame($message, $thrown->getMessage());
        }

        $fresh = $reservation->fresh();
        $this->assertSame($status, $fresh->status);
        $this->assertNull($fresh->reject_reason);
        $this->assertDatabaseCount('reservation_status_logs', 0);
        $this->assertDatabaseCount('outbox_events', 0);
    }
}

<?php

namespace Tests\Feature\Actions;

use App\Actions\Reservation\CompleteReservation;
use App\Enums\ReservationStatus;
use App\Exceptions\Reservation\InvalidStatusTransitionException;
use App\Exceptions\Reservation\StayNotFinishedException;
use App\Models\OutboxEvent;
use App\Models\PropertyProfile;
use App\Models\Reservation;
use App\Models\ReservationStatusLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

class CompleteReservationTest extends TestCase
{
    use RefreshDatabase;

    private CompleteReservation $complete;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // 16:00 on 2026-11-01 in the property timezone (Asia/Makassar, UTC+8).
        Carbon::setTestNow('2026-11-01 08:00:00');

        PropertyProfile::factory()->create(['timezone' => 'Asia/Makassar']);

        $this->complete = $this->app->make(CompleteReservation::class);
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
    public static function notConfirmed(): array
    {
        return collect(ReservationStatus::cases())
            ->reject(fn (ReservationStatus $status) => $status === ReservationStatus::Confirmed)
            ->mapWithKeys(fn (ReservationStatus $status) => [$status->value => [$status]])
            ->all();
    }

    public function test_confirmed_stay_that_has_checked_out_is_completed(): void
    {
        $reservation = $this->confirmed('2026-10-31');

        $result = $this->complete->handle($reservation, $this->admin, 'Guest left, villa inspected.');

        $this->assertSame($reservation, $result);
        $this->assertSame(ReservationStatus::Completed, $reservation->fresh()->status);

        $log = ReservationStatusLog::query()->sole();
        $this->assertSame($this->admin->id, $log->admin_user_id);
        $this->assertSame(ReservationStatus::Confirmed, $log->from_status);
        $this->assertSame(ReservationStatus::Completed, $log->to_status);
        $this->assertSame('Guest left, villa inspected.', $log->note);

        $this->assertSame('reservation.completed', OutboxEvent::query()->sole()->type);
    }

    public function test_stay_checking_out_today_cannot_be_completed_yet(): void
    {
        $this->assertNotCompleted(
            $this->confirmed('2026-11-01'),
            StayNotFinishedException::class,
            'A reservation can only be completed after its check-out date (2026-11-01).',
        );
    }

    public function test_today_is_taken_in_the_property_timezone(): void
    {
        // Still 2026-11-01 in UTC, already 2026-11-02 04:00 in Asia/Makassar.
        Carbon::setTestNow('2026-11-01 20:00:00');

        $this->assertNotCompleted($this->confirmed('2026-11-02'), StayNotFinishedException::class);

        $reservation = $this->complete->handle($this->confirmed('2026-11-01'), $this->admin);

        $this->assertSame(ReservationStatus::Completed, $reservation->fresh()->status);
    }

    #[DataProvider('notConfirmed')]
    public function test_only_confirmed_reservations_can_be_completed(ReservationStatus $status): void
    {
        $reservation = $this->confirmed('2026-10-31');
        $reservation->update(['status' => $status]);

        $exception = $this->assertNotCompleted(
            $reservation,
            InvalidStatusTransitionException::class,
            "Only confirmed reservations can be completed; this one is {$status->value}.",
            $status,
        );

        $this->assertSame($status, $exception->from);
        $this->assertSame(ReservationStatus::Completed, $exception->to);
    }

    public function test_status_is_read_from_the_database_not_from_a_stale_model(): void
    {
        $reservation = $this->confirmed('2026-10-31');
        Reservation::query()->whereKey($reservation->id)->update(['status' => ReservationStatus::Cancelled]);

        $this->assertNotCompleted($reservation, InvalidStatusTransitionException::class, null, ReservationStatus::Cancelled);
    }

    public function test_every_checked_out_stay_is_completed_in_one_run(): void
    {
        $due = [$this->confirmed('2026-10-20'), $this->confirmed('2026-10-31')];
        $today = $this->confirmed('2026-11-01');
        $upcoming = $this->confirmed('2026-12-27');
        $cancelled = Reservation::factory()->create(['status' => ReservationStatus::Cancelled, 'check_in' => '2026-10-25', 'check_out' => '2026-10-28']);

        $this->assertSame(2, $this->complete->completeCheckedOut());

        foreach ($due as $reservation) {
            $this->assertSame(ReservationStatus::Completed, $reservation->fresh()->status);
        }
        $this->assertSame(ReservationStatus::Confirmed, $today->fresh()->status);
        $this->assertSame(ReservationStatus::Confirmed, $upcoming->fresh()->status);
        $this->assertSame(ReservationStatus::Cancelled, $cancelled->fresh()->status);

        $logs = ReservationStatusLog::query()->get();
        $this->assertCount(2, $logs);
        $this->assertSame([null], $logs->pluck('admin_user_id')->unique()->values()->all());
        $this->assertSame([CompleteReservation::NOTE], $logs->pluck('note')->unique()->values()->all());

        // A second run finds nothing left to do.
        $this->assertSame(0, $this->complete->completeCheckedOut());
    }

    private function confirmed(string $checkOut): Reservation
    {
        return Reservation::factory()->confirmed()->create([
            'check_in' => Carbon::parse($checkOut)->subDays(3)->toDateString(),
            'check_out' => $checkOut,
        ]);
    }

    /**
     * Expects completing to fail with the given exception and to leave the reservation untouched.
     *
     * @template T of Throwable
     *
     * @param  class-string<T>  $exception
     * @return T
     */
    private function assertNotCompleted(
        Reservation $reservation,
        string $exception,
        ?string $message = null,
        ReservationStatus $status = ReservationStatus::Confirmed,
    ): Throwable {
        try {
            $this->complete->handle($reservation, $this->admin);
        } catch (Throwable $e) {
            $this->assertInstanceOf($exception, $e);

            if ($message !== null) {
                $this->assertSame($message, $e->getMessage());
            }

            $this->assertSame($status, $reservation->fresh()->status);
            $this->assertSame(0, ReservationStatusLog::query()->whereBelongsTo($reservation)->count());
            $this->assertSame(0, OutboxEvent::query()->where('payload->reservation_id', $reservation->id)->count());

            return $e;
        }

        $this->fail("Expected {$exception}.");
    }
}

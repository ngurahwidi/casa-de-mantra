<?php

namespace Tests\Feature\Actions;

use App\Actions\Reservation\MarkOtaBlocked;
use App\Enums\ReservationStatus;
use App\Exceptions\Reservation\OtaBlockNotAllowedException;
use App\Models\OutboxEvent;
use App\Models\Reservation;
use App\Models\ReservationStatusLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MarkOtaBlockedTest extends TestCase
{
    use RefreshDatabase;

    private MarkOtaBlocked $mark;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-01 08:00:00');

        $this->mark = $this->app->make(MarkOtaBlocked::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, array{ReservationStatus}>
     */
    public static function holdingDates(): array
    {
        return [
            'awaiting_payment' => [ReservationStatus::AwaitingPayment],
            'confirmed' => [ReservationStatus::Confirmed],
        ];
    }

    /**
     * @return array<string, array{ReservationStatus}>
     */
    public static function notHoldingDates(): array
    {
        return collect(ReservationStatus::cases())
            ->reject(fn (ReservationStatus $status) => in_array($status, ReservationStatus::blockingAvailability(), true))
            ->mapWithKeys(fn (ReservationStatus $status) => [$status->value => [$status]])
            ->all();
    }

    /**
     * @return array<string, array{ReservationStatus}>
     */
    public static function allStatuses(): array
    {
        return collect(ReservationStatus::cases())
            ->mapWithKeys(fn (ReservationStatus $status) => [$status->value => [$status]])
            ->all();
    }

    #[DataProvider('holdingDates')]
    public function test_reservation_holding_its_dates_is_marked_as_blocked(ReservationStatus $status): void
    {
        $reservation = Reservation::factory()->create(['status' => $status]);

        $result = $this->mark->handle($reservation);

        $this->assertSame($reservation, $result);
        $this->assertSame('2026-11-01 08:00:00', $reservation->ota_blocked_at->toDateTimeString());
        $this->assertFalse($reservation->isDirty());
        $this->assertSame('2026-11-01 08:00:00', $reservation->fresh()->ota_blocked_at->toDateTimeString());
        $this->assertSame($status, $reservation->fresh()->status);

        // A checklist tick, not a status change.
        $this->assertSame(0, ReservationStatusLog::query()->count());
        $this->assertSame(0, OutboxEvent::query()->count());
    }

    public function test_marking_again_keeps_the_first_time(): void
    {
        $reservation = Reservation::factory()->confirmed()->create();
        $this->mark->handle($reservation);

        Carbon::setTestNow('2026-11-02 09:30:00');
        $this->mark->handle($reservation->fresh());

        $this->assertSame('2026-11-01 08:00:00', $reservation->fresh()->ota_blocked_at->toDateTimeString());
    }

    #[DataProvider('notHoldingDates')]
    public function test_reservation_not_holding_its_dates_cannot_be_marked(ReservationStatus $status): void
    {
        $reservation = Reservation::factory()->create(['status' => $status]);

        try {
            $this->mark->handle($reservation);
            $this->fail('Expected OtaBlockNotAllowedException.');
        } catch (OtaBlockNotAllowedException $e) {
            $this->assertSame(
                "Only reservations awaiting payment or confirmed can be marked as blocked on the OTAs; this one is {$status->value}.",
                $e->getMessage(),
            );
        }

        $this->assertNull($reservation->fresh()->ota_blocked_at);
    }

    public function test_status_is_checked_against_the_database_not_a_stale_model(): void
    {
        $reservation = Reservation::factory()->confirmed()->create();
        Reservation::query()->whereKey($reservation->id)->update(['status' => ReservationStatus::Cancelled]);

        $this->expectException(OtaBlockNotAllowedException::class);

        $this->mark->handle($reservation);
    }

    #[DataProvider('allStatuses')]
    public function test_the_mark_can_be_cleared_in_any_status(ReservationStatus $status): void
    {
        $reservation = Reservation::factory()->create(['status' => $status, 'ota_blocked_at' => now()->subDay()]);

        $this->mark->handle($reservation, blocked: false);

        $this->assertNull($reservation->ota_blocked_at);
        $this->assertNull($reservation->fresh()->ota_blocked_at);
        $this->assertSame($status, $reservation->fresh()->status);
    }
}

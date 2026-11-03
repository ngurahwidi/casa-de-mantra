<?php

namespace Tests\Feature\Services;

use App\Enums\ReservationStatus;
use App\Models\AvailabilityBlock;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Unit;
use App\Services\Availability\UnitCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UnitCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_nights_list_the_reservations_and_blocks_that_cover_them(): void
    {
        $unit = Unit::factory()->create();
        $reservation = $this->reserve($unit, '2026-12-24', '2026-12-26', ReservationStatus::Confirmed);
        $block = AvailabilityBlock::factory()->for($unit)->create(['start_date' => '2026-12-25', 'end_date' => '2026-12-27']);

        $nights = $this->calendar($unit, '2026-12-23', '2026-12-28');

        $this->assertSame(['2026-12-23', '2026-12-24', '2026-12-25', '2026-12-26', '2026-12-27'], array_keys($nights));
        $this->assertSame([], $nights['2026-12-23']);
        $this->assertSame([$reservation->id], $this->ids($nights['2026-12-24']));
        $this->assertSame([$reservation->id, $block->id], $this->ids($nights['2026-12-25']));
        // Check-out days are not nights of the stay or block.
        $this->assertSame([$block->id], $this->ids($nights['2026-12-26']));
        $this->assertSame([], $nights['2026-12-27']);
    }

    /**
     * @return array<string, array{ReservationStatus}>
     */
    public static function statusesNotHoldingDates(): array
    {
        return collect(ReservationStatus::cases())
            ->reject(fn (ReservationStatus $status) => in_array($status, ReservationStatus::blockingAvailability(), true))
            ->mapWithKeys(fn (ReservationStatus $status) => [$status->value => [$status]])
            ->all();
    }

    #[DataProvider('statusesNotHoldingDates')]
    public function test_reservations_that_do_not_hold_dates_are_left_out(ReservationStatus $status): void
    {
        $unit = Unit::factory()->create();
        $this->reserve($unit, '2026-12-24', '2026-12-25', $status);

        $nights = $this->calendar($unit, '2026-12-24', '2026-12-25');

        $this->assertSame([], $nights['2026-12-24']);
    }

    public function test_reservations_and_blocks_of_other_units_are_left_out(): void
    {
        $unit = Unit::factory()->create();
        $other = Unit::factory()->for($unit->roomType)->create();
        $this->reserve($other, '2026-12-24', '2026-12-25', ReservationStatus::AwaitingPayment);
        AvailabilityBlock::factory()->for($other)->create(['start_date' => '2026-12-24', 'end_date' => '2026-12-25']);

        $nights = $this->calendar($unit, '2026-12-24', '2026-12-25');

        $this->assertSame([], $nights['2026-12-24']);
    }

    public function test_end_must_be_after_start(): void
    {
        $unit = Unit::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        $this->calendar($unit, '2026-12-24', '2026-12-24');
    }

    /**
     * @return array<string, list<Reservation|AvailabilityBlock>>
     */
    private function calendar(Unit $unit, string $start, string $end): array
    {
        return $this->app->make(UnitCalendar::class)->forPeriod($unit, $start, $end);
    }

    /**
     * @param  list<Reservation|AvailabilityBlock>  $occupants
     * @return list<string>
     */
    private function ids(array $occupants): array
    {
        return array_map(fn (Reservation|AvailabilityBlock $occupant): string => $occupant->getKey(), $occupants);
    }

    private function reserve(Unit $unit, string $checkIn, string $checkOut, ReservationStatus $status): Reservation
    {
        $reservation = Reservation::factory()->create([
            'status' => $status,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
        ]);

        ReservationRoom::factory()->for($reservation)->create([
            'room_type_id' => $unit->room_type_id,
            'unit_id' => $unit->id,
        ]);

        return $reservation;
    }
}

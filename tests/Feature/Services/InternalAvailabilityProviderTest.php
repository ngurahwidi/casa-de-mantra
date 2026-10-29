<?php

namespace Tests\Feature\Services;

use App\Contracts\AvailabilityProvider;
use App\Enums\AvailabilityBlockSource;
use App\Enums\ReservationStatus;
use App\Enums\UnitStatus;
use App\Models\AvailabilityBlock;
use App\Models\IcalFeed;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\RoomType;
use App\Models\Unit;
use App\Services\Availability\InternalAvailabilityProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InternalAvailabilityProviderTest extends TestCase
{
    use RefreshDatabase;

    private AvailabilityProvider $provider;

    private RoomType $roomType;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-01 00:00:00');

        $this->provider = $this->app->make(AvailabilityProvider::class);
        $this->roomType = RoomType::factory()->create();
        $this->unit = Unit::factory()->for($this->roomType)->create(['code' => 'A']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_container_resolves_the_internal_provider(): void
    {
        $this->assertInstanceOf(InternalAvailabilityProvider::class, $this->provider);
    }

    public function test_free_unit_is_available(): void
    {
        $this->assertTrue($this->available('2026-12-24', '2026-12-27'));
        $this->assertEquals([$this->unit->id], $this->availableUnitIds('2026-12-24', '2026-12-27'));
        $this->assertSame([], $this->blockedDates('2026-12-01', '2027-01-01'));
    }

    /**
     * Existing stay is 2026-12-24 → 2026-12-27.
     *
     * @return array<string, array{string, string}>
     */
    public static function overlappingStays(): array
    {
        return [
            'same dates' => ['2026-12-24', '2026-12-27'],
            'inside' => ['2026-12-25', '2026-12-26'],
            'covering' => ['2026-12-20', '2026-12-30'],
            'overlaps the start' => ['2026-12-22', '2026-12-25'],
            'overlaps the end' => ['2026-12-26', '2026-12-29'],
            'last night only' => ['2026-12-26', '2026-12-27'],
        ];
    }

    #[DataProvider('overlappingStays')]
    public function test_overlapping_stay_is_not_available(string $checkIn, string $checkOut): void
    {
        $this->reserve($this->unit, '2026-12-24', '2026-12-27');

        $this->assertFalse($this->available($checkIn, $checkOut));
        $this->assertSame([], $this->availableUnitIds($checkIn, $checkOut));
    }

    public function test_stays_touching_on_the_change_over_day_are_available(): void
    {
        $this->reserve($this->unit, '2026-12-24', '2026-12-27');

        $this->assertTrue($this->available('2026-12-27', '2026-12-30'), 'check-in on another guest\'s check-out day');
        $this->assertTrue($this->available('2026-12-20', '2026-12-24'), 'check-out on another guest\'s check-in day');
    }

    /**
     * @return array<string, array{ReservationStatus}>
     */
    public static function nonBlockingStatuses(): array
    {
        return [
            'pending' => [ReservationStatus::Pending],
            'rejected' => [ReservationStatus::Rejected],
            'cancelled' => [ReservationStatus::Cancelled],
            'expired' => [ReservationStatus::Expired],
        ];
    }

    #[DataProvider('nonBlockingStatuses')]
    public function test_reservations_that_do_not_hold_dates_do_not_block(ReservationStatus $status): void
    {
        $this->reserve($this->unit, '2026-12-24', '2026-12-27', $status);

        $this->assertTrue($this->available('2026-12-24', '2026-12-27'));
        $this->assertSame([], $this->blockedDates('2026-12-24', '2026-12-27'));
    }

    /**
     * @return array<string, array{ReservationStatus}>
     */
    public static function blockingStatuses(): array
    {
        return [
            'awaiting payment' => [ReservationStatus::AwaitingPayment],
            'confirmed' => [ReservationStatus::Confirmed],
        ];
    }

    #[DataProvider('blockingStatuses')]
    public function test_awaiting_payment_and_confirmed_reservations_block(ReservationStatus $status): void
    {
        $this->reserve($this->unit, '2026-12-24', '2026-12-27', $status);

        $this->assertFalse($this->available('2026-12-24', '2026-12-27'));
        $this->assertSame(['2026-12-24', '2026-12-25', '2026-12-26'], $this->blockedDates('2026-12-20', '2026-12-30'));
    }

    /**
     * @return array<string, array{AvailabilityBlockSource}>
     */
    public static function blockSources(): array
    {
        return [
            'manual' => [AvailabilityBlockSource::Manual],
            'ical' => [AvailabilityBlockSource::Ical],
            'ota manual' => [AvailabilityBlockSource::OtaManual],
        ];
    }

    #[DataProvider('blockSources')]
    public function test_availability_blocks_block(AvailabilityBlockSource $source): void
    {
        $factory = AvailabilityBlock::factory()->for($this->unit);

        if ($source === AvailabilityBlockSource::Ical) {
            $factory = $factory->fromIcal(IcalFeed::factory()->for($this->roomType)->create());
        }

        $factory->create(['source' => $source, 'start_date' => '2026-12-24', 'end_date' => '2026-12-27']);

        $this->assertFalse($this->available('2026-12-25', '2026-12-28'));
        $this->assertTrue($this->available('2026-12-27', '2026-12-28'));
        $this->assertSame(['2026-12-24', '2026-12-25', '2026-12-26'], $this->blockedDates('2026-12-20', '2026-12-30'));
    }

    public function test_inactive_units_are_never_available(): void
    {
        $this->unit->update(['status' => UnitStatus::Inactive]);

        $this->assertFalse($this->available('2026-12-24', '2026-12-27'));
        $this->assertSame(['2026-12-24', '2026-12-25', '2026-12-26'], $this->blockedDates('2026-12-24', '2026-12-27'));
    }

    public function test_only_free_active_units_are_returned(): void
    {
        $b = Unit::factory()->for($this->roomType)->create(['code' => 'B']);
        $c = Unit::factory()->for($this->roomType)->create(['code' => 'C']);
        Unit::factory()->for($this->roomType)->inactive()->create(['code' => 'D']);

        $this->reserve($this->unit, '2026-12-24', '2026-12-27');
        AvailabilityBlock::factory()->for($b)->create(['start_date' => '2026-12-26', 'end_date' => '2026-12-28']);

        $this->assertEquals([$c->id], $this->availableUnitIds('2026-12-24', '2026-12-27'));
        $this->assertEquals([$this->unit->id, $c->id], $this->availableUnitIds('2026-12-27', '2026-12-29'));
        $this->assertSame([], $this->blockedDates('2026-12-20', '2026-12-30'), 'unit C is always free');
    }

    public function test_bookings_of_other_room_types_do_not_block(): void
    {
        $otherUnit = Unit::factory()->create();
        $this->reserve($otherUnit, '2026-12-24', '2026-12-27');
        AvailabilityBlock::factory()->for($otherUnit)->create();

        $this->assertTrue($this->available('2026-12-24', '2026-12-27'));
    }

    public function test_a_reservation_can_be_ignored_when_revalidating_its_own_dates(): void
    {
        $reservation = $this->reserve($this->unit, '2026-12-24', '2026-12-27');

        $this->assertFalse($this->available('2026-12-24', '2026-12-27'));
        $this->assertTrue($this->provider->isAvailable(
            $this->roomType,
            Carbon::parse('2026-12-24'),
            Carbon::parse('2026-12-27'),
            except: $reservation,
        ));
    }

    public function test_blocking_reservation_without_an_assigned_unit_consumes_capacity(): void
    {
        $b = Unit::factory()->for($this->roomType)->create(['code' => 'B']);

        $this->reserve(null, '2026-12-24', '2026-12-27');

        $this->assertEquals([$this->unit->id], $this->availableUnitIds('2026-12-24', '2026-12-27'));
        $this->assertSame([], $this->blockedDates('2026-12-20', '2026-12-30'));

        $this->reserve($b, '2026-12-25', '2026-12-26');

        $this->assertSame([], $this->availableUnitIds('2026-12-24', '2026-12-27'));
        $this->assertSame(['2026-12-25'], $this->blockedDates('2026-12-20', '2026-12-30'));
    }

    public function test_blocked_dates_are_limited_to_the_requested_window(): void
    {
        $this->reserve($this->unit, '2026-12-20', '2026-12-27');

        $this->assertSame(['2026-12-25', '2026-12-26'], $this->blockedDates('2026-12-25', '2026-12-31'));
    }

    public function test_only_the_date_part_is_used(): void
    {
        $this->reserve($this->unit, '2026-12-24', '2026-12-27');

        $this->assertTrue($this->provider->isAvailable(
            $this->roomType,
            Carbon::parse('2026-12-27 23:00:00'),
            Carbon::parse('2026-12-28 01:00:00'),
        ));
    }

    public function test_check_out_must_be_after_check_in(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->available('2026-12-24', '2026-12-24');
    }

    private function reserve(
        ?Unit $unit,
        string $checkIn,
        string $checkOut,
        ReservationStatus $status = ReservationStatus::AwaitingPayment,
    ): Reservation {
        $reservation = Reservation::factory()->create([
            'status' => $status,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
        ]);

        ReservationRoom::factory()->for($reservation)->create([
            'room_type_id' => $unit === null ? $this->roomType->id : $unit->room_type_id,
            'unit_id' => $unit?->id,
        ]);

        return $reservation;
    }

    private function available(string $checkIn, string $checkOut): bool
    {
        return $this->provider->isAvailable($this->roomType, Carbon::parse($checkIn), Carbon::parse($checkOut));
    }

    /**
     * @return list<string>
     */
    private function availableUnitIds(string $checkIn, string $checkOut): array
    {
        return $this->provider
            ->availableUnits($this->roomType, Carbon::parse($checkIn), Carbon::parse($checkOut))
            ->pluck('id')
            ->all();
    }

    /**
     * @return list<string>
     */
    private function blockedDates(string $from, string $to): array
    {
        return $this->provider->blockedDates($this->roomType, Carbon::parse($from), Carbon::parse($to));
    }
}

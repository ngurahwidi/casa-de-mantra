<?php

namespace Tests\Feature\Actions;

use App\Actions\Availability\CreateManualBlock;
use App\Enums\AvailabilityBlockSource;
use App\Enums\ReservationStatus;
use App\Exceptions\Availability\BlockNotAllowedException;
use App\Models\AvailabilityBlock;
use App\Models\PropertyProfile;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CreateManualBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 2026-11-01 07:00 in Asia/Makassar (UTC+8).
        Carbon::setTestNow('2026-10-31 23:00:00');

        PropertyProfile::factory()->create(['timezone' => 'Asia/Makassar']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_blocks_the_nights_with_the_day_after_the_last_night_as_end_date(): void
    {
        $unit = Unit::factory()->create();

        $block = $this->block($unit, '2026-12-24', '2026-12-26', '  Pool repair  ');

        $block->refresh();
        $this->assertTrue($block->unit->is($unit));
        $this->assertSame(AvailabilityBlockSource::Manual, $block->source);
        $this->assertSame('2026-12-24', $block->start_date->toDateString());
        $this->assertSame('2026-12-27', $block->end_date->toDateString());
        $this->assertSame('Pool repair', $block->reason);
        $this->assertNull($block->ical_feed_id);
    }

    public function test_single_night_without_a_reason_can_be_blocked(): void
    {
        $unit = Unit::factory()->create();

        $block = $this->block($unit, '2026-11-01', '2026-11-01', '   ');

        $block->refresh();
        $this->assertSame('2026-11-02', $block->end_date->toDateString());
        $this->assertNull($block->reason);
    }

    /**
     * @return array<string, array{ReservationStatus}>
     */
    public static function statusesHoldingDates(): array
    {
        return [
            'awaiting_payment' => [ReservationStatus::AwaitingPayment],
            'confirmed' => [ReservationStatus::Confirmed],
        ];
    }

    #[DataProvider('statusesHoldingDates')]
    public function test_rejects_nights_held_by_a_reservation_of_the_unit(ReservationStatus $status): void
    {
        $unit = Unit::factory()->create(['code' => 'V1']);
        $this->reserve($unit, '2026-12-24', '2026-12-27', $status);

        $this->expectException(BlockNotAllowedException::class);
        $this->expectExceptionMessage('Unit V1 is already booked or blocked on 2 of these nights, starting 24 Dec 2026.');

        try {
            $this->block($unit, '2026-12-20', '2026-12-25');
        } finally {
            $this->assertSame(0, AvailabilityBlock::count());
        }
    }

    public function test_rejects_nights_already_blocked_on_the_unit(): void
    {
        $unit = Unit::factory()->create(['code' => 'V1']);
        AvailabilityBlock::factory()->for($unit)->fromIcal()->create(['start_date' => '2026-12-24', 'end_date' => '2026-12-25']);

        $this->expectException(BlockNotAllowedException::class);
        $this->expectExceptionMessage('Unit V1 is already booked or blocked on the night of 24 Dec 2026.');

        $this->block($unit, '2026-12-24', '2026-12-24');
    }

    public function test_pending_requests_and_other_units_do_not_prevent_a_block(): void
    {
        $unit = Unit::factory()->create();
        $this->reserve($unit, '2026-12-24', '2026-12-27', ReservationStatus::Pending);
        AvailabilityBlock::factory()->for(Unit::factory()->for($unit->roomType))->create(['start_date' => '2026-12-24', 'end_date' => '2026-12-27']);

        $this->block($unit, '2026-12-24', '2026-12-26');

        $this->assertSame(1, $unit->availabilityBlocks()->count());
    }

    public function test_block_may_start_on_the_check_out_day_of_a_reservation(): void
    {
        $unit = Unit::factory()->create();
        $this->reserve($unit, '2026-12-24', '2026-12-27', ReservationStatus::Confirmed);

        $this->block($unit, '2026-12-27', '2026-12-28');

        $this->assertSame(1, $unit->availabilityBlocks()->count());
    }

    public function test_rejects_a_block_starting_before_today_in_the_property_timezone(): void
    {
        $unit = Unit::factory()->create();

        $this->expectException(BlockNotAllowedException::class);
        $this->expectExceptionMessage('A block cannot start before today.');

        // Still today in UTC, but already yesterday at the property.
        $this->block($unit, '2026-10-31', '2026-11-02');
    }

    public function test_rejects_a_last_night_before_the_first(): void
    {
        $unit = Unit::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        $this->block($unit, '2026-12-26', '2026-12-24');
    }

    private function block(Unit $unit, string $firstNight, string $lastNight, ?string $reason = null): AvailabilityBlock
    {
        return $this->app->make(CreateManualBlock::class)->handle($unit, Carbon::parse($firstNight), Carbon::parse($lastNight), $reason);
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

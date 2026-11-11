<?php

namespace Tests\Feature\Admin;

use App\Actions\Pricing\SetBasePrice;
use App\Enums\AvailabilityBlockSource;
use App\Enums\ReservationStatus;
use App\Filament\Pages\AvailabilityCalendar;
use App\Filament\Resources\RoomTypes\RoomTypeResource;
use App\Models\AvailabilityBlock;
use App\Models\CancellationPolicy;
use App\Models\Guest;
use App\Models\PropertyProfile;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\RoomType;
use App\Models\Unit;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class AvailabilityCalendarTest extends TestCase
{
    use RefreshDatabase;

    private const UNKNOWN_ID = '01a00000-0000-7000-8000-000000000000';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-12-01 00:00:00');

        PropertyProfile::factory()->create(['timezone' => 'Asia/Makassar', 'currency' => 'IDR']);
        CancellationPolicy::factory()->create(['name' => CancellationPolicy::NON_REFUNDABLE]);

        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_calendar_opens_from_the_admin_menu(): void
    {
        Unit::factory()->for(RoomType::factory()->state(['name' => 'Garden Villa']))->create(['code' => 'V1']);

        $this->get(AvailabilityCalendar::getUrl())
            ->assertOk()
            ->assertSee('Unit V1 · December 2026')
            ->assertSee('Garden Villa');
    }

    public function test_calendar_shows_reservations_and_blocks_of_the_unit(): void
    {
        $unit = Unit::factory()->create();
        $this->reserve($unit, 'CONF1234', 'Ayu Lestari', ReservationStatus::Confirmed, '2026-12-05', '2026-12-08');
        $this->reserve($unit, 'WAIT5678', 'Ben Carter', ReservationStatus::AwaitingPayment, '2026-12-10', '2026-12-12');
        $this->reserve($unit, 'PEND0000', 'Cara Diaz', ReservationStatus::Pending, '2026-12-14', '2026-12-15');
        AvailabilityBlock::factory()->for($unit)->create(['start_date' => '2026-12-20', 'end_date' => '2026-12-22', 'reason' => 'Pool repair']);
        AvailabilityBlock::factory()->for($unit)->create(['start_date' => '2026-12-27', 'end_date' => '2026-12-29', 'source' => AvailabilityBlockSource::OtaManual, 'reason' => 'Booking.com 4521']);

        $this->page()
            ->assertSee('CONF1234 · Ayu Lestari')
            ->assertSee('WAIT5678 · Ben Carter')
            ->assertDontSee('PEND0000')
            ->assertSee('Pool repair')
            ->assertSee('Booking.com 4521')
            // 3 confirmed + 2 awaiting payment + 2 blocked + 2 OTA booking.
            ->assertSee('9 nights taken');
    }

    public function test_first_room_type_with_units_is_shown_with_its_first_unit(): void
    {
        RoomType::factory()->create(['name' => 'A Empty Villa']);
        $garden = RoomType::factory()->create(['name' => 'Garden Villa']);
        $first = Unit::factory()->for($garden)->create(['code' => 'G1']);
        Unit::factory()->for($garden)->create(['code' => 'G2']);
        Unit::factory()->for(RoomType::factory()->state(['name' => 'Pool Villa']))->create(['code' => 'P1']);

        $this->page()
            ->assertSet('roomTypeId', $garden->id)
            ->assertSet('unitId', $first->id)
            ->assertSee('Unit G1 · December 2026')
            ->assertSee('G2')
            ->assertDontSee('P1');
    }

    public function test_choosing_a_room_type_shows_only_its_units(): void
    {
        Unit::factory()->for(RoomType::factory()->state(['name' => 'Garden Villa']))->create(['code' => 'G1']);
        $pool = RoomType::factory()->create(['name' => 'Pool Villa']);
        $poolUnit = Unit::factory()->for($pool)->create(['code' => 'P1']);
        Unit::factory()->for($pool)->create(['code' => 'P2']);

        $this->page()
            ->assertSee('Garden Villa (1 unit)')
            ->assertSee('Pool Villa (2 units)')
            ->call('selectRoomType', $pool->id)
            ->assertSet('roomTypeId', $pool->id)
            ->assertSet('unitId', $poolUnit->id)
            ->assertSee('Unit P1 · December 2026')
            ->assertSee('P2')
            ->assertDontSee('G1');
    }

    public function test_room_type_in_the_url_is_shown(): void
    {
        Unit::factory()->for(RoomType::factory()->state(['name' => 'Garden Villa']))->create(['code' => 'G1']);
        $pool = RoomType::factory()->create(['name' => 'Pool Villa']);
        $poolUnit = Unit::factory()->for($pool)->create(['code' => 'P1']);

        $this->page(['room_type' => $pool->id])
            ->assertSet('roomTypeId', $pool->id)
            ->assertSet('unitId', $poolUnit->id);
    }

    public function test_unit_in_the_url_brings_its_room_type_along(): void
    {
        $garden = RoomType::factory()->create(['name' => 'Garden Villa']);
        Unit::factory()->for($garden)->create(['code' => 'G1']);
        $pool = RoomType::factory()->create(['name' => 'Pool Villa']);
        Unit::factory()->for($pool)->create(['code' => 'P1']);
        $second = Unit::factory()->for($pool)->create(['code' => 'P2']);

        $this->page(['room_type' => $garden->id, 'unit' => $second->id])
            ->assertSet('roomTypeId', $pool->id)
            ->assertSet('unitId', $second->id)
            ->assertSee('Unit P2 · December 2026');
    }

    public function test_unknown_ids_in_the_url_fall_back_to_the_first_room_type_and_unit(): void
    {
        $first = Unit::factory()->for(RoomType::factory()->state(['name' => 'A Villa']))->create(['code' => 'V2']);
        Unit::factory()->for(RoomType::factory()->state(['name' => 'B Villa']))->create(['code' => 'V1']);

        $this->page(['room_type' => self::UNKNOWN_ID, 'unit' => self::UNKNOWN_ID])
            ->assertSet('roomTypeId', $first->room_type_id)
            ->assertSet('unitId', $first->id);
    }

    public function test_switching_to_an_unknown_room_type_is_not_found(): void
    {
        Unit::factory()->create();

        $this->page()
            ->call('selectRoomType', self::UNKNOWN_ID)
            ->assertNotFound();
    }

    public function test_switching_to_a_unit_of_another_room_type_is_not_found(): void
    {
        Unit::factory()->for(RoomType::factory()->state(['name' => 'Garden Villa']))->create();
        $poolUnit = Unit::factory()->for(RoomType::factory()->state(['name' => 'Pool Villa']))->create();

        $this->page()
            ->call('selectUnit', $poolUnit->id)
            ->assertNotFound();
    }

    public function test_room_type_without_units_can_only_edit_prices(): void
    {
        Unit::factory()->for(RoomType::factory()->state(['name' => 'Garden Villa']))->create();
        $empty = RoomType::factory()->create(['name' => 'Pool Villa']);

        $this->page()
            ->call('selectRoomType', $empty->id)
            ->assertSet('unitId', null)
            ->assertSee('No units yet')
            ->assertActionHidden('blockDates')
            ->assertActionVisible('editPrices');
    }

    public function test_calendar_moves_between_months(): void
    {
        Unit::factory()->create(['code' => 'V1']);

        $this->page()
            ->call('nextCalendarMonth')
            ->assertSee('Unit V1 · January 2027')
            ->call('previousCalendarMonth')
            ->call('previousCalendarMonth')
            ->assertSee('Unit V1 · November 2026');
    }

    public function test_without_room_types_no_actions_are_offered(): void
    {
        $this->page()
            ->assertSee('No room types yet')
            ->assertActionHidden('blockDates')
            ->assertActionHidden('editPrices');
    }

    public function test_dates_are_blocked_from_the_page(): void
    {
        $unit = Unit::factory()->create();

        $this->page()
            ->callAction('blockDates', ['first_night' => '2026-12-24', 'last_night' => '2026-12-26', 'reason' => 'Owner stay'])
            ->assertHasNoFormErrors()
            ->assertNotified('Dates blocked')
            ->assertSee('Owner stay');

        $block = $unit->availabilityBlocks()->sole();
        $this->assertSame('2026-12-24', $block->start_date->toDateString());
        $this->assertSame('2026-12-27', $block->end_date->toDateString());
    }

    public function test_blocking_booked_nights_shows_why_and_blocks_nothing(): void
    {
        $unit = Unit::factory()->create(['code' => 'V1']);
        $this->reserve($unit, 'CONF1234', 'Ayu Lestari', ReservationStatus::Confirmed, '2026-12-24', '2026-12-27');

        $this->page()
            ->callAction('blockDates', ['first_night' => '2026-12-26', 'last_night' => '2026-12-26'])
            ->assertNotified('Dates not blocked');

        $this->assertSame(0, AvailabilityBlock::count());
    }

    public function test_first_night_cannot_be_in_the_past(): void
    {
        Unit::factory()->create();

        $this->page()
            ->callAction('blockDates', ['first_night' => '2026-11-30', 'last_night' => '2026-12-02'])
            ->assertHasFormErrors(['first_night']);

        $this->assertSame(0, AvailabilityBlock::count());
    }

    public function test_block_is_unblocked(): void
    {
        $unit = Unit::factory()->create();
        $block = AvailabilityBlock::factory()->for($unit)->create(['start_date' => '2026-12-20', 'end_date' => '2026-12-22']);
        $other = AvailabilityBlock::factory()->for($unit)->create(['start_date' => '2026-12-27', 'end_date' => '2026-12-29']);

        $this->page()->callAction(TestAction::make('delete')->table($block));

        $this->assertModelMissing($block);
        $this->assertModelExists($other);
    }

    public function test_blocks_table_lists_upcoming_blocks_of_the_shown_unit_only(): void
    {
        $unit = Unit::factory()->create(['code' => 'V1']);
        $upcoming = AvailabilityBlock::factory()->for($unit)->create(['start_date' => '2026-11-30', 'end_date' => '2026-12-02', 'source' => AvailabilityBlockSource::OtaManual]);
        $past = AvailabilityBlock::factory()->for($unit)->create(['start_date' => '2026-11-20', 'end_date' => '2026-12-01']);
        $otherUnit = AvailabilityBlock::factory()->for(Unit::factory()->for($unit->roomType)->state(['code' => 'V2']))->create();

        $this->page()
            ->assertCanSeeTableRecords([$upcoming])
            ->assertCanNotSeeTableRecords([$past, $otherUnit]);
    }

    public function test_nights_show_their_price_and_prices_are_edited_on_the_pricing_page(): void
    {
        $roomType = RoomType::factory()->create();
        Unit::factory()->for($roomType)->create();
        app(SetBasePrice::class)->handle($roomType, '2500000');

        $this->page()
            ->assertSee('2,500,000')
            ->assertDontSee('No base price yet')
            ->assertActionHasUrl('editPrices', RoomTypeResource::getUrl('pricing', ['record' => $roomType]));
    }

    public function test_room_type_without_a_base_price_is_flagged(): void
    {
        Unit::factory()->create();

        $this->page()
            ->assertSee('No base price yet')
            ->assertSee('No price');
    }

    /**
     * @param  array<string, string>  $query
     */
    private function page(array $query = []): Testable
    {
        return Livewire::withQueryParams($query)->test(AvailabilityCalendar::class);
    }

    private function reserve(Unit $unit, string $code, string $guestName, ReservationStatus $status, string $checkIn, string $checkOut): Reservation
    {
        $reservation = Reservation::factory()->for(Guest::factory()->state(['full_name' => $guestName]))->create([
            'code' => $code,
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

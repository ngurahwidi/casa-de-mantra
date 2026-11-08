<?php

namespace Tests\Feature\Services;

use App\Data\Site\CalendarDay;
use App\Data\Site\StaySearchResult;
use App\Enums\ReservationStatus;
use App\Models\AvailabilityBlock;
use App\Models\CancellationPolicy;
use App\Models\PropertyProfile;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\RoomType;
use App\Models\Unit;
use App\Services\Site\StaySearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StaySearchTest extends TestCase
{
    use RefreshDatabase;

    private const FRIDAY = 16;

    private const SATURDAY = 32;

    private StaySearch $search;

    private RoomType $villa;

    private Unit $unit;

    private RatePlan $ratePlan;

    protected function setUp(): void
    {
        parent::setUp();

        // 08:00 on 1 Nov 2026 in Asia/Makassar, so "today" is 2026-11-01 and the last check-out 2027-11-01.
        Carbon::setTestNow('2026-11-01 00:00:00');

        PropertyProfile::factory()->create(['timezone' => 'Asia/Makassar']);

        $this->search = $this->app->make(StaySearch::class);
        $this->villa = RoomType::factory()->published()->create(['max_adults' => 4, 'max_children' => 2]);
        $this->unit = Unit::factory()->for($this->villa)->create(['code' => 'A']);
        $this->ratePlan = RatePlan::factory()->for($this->villa)->create([
            'cancellation_policy_id' => CancellationPolicy::factory()->create(['name' => CancellationPolicy::NON_REFUNDABLE]),
            'min_stay' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_free_dates_return_the_nightly_prices_total_and_policy(): void
    {
        $this->basePrice();
        $this->price(['name' => 'Weekend', 'days_of_week_mask' => self::FRIDAY | self::SATURDAY, 'price_per_night' => 1500000, 'priority' => 10]);

        // Thursday, Friday and Saturday nights.
        $result = $this->search('2026-12-03', '2026-12-06');

        $this->assertTrue($result->isAvailable);
        $this->assertSame(
            ['2026-12-03' => '1000000.00', '2026-12-04' => '1500000.00', '2026-12-05' => '1500000.00'],
            array_column(array_map(fn ($night) => (array) $night, $result->quote->nights), 'price', 'date'),
        );
        $this->assertSame('4000000.00', $result->quote->subtotal);
        $this->assertSame('0.00', $result->quote->taxTotal);
        $this->assertSame('4000000.00', $result->quote->total);
        $this->assertSame(CancellationPolicy::NON_REFUNDABLE, $result->policy->name);
        $this->assertNull($result->message);
    }

    public function test_pending_request_does_not_take_the_dates(): void
    {
        $this->basePrice();
        $this->reserve('2026-12-03', '2026-12-06', ReservationStatus::Pending);

        $this->assertTrue($this->search('2026-12-03', '2026-12-06')->isAvailable);
    }

    public function test_taken_dates_suggest_the_nearest_stay_of_the_same_length(): void
    {
        $this->basePrice();
        $this->reserve('2026-12-03', '2026-12-06', ReservationStatus::Confirmed);

        $result = $this->search('2026-12-04', '2026-12-06');

        $this->assertFalse($result->isAvailable);
        $this->assertNull($result->quote);
        $this->assertSame('The selected dates are not available.', $result->message);
        $this->assertSame('2026-12-06', $result->alternative->checkIn);
        $this->assertSame('2026-12-08', $result->alternative->checkOut);
        $this->assertSame('2000000.00', $result->alternative->total);
    }

    public function test_an_earlier_stay_is_suggested_when_it_is_closer(): void
    {
        $this->basePrice();
        AvailabilityBlock::factory()->for($this->unit)->create(['start_date' => '2026-12-05', 'end_date' => '2026-12-20']);

        $result = $this->search('2026-12-06', '2026-12-08');

        $this->assertSame('2026-12-03', $result->alternative->checkIn);
        $this->assertSame('2026-12-05', $result->alternative->checkOut);
    }

    public function test_no_alternative_is_suggested_when_no_stay_of_that_length_fits(): void
    {
        $this->basePrice();
        AvailabilityBlock::factory()->for($this->unit)->create(['start_date' => '2026-11-01', 'end_date' => '2027-11-01']);

        $result = $this->search('2026-12-04', '2026-12-06');

        $this->assertFalse($result->isAvailable);
        $this->assertNull($result->alternative);
    }

    public function test_alternative_needs_one_unit_free_for_every_night(): void
    {
        $this->basePrice();
        $unitB = Unit::factory()->for($this->villa)->create(['code' => 'B']);
        // Every night from 3 to 8 Dec is free on one unit, but never on the same unit two nights in a row.
        foreach (['2026-12-03', '2026-12-05', '2026-12-07'] as $night) {
            AvailabilityBlock::factory()->for($this->unit)->create(['start_date' => $night, 'end_date' => Carbon::parse($night)->addDay()->toDateString()]);
        }
        foreach (['2026-12-04', '2026-12-06', '2026-12-08'] as $night) {
            AvailabilityBlock::factory()->for($unitB)->create(['start_date' => $night, 'end_date' => Carbon::parse($night)->addDay()->toDateString()]);
        }

        $result = $this->search('2026-12-05', '2026-12-07');

        // 6–8, 4–6, 7–9 and 3–5 Dec each switch units mid-stay; 8–10 Dec stays on unit A.
        $this->assertSame('The selected dates are not available.', $result->message);
        $this->assertSame('2026-12-08', $result->alternative->checkIn);
    }

    public function test_nights_without_a_price_cannot_be_booked_and_suggest_priced_dates(): void
    {
        $this->price(['end_date' => '2026-12-31']);

        $result = $this->search('2026-12-30', '2027-01-02');

        $this->assertFalse($result->isAvailable);
        $this->assertSame('Some of these nights are not open for booking yet.', $result->message);
        $this->assertSame('2026-12-29', $result->alternative->checkIn);
        $this->assertSame('2027-01-01', $result->alternative->checkOut);
    }

    public function test_too_many_guests_reports_the_capacity_without_an_alternative(): void
    {
        $this->basePrice();

        $result = $this->search('2026-12-03', '2026-12-06', adults: 5);

        $this->assertFalse($result->isAvailable);
        $this->assertSame('This stay allows up to 4 adults and 2 children.', $result->message);
        $this->assertNull($result->alternative);
    }

    public function test_stay_shorter_than_the_minimum_stay_is_rejected(): void
    {
        $this->ratePlan->update(['min_stay' => 3]);
        $this->basePrice();

        $result = $this->search('2026-12-03', '2026-12-05');

        $this->assertFalse($result->isAvailable);
        $this->assertSame('These dates require a minimum stay of 3 nights.', $result->message);
        $this->assertNull($result->alternative);
    }

    public function test_check_in_before_today_in_the_property_timezone_is_rejected(): void
    {
        $this->basePrice();

        $result = $this->search('2026-10-31', '2026-11-02');

        $this->assertFalse($result->isAvailable);
        $this->assertSame('Check-in cannot be in the past.', $result->message);
    }

    public function test_check_out_after_the_booking_window_is_rejected(): void
    {
        $this->basePrice();

        $this->assertTrue($this->search('2027-10-29', '2027-11-01')->isAvailable);
        $this->assertSame('These dates are too far ahead to book yet.', $this->search('2027-10-30', '2027-11-02')->message);
    }

    public function test_villa_without_an_active_rate_plan_cannot_be_booked(): void
    {
        $this->basePrice();
        $this->ratePlan->update(['is_active' => false]);

        $result = $this->search('2026-12-03', '2026-12-06');

        $this->assertFalse($result->isAvailable);
        $this->assertSame('This accommodation is not available for booking.', $result->message);
        $this->assertSame(['2026-12-03', '2026-12-04'], $this->search->unsellableNights($this->villa, Carbon::parse('2026-12-03'), Carbon::parse('2026-12-05')));
    }

    public function test_unsellable_nights_cover_taken_unpriced_past_and_out_of_window_nights(): void
    {
        $this->price(['end_date' => '2026-12-31']);
        $this->reserve('2026-12-24', '2026-12-27', ReservationStatus::AwaitingPayment);

        $this->assertSame(['2026-10-30', '2026-10-31'], $this->unsellable('2026-10-30', '2026-11-02'));
        $this->assertSame(['2026-12-24', '2026-12-25', '2026-12-26', '2027-01-01'], $this->unsellable('2026-12-23', '2027-01-02'));
        $this->assertSame([], $this->unsellable('2026-12-27', '2027-01-01'));
    }

    public function test_unsellable_nights_include_nights_after_the_booking_window(): void
    {
        $this->basePrice();

        $this->assertSame(['2027-11-01', '2027-11-02'], $this->unsellable('2027-10-31', '2027-11-03'));
    }

    public function test_latest_check_out_is_the_first_unsellable_night_after_check_in(): void
    {
        $this->basePrice();
        AvailabilityBlock::factory()->for($this->unit)->create(['start_date' => '2026-12-24', 'end_date' => '2026-12-27']);

        $this->assertSame('2026-12-24', $this->search->latestCheckOut($this->villa, Carbon::parse('2026-12-20')));
        $this->assertSame('2027-11-01', $this->search->latestCheckOut($this->villa, Carbon::parse('2026-12-27')));
        $this->assertTrue($this->search->canCheckIn($this->villa, Carbon::parse('2026-12-23')));
        $this->assertFalse($this->search->canCheckIn($this->villa, Carbon::parse('2026-12-24')));
    }

    public function test_calendar_lays_out_the_month_from_monday_and_marks_past_and_taken_dates(): void
    {
        Carbon::setTestNow('2026-12-10 00:00:00');
        $this->basePrice();
        AvailabilityBlock::factory()->for($this->unit)->create(['start_date' => '2026-12-24', 'end_date' => '2026-12-27']);

        [$month] = $this->search->calendar($this->villa, Carbon::parse('2026-12-15'));

        $this->assertSame('2026-12', $month->month);
        $this->assertSame('December 2026', $month->label);
        $this->assertSame(1, $month->leadingBlanks);
        $this->assertCount(31, $month->days);
        $this->assertEquals(new CalendarDay('2026-12-09', 9, isPast: true, isSellable: false), $month->days[8]);
        $this->assertEquals(new CalendarDay('2026-12-10', 10, isPast: false, isSellable: true), $month->days[9]);
        $this->assertEquals(new CalendarDay('2026-12-24', 24, isPast: false, isSellable: false), $month->days[23]);
        $this->assertEquals(new CalendarDay('2026-12-27', 27, isPast: false, isSellable: true), $month->days[26]);
    }

    private function search(string $checkIn, string $checkOut, int $adults = 2, int $children = 0): StaySearchResult
    {
        return $this->search->search($this->villa, Carbon::parse($checkIn), Carbon::parse($checkOut), $adults, $children);
    }

    /**
     * @return list<string>
     */
    private function unsellable(string $from, string $to): array
    {
        return $this->search->unsellableNights($this->villa, Carbon::parse($from), Carbon::parse($to));
    }

    private function basePrice(): RateRule
    {
        return $this->price([]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function price(array $attributes): RateRule
    {
        return RateRule::factory()->for($this->ratePlan)->create([
            'start_date' => '2026-01-01',
            'price_per_night' => 1000000,
            ...$attributes,
        ]);
    }

    private function reserve(string $checkIn, string $checkOut, ReservationStatus $status): Reservation
    {
        $reservation = Reservation::factory()->create([
            'status' => $status,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
        ]);

        ReservationRoom::factory()->for($reservation)->create([
            'room_type_id' => $this->villa->id,
            'unit_id' => $this->unit->id,
        ]);

        return $reservation;
    }
}

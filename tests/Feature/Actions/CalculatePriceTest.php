<?php

namespace Tests\Feature\Actions;

use App\Actions\Pricing\CalculatePrice;
use App\Data\Pricing\NightPrice;
use App\Data\Pricing\PriceQuote;
use App\Enums\TaxFeeAppliesTo;
use App\Enums\TaxFeeType;
use App\Exceptions\Pricing\GuestCapacityExceededException;
use App\Exceptions\Pricing\PriceNotConfiguredException;
use App\Exceptions\Pricing\RoomTypeNotBookableException;
use App\Exceptions\Pricing\StayLengthNotAllowedException;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\RoomType;
use App\Models\TaxFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class CalculatePriceTest extends TestCase
{
    use RefreshDatabase;

    private const FRIDAY = 16;

    private const SATURDAY = 32;

    private CalculatePrice $calculate;

    private RoomType $roomType;

    private RatePlan $ratePlan;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-01 00:00:00');

        $this->calculate = $this->app->make(CalculatePrice::class);
        $this->roomType = RoomType::factory()->published()->create(['max_adults' => 4, 'max_children' => 2]);
        $this->ratePlan = RatePlan::factory()->for($this->roomType)->create(['min_stay' => 1, 'max_stay' => null]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_every_night_takes_the_base_price(): void
    {
        $base = $this->rule(['price_per_night' => 1000000]);

        $quote = $this->quote('2026-12-03', '2026-12-06');

        $this->assertSame('2026-12-03', $quote->checkIn);
        $this->assertSame('2026-12-06', $quote->checkOut);
        $this->assertSame(3, $quote->nightCount());
        $this->assertEquals([
            new NightPrice('2026-12-03', '1000000.00', $base->id),
            new NightPrice('2026-12-04', '1000000.00', $base->id),
            new NightPrice('2026-12-05', '1000000.00', $base->id),
        ], $quote->nights);
        $this->assertSame('3000000.00', $quote->subtotal);
        $this->assertSame('0.00', $quote->taxTotal);
        $this->assertSame('3000000.00', $quote->total);
    }

    public function test_weekend_nights_take_the_weekend_price(): void
    {
        $this->rule(['price_per_night' => 1000000]);
        $this->rule(['name' => 'Weekend', 'days_of_week_mask' => self::FRIDAY | self::SATURDAY, 'price_per_night' => 1500000, 'priority' => 10]);

        // Thursday → Monday: Thu, Fri, Sat, Sun.
        $quote = $this->quote('2026-12-03', '2026-12-07');

        $this->assertSame(['1000000.00', '1500000.00', '1500000.00', '1000000.00'], $this->prices($quote));
        $this->assertSame('5000000.00', $quote->subtotal);
    }

    public function test_the_highest_priority_rule_wins(): void
    {
        $this->rule(['price_per_night' => 1000000]);
        $this->rule(['name' => 'Special', 'start_date' => '2026-12-24', 'end_date' => '2026-12-25', 'price_per_night' => 3000000, 'priority' => 30]);
        // Created last and covering the special dates, but with a lower priority.
        $this->rule(['name' => 'Season', 'start_date' => '2026-12-20', 'end_date' => '2027-01-05', 'price_per_night' => 2000000, 'priority' => 20]);

        $quote = $this->quote('2026-12-23', '2026-12-27');

        $this->assertSame(['2000000.00', '3000000.00', '3000000.00', '2000000.00'], $this->prices($quote));
    }

    public function test_on_equal_priority_the_rule_starting_latest_wins(): void
    {
        $this->rule(['start_date' => '2026-12-10', 'price_per_night' => 2000000, 'priority' => 5]);
        $this->rule(['start_date' => '2026-01-01', 'price_per_night' => 1000000, 'priority' => 5]);

        $this->assertSame(['1000000.00', '2000000.00'], $this->prices($this->quote('2026-12-09', '2026-12-11')));
    }

    public function test_rule_without_end_date_applies_indefinitely(): void
    {
        $this->rule(['start_date' => '2026-01-01', 'end_date' => null, 'price_per_night' => 1000000]);

        $this->assertSame('2000000.00', $this->quote('2030-06-03', '2030-06-05')->subtotal);
    }

    public function test_rule_end_date_is_the_last_night_it_covers(): void
    {
        $this->rule(['price_per_night' => 1000000]);
        $this->rule(['start_date' => '2026-12-20', 'end_date' => '2026-12-21', 'price_per_night' => 2000000, 'priority' => 20]);

        $this->assertSame(['1000000.00', '2000000.00', '2000000.00', '1000000.00'], $this->prices($this->quote('2026-12-19', '2026-12-23')));
    }

    public function test_nights_without_a_matching_rule_cannot_be_sold(): void
    {
        $this->rule(['start_date' => '2026-12-05', 'price_per_night' => 1000000]);

        try {
            $this->quote('2026-12-03', '2026-12-07');
            $this->fail('Expected PriceNotConfiguredException.');
        } catch (PriceNotConfiguredException $e) {
            $this->assertSame(['2026-12-03', '2026-12-04'], $e->dates);
        }
    }

    public function test_nights_outside_the_rule_days_of_week_cannot_be_sold(): void
    {
        $this->rule(['days_of_week_mask' => self::FRIDAY | self::SATURDAY, 'price_per_night' => 1500000]);

        try {
            $this->quote('2026-12-03', '2026-12-07');
            $this->fail('Expected PriceNotConfiguredException.');
        } catch (PriceNotConfiguredException $e) {
            $this->assertSame(['2026-12-03', '2026-12-06'], $e->dates);
        }
    }

    public function test_rules_of_other_rate_plans_are_ignored(): void
    {
        RateRule::factory()->for(RatePlan::factory()->for($this->roomType))->create();

        $this->expectException(PriceNotConfiguredException::class);

        $this->quote('2026-12-03', '2026-12-05');
    }

    public function test_rate_plan_min_stay_is_enforced(): void
    {
        $this->ratePlan->update(['min_stay' => 2]);
        $this->rule();

        $this->assertStayLengthRejected('2026-12-03', '2026-12-04', minStay: 2);
        $this->assertSame(2, $this->quote('2026-12-03', '2026-12-05')->nightCount());
    }

    public function test_rate_plan_max_stay_is_enforced(): void
    {
        $this->ratePlan->update(['max_stay' => 3]);
        $this->rule();

        $this->assertStayLengthRejected('2026-12-03', '2026-12-07', minStay: 1);
        $this->assertSame(3, $this->quote('2026-12-03', '2026-12-06')->nightCount());
    }

    public function test_min_stay_override_of_a_matched_rule_is_enforced(): void
    {
        $this->rule();
        $this->rule(['name' => 'Christmas', 'start_date' => '2026-12-24', 'end_date' => '2026-12-26', 'min_stay_override' => 3, 'priority' => 20]);

        $this->assertSame(2, $this->quote('2026-12-22', '2026-12-24')->nightCount(), 'stay ends before the season');

        // Only the last night falls in the season, yet the season's minimum applies.
        $this->assertStayLengthRejected('2026-12-23', '2026-12-25', minStay: 3);
        $this->assertSame(3, $this->quote('2026-12-22', '2026-12-25')->nightCount());
    }

    public function test_min_stay_override_can_relax_the_rate_plan_minimum(): void
    {
        $this->ratePlan->update(['min_stay' => 3]);
        $this->rule();
        $this->rule(['name' => 'Last minute', 'start_date' => '2026-12-10', 'end_date' => '2026-12-10', 'min_stay_override' => 1, 'priority' => 20]);

        $this->assertSame(1, $this->quote('2026-12-10', '2026-12-11')->nightCount());
        $this->assertStayLengthRejected('2026-12-10', '2026-12-12', minStay: 3);
    }

    public function test_inactive_taxes_are_ignored(): void
    {
        $this->rule(['price_per_night' => 1000000]);
        TaxFee::factory()->create(['type' => TaxFeeType::Percent, 'value' => 10]);
        TaxFee::factory()->create(['type' => TaxFeeType::Fixed, 'value' => 50000, 'applies_to' => TaxFeeAppliesTo::PerBooking]);

        $quote = $this->quote('2026-12-03', '2026-12-05');

        $this->assertSame('0.00', $quote->taxTotal);
        $this->assertSame($quote->subtotal, $quote->total);
    }

    public function test_active_taxes_charged_on_top_are_added(): void
    {
        $this->rule(['price_per_night' => 1000000]);
        TaxFee::factory()->active()->create(['type' => TaxFeeType::Percent, 'value' => 10, 'applies_to' => TaxFeeAppliesTo::PerNight]);
        TaxFee::factory()->active()->create(['type' => TaxFeeType::Fixed, 'value' => 50000, 'applies_to' => TaxFeeAppliesTo::PerBooking]);
        TaxFee::factory()->active()->create(['type' => TaxFeeType::Fixed, 'value' => 10000, 'applies_to' => TaxFeeAppliesTo::PerNight]);
        TaxFee::factory()->active()->create(['type' => TaxFeeType::Fixed, 'value' => 5000, 'applies_to' => TaxFeeAppliesTo::PerGuest]);
        // Already part of the nightly price.
        TaxFee::factory()->active()->create(['type' => TaxFeeType::Percent, 'value' => 11, 'is_included_in_price' => true]);

        $quote = $this->quote('2026-12-03', '2026-12-05', adults: 2, children: 1);

        // 10% of 2,000,000 + 50,000 + 2 × 10,000 + 3 × 5,000.
        $this->assertSame('2000000.00', $quote->subtotal);
        $this->assertSame('285000.00', $quote->taxTotal);
        $this->assertSame('2285000.00', $quote->total);
    }

    public function test_amounts_keep_cents_and_percent_taxes_round_half_up(): void
    {
        $this->rule(['price_per_night' => '1234567.55']);
        TaxFee::factory()->active()->create(['type' => TaxFeeType::Percent, 'value' => '10.50', 'applies_to' => TaxFeeAppliesTo::PerBooking]);

        $quote = $this->quote('2026-12-03', '2026-12-06');

        // 10.5% of 3,703,702.65 = 388,888.77825.
        $this->assertSame('3703702.65', $quote->subtotal);
        $this->assertSame('388888.78', $quote->taxTotal);
        $this->assertSame('4092591.43', $quote->total);
    }

    public function test_guests_up_to_capacity_are_accepted(): void
    {
        $this->rule();

        $this->assertSame(1, $this->quote('2026-12-03', '2026-12-04', adults: 4, children: 2)->nightCount());
    }

    public function test_too_many_adults_are_rejected(): void
    {
        $this->rule();

        $this->expectException(GuestCapacityExceededException::class);

        $this->quote('2026-12-03', '2026-12-04', adults: 5);
    }

    public function test_too_many_children_are_rejected(): void
    {
        $this->rule();

        $this->expectException(GuestCapacityExceededException::class);

        $this->quote('2026-12-03', '2026-12-04', adults: 1, children: 3);
    }

    public function test_unpublished_room_type_cannot_be_priced(): void
    {
        $this->rule();
        $this->roomType->update(['is_published' => false]);

        $this->expectException(RoomTypeNotBookableException::class);

        $this->quote('2026-12-03', '2026-12-04');
    }

    public function test_inactive_rate_plan_cannot_be_priced(): void
    {
        $this->rule();
        $this->ratePlan->update(['is_active' => false]);

        $this->expectException(RoomTypeNotBookableException::class);

        $this->quote('2026-12-03', '2026-12-04');
    }

    public function test_rate_plan_must_belong_to_the_room_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculate->handle(
            RoomType::factory()->published()->create(),
            $this->ratePlan,
            Carbon::parse('2026-12-03'),
            Carbon::parse('2026-12-04'),
            adults: 2,
        );
    }

    public function test_check_out_must_be_after_check_in(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->quote('2026-12-03', '2026-12-03');
    }

    public function test_at_least_one_adult_is_required(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->quote('2026-12-03', '2026-12-04', adults: 0);
    }

    public function test_only_the_date_part_is_used(): void
    {
        $this->rule();

        $quote = $this->calculate->handle(
            $this->roomType,
            $this->ratePlan,
            Carbon::parse('2026-12-03 23:00:00'),
            Carbon::parse('2026-12-04 01:00:00'),
            adults: 2,
        );

        $this->assertSame(['2026-12-03'], array_map(fn (NightPrice $night) => $night->date, $quote->nights));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function rule(array $attributes = []): RateRule
    {
        return RateRule::factory()->for($this->ratePlan)->create($attributes);
    }

    private function quote(string $checkIn, string $checkOut, int $adults = 2, int $children = 0): PriceQuote
    {
        return $this->calculate->handle(
            $this->roomType,
            $this->ratePlan,
            Carbon::parse($checkIn),
            Carbon::parse($checkOut),
            $adults,
            $children,
        );
    }

    /**
     * @return list<string>
     */
    private function prices(PriceQuote $quote): array
    {
        return array_map(fn (NightPrice $night) => $night->price, $quote->nights);
    }

    private function assertStayLengthRejected(string $checkIn, string $checkOut, int $minStay): void
    {
        try {
            $this->quote($checkIn, $checkOut);
            $this->fail("Expected StayLengthNotAllowedException for {$checkIn} → {$checkOut}.");
        } catch (StayLengthNotAllowedException $e) {
            $this->assertSame($minStay, $e->minStay);
        }
    }
}

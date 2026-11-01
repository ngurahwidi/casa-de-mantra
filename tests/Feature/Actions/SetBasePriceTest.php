<?php

namespace Tests\Feature\Actions;

use App\Actions\Pricing\CalculatePrice;
use App\Actions\Pricing\SetBasePrice;
use App\Enums\MealPlan;
use App\Models\CancellationPolicy;
use App\Models\PropertyProfile;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\RoomType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SetBasePriceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 23:30 UTC is already 2 November in Asia/Makassar (UTC+8).
        Carbon::setTestNow('2026-11-01 23:30:00');

        PropertyProfile::factory()->create(['timezone' => 'Asia/Makassar']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_first_base_price_creates_the_non_refundable_rate_plan_and_base_rule(): void
    {
        $policy = CancellationPolicy::factory()->create(['name' => CancellationPolicy::NON_REFUNDABLE]);
        $roomType = RoomType::factory()->create();

        $rule = app(SetBasePrice::class)->handle($roomType, '2500000', 2);

        $ratePlan = $roomType->ratePlans()->sole();
        $this->assertSame(CancellationPolicy::NON_REFUNDABLE, $ratePlan->name);
        $this->assertSame($policy->id, $ratePlan->cancellation_policy_id);
        $this->assertSame(MealPlan::RoomOnly, $ratePlan->meal_plan);
        $this->assertTrue($ratePlan->is_active);
        $this->assertSame(2, $ratePlan->min_stay);
        $this->assertNull($ratePlan->max_stay);

        $rule->refresh();
        $this->assertSame($ratePlan->id, $rule->rate_plan_id);
        $this->assertSame(RateRule::BASE_PRICE, $rule->name);
        $this->assertSame('2026-11-02', $rule->start_date->toDateString());
        $this->assertNull($rule->end_date);
        $this->assertSame(RateRule::ALL_DAYS, $rule->days_of_week_mask);
        $this->assertSame(0, $rule->priority);
        $this->assertSame('2500000.00', $rule->price_per_night);
        $this->assertNull($rule->min_stay_override);
    }

    public function test_base_price_makes_every_future_night_sellable(): void
    {
        CancellationPolicy::factory()->create(['name' => CancellationPolicy::NON_REFUNDABLE]);
        $roomType = RoomType::factory()->published()->create();

        app(SetBasePrice::class)->handle($roomType, '1000000');

        $quote = app(CalculatePrice::class)->handle(
            $roomType,
            $roomType->nonRefundableRatePlan()->sole(),
            Carbon::parse('2027-12-30'),
            Carbon::parse('2028-01-02'),
            adults: 2,
        );
        $this->assertSame('3000000.00', $quote->total);
    }

    public function test_changing_the_base_price_updates_the_existing_rule_and_keeps_its_start(): void
    {
        CancellationPolicy::factory()->create(['name' => CancellationPolicy::NON_REFUNDABLE]);
        $roomType = RoomType::factory()->create();
        $first = app(SetBasePrice::class)->handle($roomType, '2500000');
        Carbon::setTestNow('2026-12-15 10:00:00');

        $second = app(SetBasePrice::class)->handle($roomType, '2750000.50', 3);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $roomType->ratePlans()->count());
        $this->assertSame(1, RateRule::count());
        $this->assertSame('2750000.50', $second->fresh()->price_per_night);
        $this->assertSame('2026-11-02', $second->fresh()->start_date->toDateString());
        $this->assertSame(3, $roomType->nonRefundableRatePlan()->sole()->min_stay);
    }

    public function test_existing_rate_plan_without_a_base_price_is_reused(): void
    {
        $roomType = RoomType::factory()->create();
        $ratePlan = RatePlan::factory()->for($roomType)->create(['name' => CancellationPolicy::NON_REFUNDABLE]);

        $rule = app(SetBasePrice::class)->handle($roomType, '2500000');

        $this->assertSame($ratePlan->id, $rule->rate_plan_id);
        $this->assertSame(1, $roomType->ratePlans()->count());
    }

    public function test_missing_non_refundable_policy_is_reported_and_nothing_is_created(): void
    {
        $roomType = RoomType::factory()->create();

        try {
            app(SetBasePrice::class)->handle($roomType, '2500000');
            $this->fail('Expected a missing policy to be reported.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Non-Refundable cancellation policy is missing', $exception->getMessage());
        }

        $this->assertSame(0, RatePlan::count());
        $this->assertSame(0, RateRule::count());
    }

    public function test_negative_price_is_rejected(): void
    {
        $roomType = RoomType::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        app(SetBasePrice::class)->handle($roomType, '-1');
    }
}

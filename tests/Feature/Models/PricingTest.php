<?php

namespace Tests\Feature\Models;

use App\Enums\MealPlan;
use App\Enums\TaxFeeAppliesTo;
use App\Enums\TaxFeeType;
use App\Models\CancellationPolicy;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\RoomType;
use App\Models\TaxFee;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_keys_are_uuids(): void
    {
        $rule = RateRule::factory()->create();

        foreach ([$rule, $rule->ratePlan, $rule->ratePlan->cancellationPolicy, TaxFee::factory()->create()] as $model) {
            $this->assertTrue(Str::isUuid($model->id));
        }
    }

    public function test_cancellation_policy_rules_are_cast_to_array_and_default_to_empty(): void
    {
        $policy = CancellationPolicy::create(['name' => 'Flexible'])->fresh();
        $this->assertSame([], $policy->rules);

        $rules = [['days_before' => 7, 'refund_percent' => 100]];
        $policy->update(['rules' => $rules]);

        $this->assertSame($rules, $policy->fresh()->rules);
    }

    public function test_cancellation_policy_name_is_unique(): void
    {
        CancellationPolicy::factory()->create(['name' => 'Non-Refundable']);

        $this->expectException(QueryException::class);

        CancellationPolicy::factory()->create(['name' => 'Non-Refundable']);
    }

    public function test_rate_plan_defaults_and_casts(): void
    {
        $plan = RatePlan::create([
            'room_type_id' => RoomType::factory()->create()->id,
            'cancellation_policy_id' => CancellationPolicy::factory()->create()->id,
            'name' => 'Non-Refundable',
        ])->fresh();

        $this->assertSame(MealPlan::RoomOnly, $plan->meal_plan);
        $this->assertSame(1, $plan->min_stay);
        $this->assertNull($plan->max_stay);
        $this->assertTrue($plan->is_active);
    }

    public function test_rate_plan_relations_and_active_scope(): void
    {
        $roomType = RoomType::factory()->create();
        $policy = CancellationPolicy::factory()->create();
        $active = RatePlan::factory()->for($roomType)->for($policy)->create();
        RatePlan::factory()->for($roomType)->for($policy)->inactive()->create();

        $this->assertCount(2, $roomType->ratePlans);
        $this->assertCount(2, $policy->ratePlans);
        $this->assertTrue($active->roomType->is($roomType));
        $this->assertTrue($active->cancellationPolicy->is($policy));
        $this->assertEquals([$active->id], $roomType->ratePlans()->active()->pluck('id')->all());
    }

    public function test_unknown_meal_plan_is_rejected_by_the_database(): void
    {
        $plan = RatePlan::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('rate_plans')->where('id', $plan->id)->update(['meal_plan' => 'all_inclusive']);
    }

    public function test_rate_plan_stay_limits_are_validated_by_the_database(): void
    {
        $this->assertThrowsQuery(fn () => RatePlan::factory()->create(['min_stay' => 0]));
        $this->assertThrowsQuery(fn () => RatePlan::factory()->create(['min_stay' => 3, 'max_stay' => 2]));

        $plan = RatePlan::factory()->create(['min_stay' => 2, 'max_stay' => 2]);

        $this->assertSame(2, $plan->fresh()->max_stay);
    }

    public function test_room_type_and_policy_in_use_cannot_be_deleted(): void
    {
        $plan = RatePlan::factory()->create();

        $this->assertThrowsQuery(fn () => $plan->roomType->delete());
        $this->assertThrowsQuery(fn () => $plan->cancellationPolicy->delete());
    }

    public function test_rate_rule_defaults_and_casts(): void
    {
        $rule = RateRule::create([
            'rate_plan_id' => RatePlan::factory()->create()->id,
            'name' => 'Base Price',
            'start_date' => '2026-01-01',
            'price_per_night' => 1500000,
        ])->fresh();

        $this->assertEquals(Carbon::parse('2026-01-01'), $rule->start_date);
        $this->assertNull($rule->end_date);
        $this->assertSame(RateRule::ALL_DAYS, $rule->days_of_week_mask);
        $this->assertSame('1500000.00', $rule->price_per_night);
        $this->assertNull($rule->min_stay_override);
        $this->assertSame(0, $rule->priority);
    }

    public function test_rate_rule_price_keeps_two_decimals(): void
    {
        $rule = RateRule::factory()->create(['price_per_night' => '1234567.5'])->fresh();

        $this->assertSame('1234567.50', $rule->price_per_night);
    }

    public function test_rate_rules_belong_to_a_plan_and_are_deleted_with_it(): void
    {
        $plan = RatePlan::factory()->create();
        RateRule::factory()->count(2)->for($plan)->create();
        $other = RateRule::factory()->create();

        $this->assertCount(2, $plan->rateRules);

        $plan->delete();

        $this->assertSame(1, RateRule::count());
        $this->assertTrue(RateRule::first()->is($other));
    }

    public function test_rate_rule_constraints_are_enforced_by_the_database(): void
    {
        $this->assertThrowsQuery(fn () => RateRule::factory()->create(['end_date' => '2025-12-31']));
        $this->assertThrowsQuery(fn () => RateRule::factory()->create(['days_of_week_mask' => 0]));
        $this->assertThrowsQuery(fn () => RateRule::factory()->create(['days_of_week_mask' => 128]));
        $this->assertThrowsQuery(fn () => RateRule::factory()->create(['price_per_night' => -1]));
        $this->assertThrowsQuery(fn () => RateRule::factory()->create(['min_stay_override' => 0]));

        $single = RateRule::factory()->create([
            'start_date' => '2026-12-25',
            'end_date' => '2026-12-25',
            'days_of_week_mask' => 1,
            'min_stay_override' => 3,
        ]);

        $this->assertSame(3, $single->fresh()->min_stay_override);
    }

    public function test_tax_fee_is_inactive_by_default(): void
    {
        $fee = TaxFee::create([
            'name' => 'Service charge',
            'type' => TaxFeeType::Percent,
            'value' => 10,
        ])->fresh();

        $this->assertFalse($fee->is_active);
        $this->assertFalse($fee->is_included_in_price);
        $this->assertSame(TaxFeeAppliesTo::PerNight, $fee->applies_to);
        $this->assertSame(TaxFeeType::Percent, $fee->type);
        $this->assertSame('10.00', $fee->value);
    }

    public function test_tax_fee_active_scope_excludes_inactive_rows(): void
    {
        TaxFee::factory()->create();
        $active = TaxFee::factory()->active()->create();

        $this->assertEquals([$active->id], TaxFee::active()->pluck('id')->all());
    }

    public function test_tax_fee_constraints_are_enforced_by_the_database(): void
    {
        $this->assertThrowsQuery(fn () => TaxFee::factory()->create(['value' => -1]));
        $this->assertThrowsQuery(fn () => TaxFee::factory()->create(['type' => TaxFeeType::Percent, 'value' => 101]));

        $fixed = TaxFee::factory()->create(['type' => TaxFeeType::Fixed, 'value' => 250000]);
        $this->assertSame('250000.00', $fixed->fresh()->value);

        $this->assertThrowsQuery(fn () => DB::table('tax_fees')->where('id', $fixed->id)->update(['type' => 'tiered']));
        $this->assertThrowsQuery(fn () => DB::table('tax_fees')->where('id', $fixed->id)->update(['applies_to' => 'per_pet']));
    }

    private function assertThrowsQuery(callable $callback): void
    {
        try {
            $callback();
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected the database to reject the statement.');
    }
}

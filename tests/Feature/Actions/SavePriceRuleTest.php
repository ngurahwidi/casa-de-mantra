<?php

namespace Tests\Feature\Actions;

use App\Actions\Pricing\CalculatePrice;
use App\Actions\Pricing\SavePriceRule;
use App\Actions\Pricing\SetBasePrice;
use App\Data\Pricing\NightPrice;
use App\Data\Pricing\PriceRuleData;
use App\Enums\RateRuleType;
use App\Models\CancellationPolicy;
use App\Models\PropertyProfile;
use App\Models\RateRule;
use App\Models\RoomType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class SavePriceRuleTest extends TestCase
{
    use RefreshDatabase;

    private const FRIDAY = 5;

    private const SATURDAY = 6;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-01 00:00:00');

        PropertyProfile::factory()->create(['timezone' => 'Asia/Makassar']);
        CancellationPolicy::factory()->create(['name' => CancellationPolicy::NON_REFUNDABLE]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_weekend_price_covers_the_chosen_days_from_today_on(): void
    {
        $roomType = $this->roomTypeWithBasePrice();

        $rule = app(SavePriceRule::class)->handle(
            $roomType,
            PriceRuleData::weekend('Weekend', '3000000', [self::FRIDAY, self::SATURDAY]),
        );

        $rule->refresh();
        $this->assertSame($roomType->nonRefundableRatePlan()->sole()->id, $rule->rate_plan_id);
        $this->assertSame(RateRuleType::Weekend, $rule->type());
        $this->assertSame(16 | 32, $rule->days_of_week_mask);
        $this->assertSame('2026-11-01', $rule->start_date->toDateString());
        $this->assertNull($rule->end_date);
        $this->assertSame('3000000.00', $rule->price_per_night);
        $this->assertNull($rule->min_stay_override);
    }

    public function test_seasonal_price_covers_every_night_of_the_season_with_its_min_stay(): void
    {
        $roomType = $this->roomTypeWithBasePrice();

        $rule = app(SavePriceRule::class)->handle(
            $roomType,
            PriceRuleData::seasonal('High season', '3500000', Carbon::parse('2027-07-01'), Carbon::parse('2027-08-31'), 3),
        );

        $rule->refresh();
        $this->assertSame(RateRuleType::Seasonal, $rule->type());
        $this->assertSame(RateRule::ALL_DAYS, $rule->days_of_week_mask);
        $this->assertSame('2027-07-01', $rule->start_date->toDateString());
        $this->assertSame('2027-08-31', $rule->end_date->toDateString());
        $this->assertSame(3, $rule->min_stay_override);
    }

    public function test_special_date_without_last_night_covers_one_night(): void
    {
        $roomType = $this->roomTypeWithBasePrice();

        $rule = app(SavePriceRule::class)->handle(
            $roomType,
            PriceRuleData::specialDates('New Year', '5000000', Carbon::parse('2026-12-31')),
        );

        $rule->refresh();
        $this->assertSame(RateRuleType::SpecialDates, $rule->type());
        $this->assertSame('2026-12-31', $rule->start_date->toDateString());
        $this->assertSame('2026-12-31', $rule->end_date->toDateString());
        $this->assertNull($rule->min_stay_override);
    }

    public function test_special_dates_beat_seasons_which_beat_weekends_which_beat_the_base_price(): void
    {
        $roomType = $this->roomTypeWithBasePrice('1000000');
        $save = app(SavePriceRule::class);
        $save->handle($roomType, PriceRuleData::specialDates('Christmas', '4000000', Carbon::parse('2026-12-25')));
        $save->handle($roomType, PriceRuleData::seasonal('Holidays', '3000000', Carbon::parse('2026-12-24'), Carbon::parse('2026-12-31')));
        $save->handle($roomType, PriceRuleData::weekend('Weekend', '2000000', [self::FRIDAY, self::SATURDAY]));

        // Wed 23 Dec (base), Thu 24 (season), Fri 25 (special), Sat 26 (season beats weekend).
        $quote = app(CalculatePrice::class)->handle(
            $roomType,
            $roomType->nonRefundableRatePlan()->sole(),
            Carbon::parse('2026-12-23'),
            Carbon::parse('2026-12-27'),
            adults: 2,
        );

        $this->assertSame(
            ['1000000.00', '3000000.00', '4000000.00', '3000000.00'],
            array_map(fn (NightPrice $night): string => $night->price, $quote->nights),
        );
    }

    public function test_editing_a_weekend_price_keeps_its_start_date(): void
    {
        $roomType = $this->roomTypeWithBasePrice();
        $rule = app(SavePriceRule::class)->handle($roomType, PriceRuleData::weekend('Weekend', '3000000', [self::SATURDAY]));
        Carbon::setTestNow('2027-02-10 00:00:00');

        app(SavePriceRule::class)->handle($roomType, PriceRuleData::weekend('Fri & Sat', '3200000', [self::FRIDAY, self::SATURDAY]), $rule);

        $rule->refresh();
        $this->assertSame('Fri & Sat', $rule->name);
        $this->assertSame('3200000.00', $rule->price_per_night);
        $this->assertSame(16 | 32, $rule->days_of_week_mask);
        $this->assertSame('2026-11-01', $rule->start_date->toDateString());
        $this->assertSame(2, RateRule::count());
    }

    public function test_editing_cannot_change_the_type_of_a_price(): void
    {
        $roomType = $this->roomTypeWithBasePrice();
        $weekend = app(SavePriceRule::class)->handle($roomType, PriceRuleData::weekend('Weekend', '3000000', [self::SATURDAY]));

        try {
            app(SavePriceRule::class)->handle($roomType, PriceRuleData::specialDates('Christmas', '4000000', Carbon::parse('2026-12-25')), $weekend);
            $this->fail('Expected the type change to be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertSame(RateRuleType::Weekend, $weekend->fresh()->type());
        }
    }

    public function test_price_of_another_room_type_cannot_be_edited(): void
    {
        $other = $this->roomTypeWithBasePrice();
        $foreign = app(SavePriceRule::class)->handle($other, PriceRuleData::weekend('Weekend', '3000000', [self::SATURDAY]));
        $roomType = $this->roomTypeWithBasePrice();

        $this->expectException(InvalidArgumentException::class);

        app(SavePriceRule::class)->handle($roomType, PriceRuleData::weekend('Weekend', '1', [self::SATURDAY]), $foreign);
    }

    public function test_price_cannot_be_added_before_the_base_price(): void
    {
        $roomType = RoomType::factory()->create();

        try {
            app(SavePriceRule::class)->handle($roomType, PriceRuleData::weekend('Weekend', '3000000', [self::SATURDAY]));
            $this->fail('Expected a missing base price to be rejected.');
        } catch (LogicException) {
            $this->assertSame(0, RateRule::count());
        }
    }

    public function test_last_night_before_first_night_is_rejected(): void
    {
        $roomType = $this->roomTypeWithBasePrice();

        $this->expectException(InvalidArgumentException::class);

        app(SavePriceRule::class)->handle(
            $roomType,
            PriceRuleData::seasonal('High season', '3500000', Carbon::parse('2027-08-31'), Carbon::parse('2027-07-01')),
        );
    }

    public function test_weekend_price_without_days_is_rejected(): void
    {
        $roomType = $this->roomTypeWithBasePrice();

        $this->expectException(InvalidArgumentException::class);

        app(SavePriceRule::class)->handle($roomType, PriceRuleData::weekend('Weekend', '3000000', []));
    }

    private function roomTypeWithBasePrice(string $price = '2500000'): RoomType
    {
        $roomType = RoomType::factory()->published()->create(['max_adults' => 4]);
        app(SetBasePrice::class)->handle($roomType, $price);

        return $roomType;
    }
}

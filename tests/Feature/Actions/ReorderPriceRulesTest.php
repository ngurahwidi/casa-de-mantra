<?php

namespace Tests\Feature\Actions;

use App\Actions\Pricing\CalculatePrice;
use App\Actions\Pricing\ReorderPriceRules;
use App\Actions\Pricing\SavePriceRule;
use App\Actions\Pricing\SetBasePrice;
use App\Data\Pricing\PriceRuleData;
use App\Models\CancellationPolicy;
use App\Models\PropertyProfile;
use App\Models\RateRule;
use App\Models\RoomType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class ReorderPriceRulesTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_moving_a_price_up_makes_it_win_over_prices_of_the_same_type(): void
    {
        $roomType = $this->roomTypeWithBasePrice();
        $high = $this->seasonal($roomType, 'High season', '3000000', '2026-12-01', '2027-01-31');
        $holidays = $this->seasonal($roomType, 'Holidays', '3500000', '2026-12-20', '2027-01-05');
        $base = RateRule::query()->where('name', RateRule::BASE_PRICE)->sole();

        app(ReorderPriceRules::class)->handle($roomType, [$high->id, $holidays->id, $base->id]);

        $this->assertSame('3000000.00', $this->priceOf($roomType, '2026-12-24'));
        $this->assertSame([2001, 2000, 0], [$high->fresh()->priority, $holidays->fresh()->priority, $base->fresh()->priority]);
    }

    public function test_order_across_types_does_not_let_a_season_beat_special_dates(): void
    {
        $roomType = $this->roomTypeWithBasePrice();
        $season = $this->seasonal($roomType, 'Holidays', '3000000', '2026-12-20', '2027-01-05');
        $christmas = app(SavePriceRule::class)->handle($roomType, PriceRuleData::specialDates('Christmas', '4000000', Carbon::parse('2026-12-25')));
        $base = RateRule::query()->where('name', RateRule::BASE_PRICE)->sole();

        app(ReorderPriceRules::class)->handle($roomType, [$base->id, $season->id, $christmas->id]);

        $this->assertSame('4000000.00', $this->priceOf($roomType, '2026-12-25'));
        $this->assertSame(0, $base->fresh()->priority);
    }

    public function test_order_must_list_every_price_of_the_room_type(): void
    {
        $roomType = $this->roomTypeWithBasePrice();
        $high = $this->seasonal($roomType, 'High season', '3000000', '2026-12-01', '2027-01-31');
        $holidays = $this->seasonal($roomType, 'Holidays', '3500000', '2026-12-20', '2027-01-05');
        $base = RateRule::query()->where('name', RateRule::BASE_PRICE)->sole();

        try {
            app(ReorderPriceRules::class)->handle($roomType, [$high->id, $high->id, $base->id]);
            $this->fail('Expected an incomplete order to be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertSame([2000, 2001], [$high->fresh()->priority, $holidays->fresh()->priority]);
        }
    }

    public function test_prices_of_another_room_type_are_rejected(): void
    {
        $roomType = $this->roomTypeWithBasePrice();
        $other = $this->roomTypeWithBasePrice();
        $foreign = $this->seasonal($other, 'High season', '3000000', '2026-12-01', '2027-01-31');
        $base = $roomType->nonRefundableRatePlan()->sole()->rateRules()->sole();

        $this->expectException(InvalidArgumentException::class);

        app(ReorderPriceRules::class)->handle($roomType, [$foreign->id, $base->id]);
    }

    private function seasonal(RoomType $roomType, string $name, string $price, string $firstNight, string $lastNight): RateRule
    {
        return app(SavePriceRule::class)->handle(
            $roomType,
            PriceRuleData::seasonal($name, $price, Carbon::parse($firstNight), Carbon::parse($lastNight)),
        );
    }

    private function priceOf(RoomType $roomType, string $night): string
    {
        return app(CalculatePrice::class)->handle(
            $roomType,
            $roomType->nonRefundableRatePlan()->sole(),
            Carbon::parse($night),
            Carbon::parse($night)->addDay(),
            adults: 2,
        )->total;
    }

    private function roomTypeWithBasePrice(): RoomType
    {
        $roomType = RoomType::factory()->published()->create();
        app(SetBasePrice::class)->handle($roomType, '2500000');

        return $roomType;
    }
}

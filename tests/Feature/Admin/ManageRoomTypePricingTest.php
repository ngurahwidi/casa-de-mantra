<?php

namespace Tests\Feature\Admin;

use App\Actions\Pricing\SavePriceRule;
use App\Actions\Pricing\SetBasePrice;
use App\Data\Pricing\PriceRuleData;
use App\Enums\RateRuleType;
use App\Filament\Resources\RoomTypes\Pages\ManageRoomTypePricing;
use App\Filament\Resources\RoomTypes\RoomTypeResource;
use App\Models\CancellationPolicy;
use App\Models\PropertyProfile;
use App\Models\RateRule;
use App\Models\RoomType;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class ManageRoomTypePricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-01 00:00:00');

        PropertyProfile::factory()->create(['timezone' => 'Asia/Makassar', 'currency' => 'IDR']);
        CancellationPolicy::factory()->create(['name' => CancellationPolicy::NON_REFUNDABLE]);

        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_pricing_page_opens_from_the_room_type(): void
    {
        $roomType = RoomType::factory()->create(['name' => 'Garden Villa']);

        $this->get(RoomTypeResource::getUrl('pricing', ['record' => $roomType]))
            ->assertOk()
            ->assertSee('Garden Villa')
            ->assertSee('No base price yet')
            ->assertDontSee('rate plan');
    }

    public function test_room_type_without_base_price_can_only_set_one(): void
    {
        $roomType = RoomType::factory()->create();

        $this->page($roomType)
            ->assertActionVisible('setBasePrice')
            ->assertActionHidden('addWeekend')
            ->assertActionHidden('addSeasonal')
            ->assertActionHidden('addSpecialDates')
            ->assertSee('30 nights have no price');
    }

    public function test_setting_the_base_price_makes_the_room_type_sellable(): void
    {
        $roomType = RoomType::factory()->create();

        $this->page($roomType)
            ->callAction('setBasePrice', ['price_per_night' => '2500000', 'min_stay' => 2])
            ->assertHasNoFormErrors()
            ->assertActionVisible('addWeekend')
            ->assertSee('2,500,000')
            ->assertDontSee('No base price yet')
            ->assertSee('Prices per night in IDR');

        $ratePlan = $roomType->nonRefundableRatePlan()->sole();
        $this->assertSame(2, $ratePlan->min_stay);
        $this->assertSame('2500000.00', $ratePlan->rateRules()->sole()->price_per_night);
    }

    public function test_base_price_must_be_a_non_negative_number(): void
    {
        $roomType = RoomType::factory()->create();

        $this->page($roomType)
            ->callAction('setBasePrice', ['price_per_night' => '-5', 'min_stay' => 1])
            ->assertHasFormErrors(['price_per_night' => 'min']);

        $this->assertSame(0, RateRule::count());
    }

    public function test_weekend_price_is_added_for_the_chosen_nights(): void
    {
        $roomType = $this->roomTypeWithBasePrice();

        $this->page($roomType)
            ->callAction('addWeekend', ['name' => 'Weekend', 'days' => [5, 6], 'price_per_night' => '3000000'])
            ->assertHasNoFormErrors();

        $rule = RateRule::query()->where('priority', RateRuleType::Weekend->priority())->sole();
        $this->assertSame(16 | 32, $rule->days_of_week_mask);
        $this->assertSame('3000000.00', $rule->price_per_night);
    }

    public function test_weekend_price_needs_at_least_one_night(): void
    {
        $roomType = $this->roomTypeWithBasePrice();

        $this->page($roomType)
            ->callAction('addWeekend', ['name' => 'Weekend', 'days' => [], 'price_per_night' => '3000000'])
            ->assertHasFormErrors(['days' => 'required']);
    }

    public function test_seasonal_price_is_added_with_its_minimum_stay(): void
    {
        $roomType = $this->roomTypeWithBasePrice();

        $this->page($roomType)
            ->callAction('addSeasonal', [
                'name' => 'High season',
                'first_night' => '2027-07-01',
                'last_night' => '2027-08-31',
                'price_per_night' => '3500000',
                'min_stay' => 3,
            ])
            ->assertHasNoFormErrors();

        $rule = RateRule::query()->where('priority', RateRuleType::Seasonal->priority())->sole();
        $this->assertSame('2027-07-01', $rule->start_date->toDateString());
        $this->assertSame('2027-08-31', $rule->end_date->toDateString());
        $this->assertSame(3, $rule->min_stay_override);
    }

    public function test_season_cannot_end_before_it_starts(): void
    {
        $roomType = $this->roomTypeWithBasePrice();

        $this->page($roomType)
            ->callAction('addSeasonal', [
                'name' => 'High season',
                'first_night' => '2027-08-31',
                'last_night' => '2027-07-01',
                'price_per_night' => '3500000',
            ])
            ->assertHasFormErrors(['last_night' => 'after_or_equal']);
    }

    public function test_special_date_is_added_and_shown_in_the_calendar(): void
    {
        $roomType = $this->roomTypeWithBasePrice('2500000');

        $this->page($roomType)
            ->callAction('addSpecialDates', ['name' => 'Anniversary', 'first_night' => '2026-11-20', 'price_per_night' => '4750000'])
            ->assertHasNoFormErrors()
            ->assertSeeHtml('data-date="2026-11-20"')
            ->assertSee('4,750,000');

        $rule = RateRule::query()->where('priority', RateRuleType::SpecialDates->priority())->sole();
        $this->assertSame('2026-11-20', $rule->end_date->toDateString());
    }

    public function test_price_is_edited_from_the_table(): void
    {
        $roomType = $this->roomTypeWithBasePrice();
        $weekend = app(SavePriceRule::class)->handle($roomType, PriceRuleData::weekend('Weekend', '3000000', [6]));

        // The name is not sent, so it must come from the form being filled with the current price.
        $this->page($roomType)
            ->callAction(TestAction::make('edit')->table($weekend), ['days' => [5, 6], 'price_per_night' => '3100000'])
            ->assertHasNoFormErrors();

        $weekend->refresh();
        $this->assertSame('Weekend', $weekend->name);
        $this->assertSame(16 | 32, $weekend->days_of_week_mask);
        $this->assertSame('3100000.00', $weekend->price_per_night);
    }

    public function test_base_price_cannot_be_deleted_but_other_prices_can(): void
    {
        $roomType = $this->roomTypeWithBasePrice();
        $base = RateRule::query()->sole();
        $weekend = app(SavePriceRule::class)->handle($roomType, PriceRuleData::weekend('Weekend', '3000000', [6]));

        $this->page($roomType)
            ->assertActionHidden(TestAction::make('delete')->table($base))
            ->callAction(TestAction::make('delete')->table($weekend));

        $this->assertModelExists($base);
        $this->assertModelMissing($weekend);
    }

    public function test_table_lists_only_prices_of_this_room_type(): void
    {
        $roomType = $this->roomTypeWithBasePrice();
        $this->roomTypeWithBasePrice();

        $this->page($roomType)
            ->assertCanSeeTableRecords($roomType->nonRefundableRatePlan()->sole()->rateRules()->get())
            ->assertCountTableRecords(1);
    }

    public function test_calendar_moves_between_months_and_marks_nights_without_a_price(): void
    {
        $roomType = $this->roomTypeWithBasePrice();
        app(SavePriceRule::class)->handle(
            $roomType,
            PriceRuleData::weekend('Weekend', '3000000', [6]),
        );
        RateRule::query()->where('priority', RateRuleType::Base->priority())->update(['end_date' => '2026-12-10']);

        $this->page($roomType)
            ->assertSee('November 2026')
            ->call('nextCalendarMonth')
            ->assertSee('December 2026')
            // Base price ends on 10 Dec; Saturdays keep the weekend price: 21 nights minus 3 Saturdays.
            ->assertSee('18 nights have no price')
            ->call('previousCalendarMonth')
            ->call('previousCalendarMonth')
            ->assertSee('October 2026');
    }

    private function page(RoomType $roomType): mixed
    {
        return Livewire::test(ManageRoomTypePricing::class, ['record' => $roomType->getRouteKey()]);
    }

    private function roomTypeWithBasePrice(string $price = '2500000'): RoomType
    {
        $roomType = RoomType::factory()->create();
        app(SetBasePrice::class)->handle($roomType, $price);

        return $roomType;
    }
}

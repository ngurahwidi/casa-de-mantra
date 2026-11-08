<?php

namespace Tests\Feature\Site;

use App\Livewire\Site\AvailabilitySearch;
use App\Models\AvailabilityBlock;
use App\Models\CancellationPolicy;
use App\Models\PropertyProfile;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\RoomType;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class AvailabilitySearchTest extends TestCase
{
    use RefreshDatabase;

    private RoomType $villa;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-01 00:00:00');

        PropertyProfile::factory()->create([
            'name' => 'Casa de Mantra',
            'timezone' => 'Asia/Makassar',
            'currency' => 'IDR',
            'whatsapp' => '+62 812-3456-7890',
        ]);

        $this->villa = RoomType::factory()->published()->create(['name' => 'Villa Aruna', 'max_adults' => 4, 'max_children' => 2]);
        $this->unit = Unit::factory()->for($this->villa)->create();

        $ratePlan = RatePlan::factory()->for($this->villa)->create([
            'cancellation_policy_id' => CancellationPolicy::factory()->create([
                'name' => CancellationPolicy::NON_REFUNDABLE,
                'description' => 'No refunds are available after a booking is confirmed.',
            ]),
        ]);

        RateRule::factory()->for($ratePlan)->create(['start_date' => '2026-01-01', 'price_per_night' => 2500000]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_picking_check_in_then_check_out_shows_the_price_total_and_policy(): void
    {
        $component = $this->widget()
            ->call('selectDate', '2026-12-03')
            ->assertSee('Now choose your check-out date.')
            ->call('selectDate', '2026-12-06');

        $component
            ->assertSet('checkIn', '2026-12-03')
            ->assertSet('checkOut', '2026-12-06')
            ->assertSee('3 Dec 2026')
            ->assertSee('6 Dec 2026')
            ->assertSee('Rp2.500.000 × 3 nights')
            ->assertSee('Rp7.500.000')
            ->assertSee('Non-Refundable')
            ->assertSee('No refunds are available after a booking is confirmed.')
            ->assertSee('Request to book');
    }

    public function test_changing_guests_beyond_capacity_is_capped_at_the_villa_maximum(): void
    {
        $this->widget()
            ->set('adults', 9)
            ->set('children', 7)
            ->assertSet('adults', 4)
            ->assertSet('children', 2);
    }

    public function test_calendar_marks_past_and_taken_dates_as_not_selectable(): void
    {
        AvailabilityBlock::factory()->for($this->unit)->create(['start_date' => '2026-11-10', 'end_date' => '2026-11-12']);

        $this->widget()
            ->assertSee('November 2026')
            ->assertSeeHtml('data-date="2026-11-01" data-state="available"')
            ->assertSeeHtml('data-date="2026-11-10" data-state="unavailable"')
            ->assertSeeHtml('data-date="2026-11-11" data-state="unavailable"')
            ->assertSeeHtml('data-date="2026-11-12" data-state="available"');
    }

    public function test_taken_date_cannot_be_check_in_but_can_be_the_check_out_right_before_it(): void
    {
        AvailabilityBlock::factory()->for($this->unit)->create(['start_date' => '2026-11-10', 'end_date' => '2026-11-12']);

        $this->widget()
            ->call('selectDate', '2026-11-10')
            ->assertSet('checkIn', '')
            ->call('selectDate', '2026-11-07')
            ->assertSeeHtml('data-date="2026-11-10" data-state="available"')
            ->assertSeeHtml('data-date="2026-11-11" data-state="unavailable"')
            ->call('selectDate', '2026-11-10')
            ->assertSet('checkIn', '2026-11-07')
            ->assertSet('checkOut', '2026-11-10');
    }

    public function test_date_past_a_taken_night_starts_a_new_check_in_instead_of_spanning_it(): void
    {
        AvailabilityBlock::factory()->for($this->unit)->create(['start_date' => '2026-11-10', 'end_date' => '2026-11-12']);

        $this->widget()
            ->call('selectDate', '2026-11-07')
            ->call('selectDate', '2026-11-14')
            ->assertSet('checkIn', '2026-11-14')
            ->assertSet('checkOut', '');
    }

    public function test_past_date_cannot_be_selected(): void
    {
        $this->widget()
            ->call('selectDate', '2026-10-31')
            ->assertSet('checkIn', '');
    }

    public function test_unavailable_dates_offer_the_nearest_stay_and_whatsapp(): void
    {
        AvailabilityBlock::factory()->for($this->unit)->create(['start_date' => '2026-12-04', 'end_date' => '2026-12-06']);

        $this->widget(['check_in' => '2026-12-04', 'check_out' => '2026-12-06'])
            ->assertSee('The selected dates are not available.')
            ->assertSee('Nearest available dates')
            ->assertSee('6 Dec 2026 – 8 Dec 2026')
            ->assertSee('Ask about other dates on WhatsApp')
            ->assertSeeHtml('https://wa.me/6281234567890?text=')
            ->assertDontSee('Request to book')
            ->call('useAlternative')
            ->assertSet('checkIn', '2026-12-06')
            ->assertSet('checkOut', '2026-12-08')
            ->assertSee('Rp5.000.000')
            ->assertSee('Request to book');
    }

    public function test_dates_and_guests_are_read_from_the_query_string(): void
    {
        $this->widget(['check_in' => '2026-12-03', 'check_out' => '2026-12-05', 'adults' => 3, 'children' => 1])
            ->assertSet('adults', 3)
            ->assertSet('children', 1)
            ->assertSet('month', '2026-12')
            ->assertSee('December 2026')
            ->assertSee('Rp5.000.000')
            ->assertSee('3 adults, 1 child');
    }

    public function test_malformed_or_reversed_dates_in_the_query_string_are_ignored(): void
    {
        $this->widget(['check_in' => '2026-12-05', 'check_out' => '2026-12-03', 'adults' => 12])
            ->assertSet('checkIn', '2026-12-05')
            ->assertSet('checkOut', '')
            ->assertSet('adults', 4);

        $this->widget(['check_in' => '2026-02-30', 'check_out' => 'tomorrow'])
            ->assertSet('checkIn', '')
            ->assertSet('checkOut', '');
    }

    public function test_calendar_cannot_move_before_this_month_or_after_the_booking_window(): void
    {
        $component = $this->widget()->call('previousMonth')->assertSet('month', '2026-11');

        for ($month = 0; $month < 14; $month++) {
            $component->call('nextMonth');
        }

        $component->assertSet('month', '2027-11');
    }

    public function test_villa_page_renders_the_widget(): void
    {
        $this->withoutVite();

        $this->get(route('villas.show', $this->villa->slug))
            ->assertOk()
            ->assertSeeHtml('wire:name="site.availability-search"');
    }

    public function test_villa_page_still_renders_with_query_values_of_the_wrong_type(): void
    {
        $this->withoutVite();

        $this->get(route('villas.show', $this->villa->slug).'?adults=abc&children[]=1&check_in[]=2026-12-03')
            ->assertOk()
            ->assertSee('Choose your dates to see the price for your stay.');
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function widget(array $query = []): Testable
    {
        return Livewire::withQueryParams($query)->test(AvailabilitySearch::class, ['villa' => $this->villa]);
    }
}

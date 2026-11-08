<?php

namespace Tests\Feature\Site;

use App\Livewire\Site\HomeSearch;
use App\Models\AvailabilityBlock;
use App\Models\CancellationPolicy;
use App\Models\PropertyProfile;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\RoomType;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class HomeSearchTest extends TestCase
{
    use RefreshDatabase;

    private RoomType $villa;

    private Unit $unit;

    private CancellationPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        Carbon::setTestNow('2026-11-01 00:00:00');

        PropertyProfile::factory()->create([
            'name' => 'Casa de Mantra',
            'timezone' => 'Asia/Makassar',
            'currency' => 'IDR',
            'whatsapp' => '+62 812-3456-7890',
        ]);

        $this->villa = RoomType::factory()->published()->create([
            'name' => 'Villa Aruna',
            'slug' => 'villa-aruna',
            'max_adults' => 4,
            'max_children' => 2,
        ]);
        $this->unit = Unit::factory()->for($this->villa)->create();

        $ratePlan = RatePlan::factory()->for($this->villa)->create([
            'cancellation_policy_id' => $this->policy = CancellationPolicy::factory()->create([
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

    public function test_home_page_renders_the_date_search_widget(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSeeHtml('wire:name="site.home-search"')
            ->assertSee('Check availability');
    }

    public function test_searching_redirects_to_the_villa_list_with_the_dates_and_guests(): void
    {
        Livewire::test(HomeSearch::class, ['maxGuests' => 6])
            ->set('checkIn', '2026-12-03')
            ->set('checkOut', '2026-12-06')
            ->set('guests', 4)
            ->call('search')
            ->assertRedirect(route('home', [
                'check_in' => '2026-12-03',
                'check_out' => '2026-12-06',
                'guests' => 4,
            ]).'#villas');
    }

    public function test_search_rejects_missing_reversed_and_out_of_window_dates(): void
    {
        $component = Livewire::test(HomeSearch::class, ['maxGuests' => 6]);

        $component->call('search')->assertHasErrors(['checkIn']);

        $component
            ->set('checkIn', '2026-12-06')
            ->set('checkOut', '2026-12-03')
            ->call('search')
            ->assertHasErrors(['checkOut' => 'Check-out must be after check-in.']);

        $component
            ->set('checkIn', '2026-10-30')
            ->set('checkOut', '2026-11-02')
            ->call('search')
            ->assertHasErrors(['checkIn' => 'Check-in cannot be in the past.']);

        $component
            ->set('checkIn', '2027-10-30')
            ->set('checkOut', '2027-11-02')
            ->call('search')
            ->assertHasErrors(['checkOut' => 'These dates are too far ahead to book yet.']);
    }

    public function test_guests_are_capped_at_the_largest_villa_capacity(): void
    {
        Livewire::test(HomeSearch::class, ['maxGuests' => 4])
            ->set('guests', 9)
            ->assertSet('guests', 4);
    }

    public function test_query_string_prefills_the_widget_and_shows_the_available_total(): void
    {
        $this->get(route('home', [
            'check_in' => '2026-12-03',
            'check_out' => '2026-12-06',
            'guests' => 4,
        ]))
            ->assertOk()
            ->assertSee('3 Dec 2026')
            ->assertSee('6 Dec 2026')
            ->assertSee('1 of 1 villa available')
            ->assertSee('Available')
            ->assertSee('Total · 3 nights')
            ->assertSee('Rp7.500.000')
            ->assertSee('Clear dates');
    }

    public function test_villa_link_keeps_the_selected_dates_and_party(): void
    {
        $this->get(route('home', [
            'check_in' => '2026-12-03',
            'check_out' => '2026-12-06',
            'guests' => 4,
        ]))
            ->assertOk()
            ->assertSee(route('villas.show', $this->villa->slug).'?check_in=2026-12-03&check_out=2026-12-06&adults=4&children=0');
    }

    public function test_taken_dates_mark_the_villa_unavailable_and_offer_the_nearest_stay(): void
    {
        AvailabilityBlock::factory()->for($this->unit)->create([
            'start_date' => '2026-12-04',
            'end_date' => '2026-12-06',
        ]);

        $this->get(route('home', [
            'check_in' => '2026-12-04',
            'check_out' => '2026-12-06',
            'guests' => 2,
        ]))
            ->assertOk()
            ->assertSee('0 of 1 villa available')
            ->assertSee('Not available')
            ->assertSee('Nearest stay: 6 Dec 2026 – 8 Dec 2026')
            ->assertSee('Ask us about other dates')
            ->assertSee('Rp5.000.000')
            ->assertSee(route('villas.show', $this->villa->slug).'?check_in=2026-12-06&check_out=2026-12-08&adults=2&children=0');
    }

    public function test_a_party_above_a_smaller_villas_capacity_is_unavailable_there(): void
    {
        $small = RoomType::factory()->published()->create([
            'name' => 'Villa Sora',
            'slug' => 'villa-sora',
            'max_adults' => 2,
            'max_children' => 0,
        ]);

        $ratePlan = RatePlan::factory()->for($small)->create([
            'cancellation_policy_id' => $this->policy->id,
        ]);

        RateRule::factory()->for($ratePlan)->create(['start_date' => '2026-01-01', 'price_per_night' => 3000000]);

        $this->get(route('home', [
            'check_in' => '2026-12-03',
            'check_out' => '2026-12-06',
            'guests' => 4,
        ]))
            ->assertOk()
            ->assertSee('1 of 2 villas available')
            ->assertSee('This stay allows up to 2 adults and 0 children.');
    }

    public function test_invalid_query_dates_are_ignored_so_the_page_still_opens(): void
    {
        $this->get(route('home', [
            'check_in' => '2026-12-06',
            'check_out' => '2026-12-03',
            'guests' => 4,
        ]))
            ->assertOk()
            ->assertDontSee('Clear dates')
            ->assertDontSee('villa available')
            ->assertSee('Rates are per night and subject to availability.');
    }

    public function test_a_query_string_beyond_the_booking_window_is_ignored(): void
    {
        $this->get(route('home', [
            'check_in' => '2026-10-30',
            'check_out' => '2026-11-02',
            'guests' => 2,
        ]))
            ->assertOk()
            ->assertDontSee('Clear dates');
    }
}

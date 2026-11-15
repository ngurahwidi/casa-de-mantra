<?php

namespace Tests\Browser;

use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Models\CancellationPolicy;
use App\Models\PropertyProfile;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\Unit;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * A guest books the villa in a real browser: picks dates in the Livewire calendar, follows the
 * price to the booking form and sends the request.
 *
 * The web server runs on the real clock, so the stay is placed in next month.
 */
class BookingRequestTest extends DuskTestCase
{
    use DatabaseTruncation;

    public function test_guest_picks_dates_in_the_calendar_and_sends_a_booking_request(): void
    {
        PropertyProfile::factory()->create(['name' => 'Casa de Mantra', 'timezone' => 'Asia/Makassar', 'currency' => 'IDR']);
        $villa = RoomType::factory()->published()->create(['name' => 'Villa Aruna', 'slug' => 'villa-aruna', 'max_adults' => 4, 'max_children' => 2]);
        Unit::factory()->for($villa)->create();
        $ratePlan = RatePlan::factory()->for($villa)->create([
            'cancellation_policy_id' => CancellationPolicy::factory()->create(['name' => CancellationPolicy::NON_REFUNDABLE]),
        ]);
        RateRule::factory()->for($ratePlan)->create(['start_date' => now()->startOfYear()->toDateString(), 'price_per_night' => 2500000]);

        $nextMonth = now('Asia/Makassar')->addMonthNoOverflow()->startOfMonth();
        $checkIn = $nextMonth->copy()->day(10)->toDateString();
        $checkOut = $nextMonth->copy()->day(13)->toDateString();

        $this->browse(function (Browser $browser) use ($checkIn, $checkOut): void {
            $browser->visit(route('villas.show', ['slug' => 'villa-aruna'], false))
                ->assertSee('Villa Aruna')
                ->click('button[aria-label="Next month"]')
                ->waitFor("button[data-date=\"{$checkIn}\"]")
                ->click("button[data-date=\"{$checkIn}\"]")
                ->waitFor("button[data-date=\"{$checkIn}\"][data-state=\"check-in\"]")
                ->click("button[data-date=\"{$checkOut}\"]")
                ->waitForText('Rp7.500.000')
                ->assertSee('Rp2.500.000 × 3 nights')
                ->clickLink('Request to book')
                ->waitForText('2 · Your details')
                ->type('full_name', 'Jane Doe')
                ->type('email', 'jane@example.com')
                ->type('phone', '+62 812 3456 7890')
                ->type('country', 'Australia')
                ->check('policy_accepted')
                ->press('Send request')
                ->waitForText('not a confirmed booking')
                ->assertPathBeginsWith('/booking/success/');
        });

        $reservation = Reservation::query()->sole();
        $this->assertSame(ReservationStatus::Pending, $reservation->status);
        $this->assertSame(ReservationSource::DirectWeb, $reservation->source);
        $this->assertSame($checkIn, $reservation->check_in->toDateString());
        $this->assertSame($checkOut, $reservation->check_out->toDateString());
        $this->assertSame('jane@example.com', $reservation->guest_email);
        $this->assertSame('7500000.00', $reservation->total);
    }
}

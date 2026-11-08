<?php

namespace Tests\Feature\Site;

use App\Models\AvailabilityBlock;
use App\Models\CancellationPolicy;
use App\Models\PropertyProfile;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BookingRequestTest extends TestCase
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
            'pending_reminder_hours' => 12,
        ]);

        $this->villa = RoomType::factory()->published()->create([
            'name' => 'Villa Aruna',
            'slug' => 'villa-aruna',
            'max_adults' => 4,
            'max_children' => 2,
        ]);
        $this->unit = Unit::factory()->for($this->villa)->create();

        $this->policy = CancellationPolicy::factory()->create([
            'name' => CancellationPolicy::NON_REFUNDABLE,
            'description' => 'No refunds are available after a booking is confirmed.',
        ]);

        $ratePlan = RatePlan::factory()->for($this->villa)->create([
            'cancellation_policy_id' => $this->policy->id,
        ]);

        RateRule::factory()->for($ratePlan)->create([
            'start_date' => '2026-01-01',
            'price_per_night' => 2500000,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_booking_page_shows_the_stay_summary_and_guest_form(): void
    {
        $this->get($this->bookingUrl())
            ->assertOk()
            ->assertSee('Request to book')
            ->assertSee('Villa Aruna')
            ->assertSee('3 Dec 2026')
            ->assertSee('6 Dec 2026')
            ->assertSee('2 adults, 1 child')
            ->assertSee('Rp2.500.000 × 3 nights')
            ->assertSee('Rp7.500.000')
            ->assertSee('Non-Refundable')
            ->assertSee('No refunds are available after a booking is confirmed.')
            ->assertSee('2 · Your details')
            ->assertSee('3 · Before you send')
            ->assertSee('Send request')
            ->assertSeeHtml('name="check_in" value="2026-12-03"')
            ->assertSeeHtml('name="check_out" value="2026-12-06"')
            ->assertSeeHtml('name="adults" value="2"')
            ->assertSeeHtml('name="children" value="1"');
    }

    public function test_booking_page_asks_for_dates_when_none_are_given(): void
    {
        $this->get(route('booking.create', ['villa' => $this->villa->slug]))
            ->assertOk()
            ->assertSee('Choose your check-in and check-out dates')
            ->assertDontSee('2 · Your details');
    }

    public function test_booking_page_reports_unavailable_dates_and_the_nearest_stay(): void
    {
        AvailabilityBlock::factory()->for($this->unit)->create([
            'start_date' => '2026-12-04',
            'end_date' => '2026-12-06',
        ]);

        $this->get($this->bookingUrl())
            ->assertOk()
            ->assertSee('The selected dates are not available.')
            ->assertSee('1 Dec 2026 – 4 Dec 2026')
            ->assertDontSee('2 · Your details');
    }

    public function test_unpublished_villas_cannot_be_booked(): void
    {
        $draft = RoomType::factory()->create(['slug' => 'draft-villa']);

        $this->get(route('booking.create', [
            'villa' => $draft->slug,
            'check_in' => '2026-12-03',
            'check_out' => '2026-12-06',
            'adults' => 2,
            'children' => 0,
        ]))->assertNotFound();
    }

    public function test_a_valid_request_is_stored_as_pending_and_redirects_to_the_success_page(): void
    {
        $response = $this->post(route('booking.store', ['villa' => $this->villa->slug]), $this->payload());

        $reservation = Reservation::query()->sole();
        $code = $reservation->code;

        $response->assertRedirect(route('booking.success', ['code' => $code]));

        $this->assertSame('pending', $reservation->status->value);
        $this->assertSame('direct_web', $reservation->source->value);
        $this->assertSame('Jane Doe', $reservation->guest_full_name);
        $this->assertSame('jane@example.com', $reservation->guest_email);
        $this->assertSame('+62 812 3456 7890', $reservation->guest_phone);
        $this->assertSame('Australia', $reservation->guest_country);
        $this->assertSame('2026-12-03', $reservation->check_in->toDateString());
        $this->assertSame('2026-12-06', $reservation->check_out->toDateString());
        $this->assertSame(2, $reservation->adults);
        $this->assertSame(1, $reservation->children);
        $this->assertSame('Late arrival around 22:00.', $reservation->special_requests);
        $this->assertSame('7500000.00', $reservation->total);
        $this->assertDatabaseCount('reservation_nights', 3);
        $this->assertDatabaseHas('outbox_events', ['type' => 'reservation.created']);

        $this->get(route('booking.success', ['code' => $code]))
            ->assertOk()
            ->assertSee($code)
            ->assertSee('not a confirmed booking')
            ->assertSee('12 hours')
            ->assertSee('Villa Aruna')
            ->assertSee('3 Dec 2026')
            ->assertSee('6 Dec 2026')
            ->assertSee('Rp7.500.000')
            ->assertDontSee('jane@example.com');
    }

    public function test_missing_guest_details_and_consent_are_rejected(): void
    {
        $this->post(route('booking.store', ['villa' => $this->villa->slug]), [])
            ->assertSessionHasErrors([
                'check_in',
                'check_out',
                'adults',
                'full_name',
                'email',
                'phone',
                'policy_accepted',
            ]);

        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_the_email_must_be_valid_and_the_policy_must_be_accepted(): void
    {
        $this->post(route('booking.store', ['villa' => $this->villa->slug]), $this->payload([
            'email' => 'not-an-email',
            'policy_accepted' => null,
        ]))->assertSessionHasErrors([
            'email',
            'policy_accepted' => 'Please agree to the non-refundable policy before sending your request.',
        ]);

        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_honeypot_field_rejects_the_request(): void
    {
        $this->post(route('booking.store', ['villa' => $this->villa->slug]), $this->payload([
            'website' => 'https://spam.example',
        ]))->assertSessionHasErrors([
            'website' => 'This request could not be processed.',
        ]);

        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_booking_requests_are_rate_limited_per_ip(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('booking.store', ['villa' => $this->villa->slug]), []);
        }

        $this->post(route('booking.store', ['villa' => $this->villa->slug]), [])
            ->assertStatus(429);
    }

    public function test_turnstile_is_required_when_it_is_configured(): void
    {
        config(['services.turnstile.secret_key' => 'secret-key']);

        Http::fake([
            'https://challenges.cloudflare.com/*' => Http::response(['success' => false]),
        ]);

        $this->post(route('booking.store', ['villa' => $this->villa->slug]), $this->payload([
            'cf-turnstile-response' => 'invalid-token',
        ]))->assertSessionHasErrors('cf-turnstile-response');

        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_a_verified_turnstile_token_lets_the_request_through(): void
    {
        config(['services.turnstile.secret_key' => 'secret-key']);

        Http::fake([
            'https://challenges.cloudflare.com/*' => Http::response(['success' => true]),
        ]);

        $this->post(route('booking.store', ['villa' => $this->villa->slug]), $this->payload([
            'cf-turnstile-response' => 'valid-token',
        ]))->assertRedirect();

        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_turnstile_is_skipped_when_it_is_not_configured(): void
    {
        $this->post(route('booking.store', ['villa' => $this->villa->slug]), $this->payload())
            ->assertRedirect();

        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_turnstile_widget_is_rendered_when_it_is_configured(): void
    {
        config(['services.turnstile.site_key' => 'site-key']);

        $this->get($this->bookingUrl())
            ->assertOk()
            ->assertSee('cf-turnstile')
            ->assertSee('site-key')
            ->assertSee('https://challenges.cloudflare.com/turnstile/v0/api.js');
    }

    public function test_dates_taken_after_the_page_was_opened_are_rejected_on_submit(): void
    {
        $this->get($this->bookingUrl())->assertOk();

        AvailabilityBlock::factory()->for($this->unit)->create([
            'start_date' => '2026-12-04',
            'end_date' => '2026-12-06',
        ]);

        $this->post(route('booking.store', ['villa' => $this->villa->slug]), $this->payload())
            ->assertRedirect($this->bookingUrl())
            ->assertSessionHasErrors('stay');

        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_success_page_is_not_found_for_an_unknown_code(): void
    {
        $this->get(route('booking.success', ['code' => 'RSV-ZZZZZZZZ']))
            ->assertNotFound();
    }

    public function test_success_page_finds_the_code_case_insensitively(): void
    {
        $this->post(route('booking.store', ['villa' => $this->villa->slug]), $this->payload());

        $code = Reservation::query()->sole()->code;

        $this->get(route('booking.success', ['code' => strtolower($code)]))
            ->assertOk()
            ->assertSee($code);
    }

    public function test_villa_page_links_to_the_booking_form_with_the_selected_stay(): void
    {
        $this->get(route('villas.show', [
            'slug' => $this->villa->slug,
            'check_in' => '2026-12-03',
            'check_out' => '2026-12-06',
            'adults' => 2,
            'children' => 1,
        ]))->assertOk()
            ->assertSee(route('booking.create', [
                'villa' => $this->villa->slug,
                'check_in' => '2026-12-03',
                'check_out' => '2026-12-06',
                'adults' => 2,
                'children' => 1,
            ]));
    }

    private function bookingUrl(): string
    {
        return route('booking.create', [
            'villa' => $this->villa->slug,
            'check_in' => '2026-12-03',
            'check_out' => '2026-12-06',
            'adults' => 2,
            'children' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'check_in' => '2026-12-03',
            'check_out' => '2026-12-06',
            'adults' => 2,
            'children' => 1,
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '+62 812 3456 7890',
            'country' => 'Australia',
            'special_requests' => 'Late arrival around 22:00.',
            'policy_accepted' => '1',
        ], $overrides);
    }
}

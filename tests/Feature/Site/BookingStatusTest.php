<?php

namespace Tests\Feature\Site;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\CancellationPolicy;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\PropertyProfile;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationNight;
use App\Models\ReservationRoom;
use App\Models\RoomType;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BookingStatusTest extends TestCase
{
    use RefreshDatabase;

    private RoomType $villa;

    private Unit $unit;

    private RatePlan $ratePlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        Carbon::setTestNow('2026-12-01 00:00:00');

        PropertyProfile::factory()->create([
            'name' => 'Casa de Mantra',
            'timezone' => 'Asia/Makassar',
            'currency' => 'IDR',
            'whatsapp' => '+62 812-3456-7890',
            'email' => 'stay@example.com',
            'address' => 'Jalan Pantai, Bali',
            'checkin_time' => '14:00:00',
            'checkout_time' => '12:00:00',
            'pending_reminder_hours' => 12,
            'bank_account_info' => [
                'bank' => 'Bank Example',
                'account_name' => 'Casa Demo',
                'account_number' => '1234567890',
                'swift_code' => 'BEXAIDJA',
            ],
        ]);

        $this->villa = RoomType::factory()->published()->create([
            'name' => 'Villa Aruna',
            'slug' => 'villa-aruna',
            'max_adults' => 4,
            'max_children' => 2,
        ]);
        $this->unit = Unit::factory()->for($this->villa)->create();

        $policy = CancellationPolicy::factory()->create([
            'name' => CancellationPolicy::NON_REFUNDABLE,
            'description' => 'No refunds are available after a booking is confirmed.',
        ]);

        $this->ratePlan = RatePlan::factory()->for($this->villa)->create([
            'cancellation_policy_id' => $policy->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_status_page_shows_the_lookup_form(): void
    {
        $this->get(route('booking.status'))
            ->assertOk()
            ->assertSee('Booking status')
            ->assertSee('Find your booking')
            ->assertSee('Reference code')
            ->assertSee('Email address')
            ->assertSee('Check status')
            ->assertSee('<meta name="robots" content="noindex,nofollow">', false);
    }

    public function test_the_code_query_parameter_prefills_the_form(): void
    {
        $this->get(route('booking.status', ['code' => 'RSV-ABCDEFGH']))
            ->assertOk()
            ->assertSee('value="RSV-ABCDEFGH"', false);
    }

    public function test_a_matching_code_and_email_opens_the_reservation(): void
    {
        $reservation = $this->reservation();

        $this->post(route('booking.status.lookup'), [
            'code' => strtolower($reservation->code),
            'email' => 'Jane@Example.com',
        ])->assertRedirect(route('booking.status'));

        $this->get(route('booking.status'))
            ->assertOk()
            ->assertSee($reservation->code)
            ->assertSee('Pending')
            ->assertSee('Villa Aruna')
            ->assertSee('24 Dec 2026')
            ->assertSee('27 Dec 2026')
            ->assertSee('2 adults')
            ->assertSee('Rp4.500.000')
            ->assertSee('We are checking your dates')
            ->assertSee('within about 12 hours')
            ->assertSee('What happens next');
    }

    public function test_a_wrong_email_or_code_does_not_reveal_a_booking(): void
    {
        $reservation = $this->reservation();

        $this->post(route('booking.status.lookup'), [
            'code' => $reservation->code,
            'email' => 'someone-else@example.com',
        ])->assertRedirect(route('booking.status'))
            ->assertSessionHasErrors([
                'lookup' => 'We could not find a booking with that code and email address. Please check both and try again.',
            ]);

        $this->followingRedirects()
            ->post(route('booking.status.lookup'), [
                'code' => $reservation->code,
                'email' => 'someone-else@example.com',
            ])
            ->assertOk()
            ->assertSee('We could not find a booking')
            ->assertDontSee('We are checking your dates');
    }

    public function test_an_unknown_code_gets_the_same_generic_error(): void
    {
        $this->post(route('booking.status.lookup'), [
            'code' => 'RSV-ZZZZZZZZ',
            'email' => 'jane@example.com',
        ])->assertSessionHasErrors([
            'lookup' => 'We could not find a booking with that code and email address. Please check both and try again.',
        ]);
    }

    public function test_code_and_email_are_required(): void
    {
        $this->post(route('booking.status.lookup'), [])
            ->assertSessionHasErrors(['code', 'email']);
    }

    public function test_lookups_are_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('booking.status.lookup'), [
                'code' => 'RSV-ZZZZZZZZ',
                'email' => 'nobody@example.com',
            ])->assertRedirect(route('booking.status'));
        }

        $this->post(route('booking.status.lookup'), [
            'code' => 'RSV-ZZZZZZZZ',
            'email' => 'nobody@example.com',
        ])->assertStatus(429);
    }

    public function test_forgetting_a_booking_closes_the_status_page(): void
    {
        $reservation = $this->reservation();
        $this->open($reservation);

        $this->post(route('booking.status.forget'))
            ->assertRedirect(route('booking.status'));

        $this->get(route('booking.status'))
            ->assertOk()
            ->assertSee('Find your booking')
            ->assertDontSee($reservation->code);
    }

    public function test_awaiting_payment_shows_the_amount_deadline_bank_details_and_upload_form(): void
    {
        $reservation = $this->reservation(ReservationStatus::AwaitingPayment, [
            'expires_at' => now()->addDay(),
        ]);
        $this->open($reservation);

        $this->get(route('booking.status'))
            ->assertOk()
            ->assertSee('Awaiting payment')
            ->assertSee('Your dates are being held')
            ->assertSee('Rp4.500.000')
            ->assertSee('2 Dec 2026, 08:00')
            ->assertSee('Bank Example')
            ->assertSee('Casa Demo')
            ->assertSee('1234567890')
            ->assertSee('BEXAIDJA')
            ->assertSee('Upload transfer proof')
            ->assertSee(route('booking.status.proof'));
    }

    public function test_a_guest_can_upload_a_transfer_proof_which_is_kept_privately(): void
    {
        Storage::fake(Payment::PROOF_DISK);

        $reservation = $this->reservation(ReservationStatus::AwaitingPayment, [
            'expires_at' => now()->addDay(),
        ]);
        $this->open($reservation);

        $this->post(route('booking.status.proof'), [
            'proof' => UploadedFile::fake()->image('receipt.jpg'),
        ])->assertRedirect(route('booking.status'))
            ->assertSessionHas('status', 'Thank you. We received your transfer proof and will verify it shortly.');

        $payment = $reservation->payments()->sole();

        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(PaymentMethod::BankTransfer, $payment->method);
        $this->assertSame('4500000.00', $payment->amount);
        $this->assertNull($payment->verified_by);
        $this->assertNull($payment->paid_at);
        $this->assertStringStartsWith(
            Payment::PROOF_DIRECTORY.'/'.$reservation->code.'/',
            (string) $payment->proof_url,
        );
        Storage::disk(Payment::PROOF_DISK)->assertExists((string) $payment->proof_url);

        $reservation->refresh();
        $this->assertSame(ReservationStatus::AwaitingPayment, $reservation->status);
        $this->assertSame(ReservationPaymentStatus::Unpaid, $reservation->payment_status);
        $this->assertDatabaseHas('outbox_events', ['type' => 'payment.proof_uploaded']);

        $this->get(route('booking.status'))
            ->assertSee('We received your transfer proof');
    }

    public function test_a_waiting_proof_is_still_shown_after_the_page_is_reloaded(): void
    {
        $reservation = $this->reservation(ReservationStatus::AwaitingPayment, ['expires_at' => now()->addDay()]);
        Payment::factory()->for($reservation)->create();
        $this->open($reservation);

        $this->get(route('booking.status'))
            ->assertOk()
            ->assertSee('We have your transfer proof')
            ->assertSee('Upload another proof')
            ->assertDontSee('Upload transfer proof')
            ->assertDontSee('We could not verify');
    }

    public function test_a_rejected_proof_asks_the_guest_to_upload_again(): void
    {
        $reservation = $this->reservation(ReservationStatus::AwaitingPayment, ['expires_at' => now()->addDay()]);
        Payment::factory()->for($reservation)->create(['status' => PaymentStatus::Failed]);
        $this->open($reservation);

        $this->get(route('booking.status'))
            ->assertOk()
            ->assertSee('We could not verify your last transfer proof')
            ->assertSee('Upload transfer proof')
            ->assertDontSee('We have your transfer proof');
    }

    public function test_a_rejected_proof_is_no_longer_mentioned_after_a_later_payment(): void
    {
        $reservation = $this->reservation(ReservationStatus::AwaitingPayment, ['expires_at' => now()->addDay()]);
        Payment::factory()->for($reservation)->create(['status' => PaymentStatus::Failed, 'updated_at' => now()->subHour()]);
        Payment::factory()->paid()->for($reservation)->create(['amount' => 1000000]);
        $this->open($reservation);

        $this->get(route('booking.status'))
            ->assertOk()
            ->assertDontSee('We could not verify');
    }

    public function test_a_passed_deadline_is_only_raised_without_a_proof_waiting(): void
    {
        $reservation = $this->reservation(ReservationStatus::AwaitingPayment, ['expires_at' => now()->subHour()]);
        $this->open($reservation);

        $this->get(route('booking.status'))->assertSee('The payment window has closed');

        Payment::factory()->for($reservation)->create();

        $this->get(route('booking.status'))->assertDontSee('The payment window has closed');
    }

    public function test_the_proof_must_be_an_allowed_file_type(): void
    {
        Storage::fake(Payment::PROOF_DISK);

        $reservation = $this->reservation(ReservationStatus::AwaitingPayment);
        $this->open($reservation);

        $this->post(route('booking.status.proof'), [
            'proof' => UploadedFile::fake()->create('receipt.txt', 10, 'text/plain'),
        ])->assertSessionHasErrors([
            'proof' => 'Upload a JPG, PNG, WebP or PDF file.',
        ]);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_the_proof_must_be_five_megabytes_or_smaller(): void
    {
        Storage::fake(Payment::PROOF_DISK);

        $reservation = $this->reservation(ReservationStatus::AwaitingPayment);
        $this->open($reservation);

        $this->post(route('booking.status.proof'), [
            'proof' => UploadedFile::fake()->create('receipt.pdf', 6000, 'application/pdf'),
        ])->assertSessionHasErrors([
            'proof' => 'The file must be 5 MB or smaller.',
        ]);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_proof_upload_is_refused_when_the_booking_is_not_awaiting_payment(): void
    {
        $reservation = $this->reservation();
        $this->open($reservation);

        $this->post(route('booking.status.proof'), [
            'proof' => UploadedFile::fake()->image('receipt.jpg'),
        ])->assertSessionHasErrors([
            'proof' => 'This booking is not waiting for a transfer proof. Please check the status or contact us.',
        ]);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_proof_upload_requires_an_open_booking(): void
    {
        $this->post(route('booking.status.proof'), [
            'proof' => UploadedFile::fake()->image('receipt.jpg'),
        ])->assertSessionHasErrors([
            'lookup' => 'Please enter your booking code and email address again.',
        ]);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_uploads_are_rate_limited(): void
    {
        $reservation = $this->reservation(ReservationStatus::AwaitingPayment);
        $this->open($reservation);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('booking.status.proof'), []);
        }

        $this->post(route('booking.status.proof'), [])->assertStatus(429);
    }

    public function test_a_confirmed_booking_shows_the_arrival_details_and_no_upload_form(): void
    {
        $reservation = $this->reservation(ReservationStatus::Confirmed, [
            'payment_status' => ReservationPaymentStatus::Paid,
            'confirmed_at' => now(),
        ]);
        Payment::factory()->paid()->create([
            'reservation_id' => $reservation->id,
            'amount' => $reservation->total,
        ]);
        $this->open($reservation);

        $this->get(route('booking.status'))
            ->assertOk()
            ->assertSee('Confirmed')
            ->assertSee('Your booking is confirmed')
            ->assertSee('Check-in is from 14:00')
            ->assertSee('Jalan Pantai, Bali')
            ->assertSee('Paid')
            ->assertDontSee('Upload transfer proof')
            ->assertDontSee('Balance due');
    }

    public function test_an_expired_booking_explains_the_dates_were_released(): void
    {
        $reservation = $this->reservation(ReservationStatus::Expired, [
            'expires_at' => now()->subDay(),
        ]);
        $this->open($reservation);

        $this->get(route('booking.status'))
            ->assertOk()
            ->assertSee('Expired')
            ->assertSee('The payment window has passed')
            ->assertSee('released')
            ->assertSee(route('villas.show', $this->villa->slug));
    }

    public function test_a_rejected_booking_shows_the_reason(): void
    {
        $reservation = $this->reservation(ReservationStatus::Rejected, [
            'reject_reason' => 'These dates were already taken.',
        ]);
        $this->open($reservation);

        $this->get(route('booking.status'))
            ->assertOk()
            ->assertSee('We could not confirm this request')
            ->assertSee('These dates were already taken.');
    }

    public function test_a_cancelled_booking_explains_the_non_refundable_rate(): void
    {
        $reservation = $this->reservation(ReservationStatus::Cancelled);
        $this->open($reservation);

        $this->get(route('booking.status'))
            ->assertOk()
            ->assertSee('This booking was cancelled')
            ->assertSee('non-refundable');
    }

    private function open(Reservation $reservation): void
    {
        $this->post(route('booking.status.lookup'), [
            'code' => $reservation->code,
            'email' => $reservation->guest_email,
        ])->assertRedirect(route('booking.status'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function reservation(ReservationStatus $status = ReservationStatus::Pending, array $overrides = []): Reservation
    {
        $guest = Guest::factory()->create([
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '+62 812 3456 7890',
            'country' => 'Australia',
        ]);

        $reservation = Reservation::factory()->create(array_merge([
            'guest_id' => $guest->id,
            'status' => $status,
        ], $overrides));

        $room = ReservationRoom::factory()->create([
            'reservation_id' => $reservation->id,
            'room_type_id' => $this->villa->id,
            'unit_id' => $this->unit->id,
            'rate_plan_id' => $this->ratePlan->id,
            'total_price' => $reservation->total,
        ]);

        foreach (['2026-12-24', '2026-12-25', '2026-12-26'] as $night) {
            ReservationNight::factory()->create([
                'reservation_room_id' => $room->id,
                'night_date' => $night,
                'price' => 1500000,
            ]);
        }

        return $reservation;
    }
}

<?php

namespace Tests\Feature;

use App\Enums\NotificationLogStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationStatus;
use App\Filament\Resources\Reservations\Pages\ViewReservation;
use App\Livewire\Site\AvailabilitySearch;
use App\Mail\ReservationNotificationMail;
use App\Models\CancellationPolicy;
use App\Models\NotificationLog;
use App\Models\OutboxEvent;
use App\Models\Payment;
use App\Models\PropertyProfile;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Reservation;
use App\Models\ReservationStatusLog;
use App\Models\RoomType;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * One website booking from search to completed stay, through the same entry points the guest
 * and the admin use: the search widget, the booking form, the admin reservation page, the
 * status page and the scheduled command. Each step's rules are covered by its own tests; this
 * one shows the steps fit together.
 */
class BookingFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_website_request_goes_from_search_to_a_completed_stay(): void
    {
        Carbon::setTestNow('2026-11-01 00:00:00');
        Storage::fake(Payment::PROOF_DISK);

        PropertyProfile::factory()->create([
            'name' => 'Casa de Mantra',
            'email' => 'stay@example.com',
            'timezone' => 'Asia/Makassar',
            'currency' => 'IDR',
            'payment_window_hours' => 24,
            'bank_account_info' => ['bank' => 'Bank Example', 'account_name' => 'Casa Demo', 'account_number' => '1234567890'],
        ]);
        $this->seed(NotificationTemplateSeeder::class);
        $villa = RoomType::factory()->published()->create(['name' => 'Villa Aruna', 'slug' => 'villa-aruna', 'max_adults' => 4, 'max_children' => 2]);
        $unit = Unit::factory()->for($villa)->create();
        $ratePlan = RatePlan::factory()->for($villa)->create([
            'cancellation_policy_id' => CancellationPolicy::factory()->create(['name' => CancellationPolicy::NON_REFUNDABLE]),
        ]);
        RateRule::factory()->for($ratePlan)->create(['start_date' => '2026-01-01', 'price_per_night' => 2500000]);
        $owner = User::factory()->owner()->create();
        Mail::fake();

        // The guest picks 3–6 Dec in the search widget, which links to the booking form.
        $bookingUrl = route('booking.create', ['villa' => 'villa-aruna', 'check_in' => '2026-12-03', 'check_out' => '2026-12-06', 'adults' => 2, 'children' => 0]);

        Livewire::test(AvailabilitySearch::class, ['villa' => $villa])
            ->call('selectDate', '2026-12-03')
            ->call('selectDate', '2026-12-06')
            ->assertSee('Rp7.500.000')
            ->assertSee($bookingUrl);

        // ...and sends the request.
        $this->get($bookingUrl)->assertOk()->assertSee('Send request');

        $response = $this->post(route('booking.store', ['villa' => 'villa-aruna']), [
            'check_in' => '2026-12-03',
            'check_out' => '2026-12-06',
            'adults' => 2,
            'children' => 0,
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '+62 812 3456 7890',
            'country' => 'Australia',
            'policy_accepted' => '1',
        ]);

        $reservation = Reservation::query()->sole();
        $response->assertRedirect(route('booking.success', ['code' => $reservation->code]));
        $this->assertSame(ReservationStatus::Pending, $reservation->status);
        $this->assertSame('7500000.00', $reservation->total);

        // Two hours later the owner approves it and asks for payment.
        Carbon::setTestNow('2026-11-01 02:00:00');
        $this->actingAs($owner);

        Livewire::test(ViewReservation::class, ['record' => $reservation->getRouteKey()])
            ->callAction('approve')
            ->assertNotified('Reservation approved');

        $reservation->refresh();
        $this->assertSame(ReservationStatus::AwaitingPayment, $reservation->status);
        $this->assertSame('2026-11-02 02:00:00', $reservation->expires_at?->toDateTimeString());
        $this->assertSame($unit->id, $reservation->reservationRooms()->sole()->unit_id);

        // The guest opens the status page, sees what to pay and uploads the transfer receipt.
        Carbon::setTestNow('2026-11-01 05:00:00');

        $this->post(route('booking.status.lookup'), ['code' => $reservation->code, 'email' => 'jane@example.com'])
            ->assertRedirect(route('booking.status'));
        $this->get(route('booking.status'))
            ->assertOk()
            ->assertSee('Rp7.500.000')
            ->assertSee('1234567890')
            ->assertSee('Upload transfer proof');
        $this->post(route('booking.status.proof'), ['proof' => UploadedFile::fake()->image('receipt.jpg')])
            ->assertRedirect(route('booking.status'));

        $proof = $reservation->payments()->sole();
        $this->assertSame(PaymentStatus::Pending, $proof->status);

        // The owner checks the bank account and records the payment against that receipt.
        Carbon::setTestNow('2026-11-01 06:00:00');

        Livewire::test(ViewReservation::class, ['record' => $reservation->getRouteKey()])
            ->callAction('recordPayment', ['amount' => '7500000', 'method' => PaymentMethod::BankTransfer->value])
            ->assertHasNoFormErrors()
            ->assertNotified('Payment recorded, reservation confirmed');

        $reservation->refresh();
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertSame(ReservationPaymentStatus::Paid, $reservation->payment_status);
        $this->assertSame('2026-11-01 06:00:00', $reservation->confirmed_at?->toDateTimeString());
        $this->assertSame([$proof->id], $reservation->payments()->pluck('id')->all());
        $this->assertSame(PaymentStatus::Paid, $proof->refresh()->status);
        Storage::disk(Payment::PROOF_DISK)->assertExists((string) $proof->proof_url);

        $this->get(route('booking.status'))
            ->assertOk()
            ->assertSee('Your booking is confirmed')
            ->assertDontSee('Upload transfer proof');

        // The scheduled job completes the stay once check-out day (6 Dec, Bali time) has passed.
        Carbon::setTestNow('2026-12-06 15:59:00');
        $this->artisan('reservations:complete')->assertSuccessful();
        $this->assertSame(ReservationStatus::Confirmed, $reservation->refresh()->status);

        Carbon::setTestNow('2026-12-06 16:00:00');
        $this->artisan('reservations:complete')->assertSuccessful();
        $this->assertSame(ReservationStatus::Completed, $reservation->refresh()->status);

        $this->assertSame(
            [
                [null, 'pending', null],
                ['pending', 'awaiting_payment', $owner->id],
                ['awaiting_payment', 'confirmed', $owner->id],
                ['confirmed', 'completed', null],
            ],
            ReservationStatusLog::query()->whereBelongsTo($reservation)->orderBy('created_at')->get()
                ->map(fn (ReservationStatusLog $log): array => [$log->from_status?->value, $log->to_status->value, $log->admin_user_id])
                ->all(),
        );

        $this->assertSame(
            ['reservation.created', 'reservation.awaiting_payment', 'payment.proof_uploaded', 'payment.recorded', 'reservation.confirmed', 'reservation.completed'],
            OutboxEvent::query()->orderBy('created_at')->orderBy('id')->pluck('type')->all(),
        );

        $this->assertEqualsCanonicalizing(
            ['reservation_received', 'admin_new_request', 'reservation_awaiting_payment', 'reservation_confirmed'],
            NotificationLog::query()->whereBelongsTo($reservation)->where('status', NotificationLogStatus::Sent)->pluck('template')->all(),
        );
        $this->assertSame(4, NotificationLog::query()->count());
        Mail::assertSent(ReservationNotificationMail::class, fn (ReservationNotificationMail $mail): bool => $mail->hasTo('stay@example.com'));
        Mail::assertSent(ReservationNotificationMail::class, fn (ReservationNotificationMail $mail): bool => $mail->hasTo('jane@example.com'));
        Mail::assertSentCount(4);
    }
}

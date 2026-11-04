<?php

namespace Tests\Feature\Admin;

use App\Actions\Pricing\SetBasePrice;
use App\Enums\PaymentMethod;
use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Filament\Resources\Reservations\Pages\ListReservations;
use App\Filament\Resources\Reservations\Pages\ViewReservation;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\AvailabilityBlock;
use App\Models\CancellationPolicy;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\PropertyProfile;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\ReservationStatusLog;
use App\Models\RoomType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReservationResourceTest extends TestCase
{
    use RefreshDatabase;

    private const ACTIONS = ['approve', 'reject', 'recordPayment', 'otaBlocked', 'complete', 'reactivate', 'cancel'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-12-01 00:00:00');

        PropertyProfile::factory()->create(['timezone' => 'Asia/Makassar', 'currency' => 'IDR', 'payment_window_hours' => 24]);
        CancellationPolicy::factory()->create(['name' => CancellationPolicy::NON_REFUNDABLE]);

        $this->admin = User::factory()->create(['name' => 'Made Admin']);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_reservations_list_opens_from_the_admin_menu(): void
    {
        $this->reservation(attributes: ['code' => 'PEND1234', 'guest_full_name' => 'Ayu Lestari']);

        $this->get(ReservationResource::getUrl())
            ->assertOk()
            ->assertSee('PEND1234')
            ->assertSee('Ayu Lestari');
    }

    public function test_list_filters_by_status(): void
    {
        $pending = $this->reservation();
        $confirmed = $this->reservation(ReservationStatus::Confirmed);

        Livewire::test(ListReservations::class)
            ->filterTable('status', [ReservationStatus::Confirmed->value])
            ->assertCanSeeTableRecords([$confirmed])
            ->assertCanNotSeeTableRecords([$pending]);
    }

    public function test_stay_filter_lists_stays_with_a_night_in_the_range(): void
    {
        $inside = $this->reservation(attributes: ['check_in' => '2026-12-09', 'check_out' => '2026-12-11']);
        $leavingOnTheFirstDay = $this->reservation(attributes: ['check_in' => '2026-12-05', 'check_out' => '2026-12-10']);
        $arrivingOnTheLastDay = $this->reservation(attributes: ['check_in' => '2026-12-12', 'check_out' => '2026-12-14']);
        $after = $this->reservation(attributes: ['check_in' => '2026-12-13', 'check_out' => '2026-12-15']);

        Livewire::test(ListReservations::class)
            ->filterTable('stay', ['from' => '2026-12-10', 'until' => '2026-12-12'])
            ->assertCanSeeTableRecords([$inside, $arrivingOnTheLastDay])
            ->assertCanNotSeeTableRecords([$leavingOnTheFirstDay, $after]);
    }

    public function test_view_page_shows_guest_nights_payments_and_history(): void
    {
        $reservation = $this->reservation(ReservationStatus::Confirmed, [
            'guest_full_name' => 'Ayu Lestari',
            'guest_email' => 'ayu@example.com',
            'guest_phone' => '+62 812 0000 1111',
        ]);
        Payment::factory()->paid()->for($reservation)->create(['provider_ref' => 'TRX-778', 'verified_by' => $this->admin->id, 'proof_url' => 'payment-proofs/trx-778.pdf']);
        ReservationStatusLog::factory()->for($reservation)->create([
            'from_status' => ReservationStatus::AwaitingPayment,
            'to_status' => ReservationStatus::Confirmed,
            'admin_user_id' => $this->admin->id,
            'note' => 'Payment received.',
        ]);

        $this->get(ReservationResource::getUrl('view', ['record' => $reservation]))
            ->assertOk()
            ->assertSee('Ayu Lestari')
            ->assertSee('ayu@example.com')
            ->assertSee('+62 812 0000 1111')
            ->assertSee('24 Dec 2026 – 27 Dec 2026 (3 nights)')
            ->assertSee('Thu 24 Dec 2026')
            ->assertSee('TRX-778')
            ->assertSee('View proof')
            ->assertSee('payment-proofs/trx-778.pdf?expires=')
            ->assertSee('Awaiting payment → Confirmed')
            ->assertSee('Made Admin')
            ->assertSee('Payment received.');
    }

    /**
     * @return array<string, array{ReservationStatus, list<string>}>
     */
    public static function actionsByStatus(): array
    {
        return [
            'pending' => [ReservationStatus::Pending, ['approve', 'reject', 'cancel']],
            'awaiting payment' => [ReservationStatus::AwaitingPayment, ['recordPayment', 'otaBlocked', 'cancel']],
            'confirmed, paid, before check-out' => [ReservationStatus::Confirmed, ['otaBlocked', 'cancel']],
            'completed' => [ReservationStatus::Completed, []],
            'rejected' => [ReservationStatus::Rejected, []],
            'cancelled' => [ReservationStatus::Cancelled, []],
            'expired' => [ReservationStatus::Expired, ['reactivate']],
        ];
    }

    /**
     * @param  list<string>  $visible
     */
    #[DataProvider('actionsByStatus')]
    public function test_header_actions_follow_the_status(ReservationStatus $status, array $visible): void
    {
        $page = $this->page($this->reservation($status));

        foreach (self::ACTIONS as $action) {
            in_array($action, $visible, true) ? $page->assertActionVisible($action) : $page->assertActionHidden($action);
        }
    }

    public function test_unpaid_confirmed_reservation_takes_payments(): void
    {
        $reservation = $this->reservation(ReservationStatus::Confirmed, ['payment_status' => ReservationPaymentStatus::Unpaid]);

        $this->page($reservation)->assertActionVisible('recordPayment');
    }

    public function test_confirmed_stay_can_be_completed_once_checked_out(): void
    {
        $reservation = $this->reservation(ReservationStatus::Confirmed, ['check_in' => '2026-11-27', 'check_out' => '2026-11-30']);

        $this->page($reservation)
            ->callAction('complete')
            ->assertNotified('Reservation completed');

        $this->assertSame(ReservationStatus::Completed, $reservation->refresh()->status);
    }

    public function test_approve_holds_a_unit_and_requests_payment(): void
    {
        $reservation = $this->reservation();

        $this->page($reservation)
            ->callAction('approve', ['note' => 'Dates checked on Booking.com.'])
            ->assertNotified('Reservation approved');

        $reservation->refresh();
        $this->assertSame(ReservationStatus::AwaitingPayment, $reservation->status);
        $this->assertSame('2026-12-02 00:00:00', $reservation->expires_at?->toDateTimeString());
        $this->assertNotNull($reservation->reservationRooms()->sole()->unit_id);
        $this->assertDatabaseHas('reservation_status_logs', [
            'reservation_id' => $reservation->id,
            'to_status' => ReservationStatus::AwaitingPayment->value,
            'admin_user_id' => $this->admin->id,
            'note' => 'Dates checked on Booking.com.',
        ]);
    }

    public function test_approve_without_a_free_unit_shows_why_and_changes_nothing(): void
    {
        $reservation = $this->reservation();
        $unit = Unit::query()->where('room_type_id', $reservation->reservationRooms()->sole()->room_type_id)->sole();
        AvailabilityBlock::factory()->for($unit)->create(['start_date' => '2026-12-25', 'end_date' => '2026-12-26']);

        $this->page($reservation)
            ->callAction('approve')
            ->assertNotified('Not approved');

        $this->assertSame(ReservationStatus::Pending, $reservation->refresh()->status);
    }

    public function test_reject_needs_a_reason(): void
    {
        $reservation = $this->reservation();

        $this->page($reservation)
            ->callAction('reject', ['reason' => ''])
            ->assertHasFormErrors(['reason' => 'required']);

        $this->assertSame(ReservationStatus::Pending, $reservation->refresh()->status);
    }

    public function test_reject_stores_the_reason(): void
    {
        $reservation = $this->reservation();

        $this->page($reservation)
            ->callAction('reject', ['reason' => 'Villa closed for renovation.'])
            ->assertNotified('Request rejected');

        $reservation->refresh();
        $this->assertSame(ReservationStatus::Rejected, $reservation->status);
        $this->assertSame('Villa closed for renovation.', $reservation->reject_reason);
    }

    public function test_full_payment_with_proof_confirms_the_reservation(): void
    {
        Storage::fake(Payment::PROOF_DISK);
        // The frozen clock is ahead of the files' real times, so Livewire would clean the upload up as stale.
        config(['livewire.temporary_file_upload.cleanup' => false]);
        $reservation = $this->reservation(ReservationStatus::AwaitingPayment);

        $this->page($reservation)
            ->callAction('recordPayment', [
                'amount' => '4500000',
                'method' => PaymentMethod::BankTransfer->value,
                'reference' => 'TRX-001',
                'proof' => UploadedFile::fake()->image('proof.jpg'),
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Payment recorded, reservation confirmed');

        $this->assertSame(ReservationStatus::Confirmed, $reservation->refresh()->status);
        $payment = $reservation->payments()->sole();
        $this->assertSame('4500000.00', $payment->amount);
        $this->assertSame('TRX-001', $payment->provider_ref);
        $this->assertSame($this->admin->id, $payment->verified_by);
        $this->assertSame('2026-12-01 00:00:00', $payment->paid_at?->toDateTimeString());
        $this->assertStringStartsWith(Payment::PROOF_DIRECTORY.'/', (string) $payment->proof_url);
        Storage::disk(Payment::PROOF_DISK)->assertExists((string) $payment->proof_url);
    }

    public function test_payment_received_later_than_now_is_refused(): void
    {
        $reservation = $this->reservation(ReservationStatus::AwaitingPayment);

        // The admin enters times in the property timezone: 09:00 WITA is 01:00 UTC, an hour from now.
        $this->page($reservation)
            ->callAction('recordPayment', ['amount' => '4500000', 'method' => PaymentMethod::Cash->value, 'paid_at' => '2026-12-01 09:00:00'])
            ->assertNotified('Payment not recorded');

        $this->assertSame(0, $reservation->payments()->count());
        $this->assertSame(ReservationStatus::AwaitingPayment, $reservation->refresh()->status);
    }

    public function test_payment_that_cannot_confirm_is_kept_and_the_admin_is_warned(): void
    {
        $reservation = $this->reservation(ReservationStatus::AwaitingPayment);
        $unit = $reservation->reservationRooms()->sole()->unit()->firstOrFail();
        AvailabilityBlock::factory()->for($unit)->create(['start_date' => '2026-12-24', 'end_date' => '2026-12-25']);

        $this->page($reservation)
            ->callAction('recordPayment', ['amount' => '4500000', 'method' => PaymentMethod::Cash->value])
            ->assertNotified('Payment recorded, reservation not confirmed');

        $reservation->refresh();
        $this->assertSame(ReservationStatus::AwaitingPayment, $reservation->status);
        $this->assertSame(ReservationPaymentStatus::Paid, $reservation->payment_status);
    }

    public function test_cancel_needs_a_reason(): void
    {
        $reservation = $this->reservation(ReservationStatus::Confirmed);

        $this->page($reservation)
            ->callAction('cancel', ['reason' => ''])
            ->assertHasFormErrors(['reason' => 'required']);

        $this->assertSame(ReservationStatus::Confirmed, $reservation->refresh()->status);
    }

    public function test_cancel_records_the_reason_in_the_history(): void
    {
        $reservation = $this->reservation(ReservationStatus::Confirmed);

        $this->page($reservation)
            ->callAction('cancel', ['reason' => 'Guest asked to cancel.'])
            ->assertNotified('Reservation cancelled');

        $this->assertSame(ReservationStatus::Cancelled, $reservation->refresh()->status);
        $this->assertDatabaseHas('reservation_status_logs', [
            'reservation_id' => $reservation->id,
            'to_status' => ReservationStatus::Cancelled->value,
            'note' => "Guest asked to cancel.\nNo refund: the rate is non-refundable.",
        ]);
    }

    public function test_reactivate_gives_an_expired_reservation_another_payment_window(): void
    {
        $reservation = $this->reservation(ReservationStatus::Expired);

        $this->page($reservation)
            ->callAction('reactivate')
            ->assertNotified('Reservation reactivated');

        $reservation->refresh();
        $this->assertSame(ReservationStatus::AwaitingPayment, $reservation->status);
        $this->assertSame('2026-12-02 00:00:00', $reservation->expires_at?->toDateTimeString());
    }

    public function test_blocked_on_ota_is_marked_and_cleared(): void
    {
        $reservation = $this->reservation(ReservationStatus::Confirmed);

        $page = $this->page($reservation)
            ->callAction('otaBlocked')
            ->assertNotified('Marked as blocked on OTA');

        $this->assertSame('2026-12-01 00:00:00', $reservation->refresh()->ota_blocked_at?->toDateTimeString());

        $page->callAction('otaBlocked')
            ->assertNotified('"Blocked on OTA" cleared');

        $this->assertNull($reservation->refresh()->ota_blocked_at);
    }

    public function test_overlapping_pending_requests_are_warned_about_with_escaped_names(): void
    {
        $reservation = $this->reservation();
        $roomType = $reservation->reservationRooms()->sole()->roomType()->firstOrFail();
        $other = $this->reservation(attributes: ['code' => 'OVER5678', 'guest_full_name' => '<b>Ben</b>', 'check_in' => '2026-12-26', 'check_out' => '2026-12-28'], roomType: $roomType);

        $this->get(ReservationResource::getUrl('view', ['record' => $reservation]))
            ->assertOk()
            ->assertSee('Other pending requests for these dates')
            ->assertSee(ReservationResource::getUrl('view', ['record' => $other]))
            ->assertSee('OVER5678')
            ->assertSee('<b>Ben</b>')
            ->assertDontSee('<b>Ben</b>', escape: false);
    }

    public function test_no_warning_without_overlapping_requests(): void
    {
        $reservation = $this->reservation();

        $this->get(ReservationResource::getUrl('view', ['record' => $reservation]))
            ->assertOk()
            ->assertDontSee('Other pending requests for these dates');
    }

    public function test_manual_reservation_is_created_confirmed_and_opened(): void
    {
        $roomType = RoomType::factory()->create();
        Unit::factory()->for($roomType)->create();
        app(SetBasePrice::class)->handle($roomType, '1500000');

        Livewire::test(ListReservations::class)
            ->callAction('createManualReservation', [
                'source' => ReservationSource::BookingCom->value,
                'external_id' => 'BK-4455',
                'room_type_id' => $roomType->id,
                'check_in' => '2026-12-10',
                'check_out' => '2026-12-12',
                'adults' => 2,
                'children' => 0,
                'total' => '3200000',
                'full_name' => 'Cara Diaz',
                'email' => 'cara@example.com',
                'phone' => '+44 7700 900000',
            ])
            ->assertHasNoFormErrors()
            ->assertNotified()
            ->assertRedirect(ReservationResource::getUrl('view', ['record' => Reservation::query()->sole()]));

        $reservation = Reservation::query()->sole();
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertSame(ReservationSource::BookingCom, $reservation->source);
        $this->assertSame('BK-4455', $reservation->external_id);
        $this->assertSame('3200000.00', $reservation->total);
        $this->assertSame('Cara Diaz', $reservation->guest_full_name);
    }

    public function test_manual_reservation_for_a_room_type_without_prices_is_refused(): void
    {
        $roomType = RoomType::factory()->create();
        Unit::factory()->for($roomType)->create();

        Livewire::test(ListReservations::class)
            ->callAction('createManualReservation', [
                'source' => ReservationSource::Manual->value,
                'room_type_id' => $roomType->id,
                'check_in' => '2026-12-10',
                'check_out' => '2026-12-12',
                'adults' => 2,
                'children' => 0,
                'full_name' => 'Cara Diaz',
                'email' => 'cara@example.com',
                'phone' => '+44 7700 900000',
            ])
            ->assertNotified('Reservation not created');

        $this->assertSame(0, Reservation::query()->count());
    }

    private function page(Reservation $reservation): Testable
    {
        return Livewire::test(ViewReservation::class, ['record' => $reservation->getRouteKey()]);
    }

    /**
     * A reservation for 24–27 Dec 2026 of a room type with one unit, which reservations holding
     * their dates are given. Three nights at 1,500,000.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function reservation(ReservationStatus $status = ReservationStatus::Pending, array $attributes = [], ?RoomType $roomType = null): Reservation
    {
        $factory = Reservation::factory()->for(Guest::factory());
        $factory = match ($status) {
            ReservationStatus::AwaitingPayment => $factory->awaitingPayment(),
            ReservationStatus::Confirmed => $factory->confirmed(),
            default => $factory->state(['status' => $status]),
        };
        $reservation = $factory->create($attributes);

        $roomType ??= RoomType::factory()->create();
        $unit = $roomType->units()->first() ?? Unit::factory()->for($roomType)->create();

        $room = ReservationRoom::factory()->for($reservation)->for($roomType)->create([
            'unit_id' => in_array($status, ReservationStatus::blockingAvailability(), true) ? $unit->id : null,
        ]);

        foreach (['2026-12-24', '2026-12-25', '2026-12-26'] as $night) {
            $room->nights()->create(['night_date' => $night, 'price' => 1500000]);
        }

        return $reservation;
    }
}

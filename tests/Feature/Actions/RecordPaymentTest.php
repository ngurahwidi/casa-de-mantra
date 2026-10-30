<?php

namespace Tests\Feature\Actions;

use App\Actions\Reservation\RecordPayment;
use App\Data\Reservation\PaymentDetails;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\Reservation\DatesNotAvailableException;
use App\Exceptions\Reservation\PaymentNotAcceptedException;
use App\Models\AvailabilityBlock;
use App\Models\OutboxEvent;
use App\Models\Payment;
use App\Models\PropertyProfile;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\ReservationStatusLog;
use App\Models\RoomType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class RecordPaymentTest extends TestCase
{
    use RefreshDatabase;

    private RecordPayment $record;

    private User $admin;

    private RoomType $roomType;

    private RatePlan $ratePlan;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-01 08:00:00');

        PropertyProfile::factory()->create();

        $this->record = $this->app->make(RecordPayment::class);
        $this->admin = User::factory()->create();
        $this->roomType = RoomType::factory()->published()->create();
        $this->ratePlan = RatePlan::factory()->for($this->roomType)->create();
        $this->unit = Unit::factory()->for($this->roomType)->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, array{ReservationStatus}>
     */
    public static function notPayable(): array
    {
        return collect([
            ReservationStatus::Pending,
            ReservationStatus::Rejected,
            ReservationStatus::Cancelled,
            ReservationStatus::Completed,
        ])->mapWithKeys(fn (ReservationStatus $status) => [$status->value => [$status]])->all();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidAmounts(): array
    {
        return [
            'zero' => ['0'],
            'zero with decimals' => ['0.00'],
            'negative' => ['-100'],
            'three decimals' => ['100.005'],
            'not a number' => ['abc'],
            'empty' => [''],
            'thousands separator' => ['1,500,000'],
            'too large for decimal(14,2)' => ['1000000000000'],
        ];
    }

    public function test_full_payment_is_recorded_and_confirms_the_reservation(): void
    {
        $reservation = $this->awaitingPayment();

        $result = $this->record->handle($reservation, $this->admin, new PaymentDetails(
            amount: '4500000',
            method: PaymentMethod::BankTransfer,
            paidAt: Carbon::parse('2026-10-31 03:15:00'),
            reference: '  TRX-123  ',
            proofPath: 'payment-proofs/abc.jpg',
        ));

        $payment = Payment::query()->sole();
        $this->assertTrue($result->payment->is($payment));
        $this->assertSame($reservation->id, $payment->reservation_id);
        $this->assertSame($this->admin->id, $payment->verified_by);
        $this->assertSame('4500000.00', $payment->amount);
        $this->assertSame(PaymentMethod::BankTransfer, $payment->method);
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertNull($payment->provider);
        $this->assertSame('TRX-123', $payment->provider_ref);
        $this->assertSame('2026-10-31 03:15:00', $payment->paid_at->toDateTimeString());
        $this->assertSame('payment-proofs/abc.jpg', $payment->proof_url);

        $this->assertSame($reservation, $result->reservation);
        $this->assertTrue($result->isConfirmed());
        $this->assertNull($result->confirmationFailure);

        $fresh = $reservation->fresh();
        $this->assertSame(ReservationPaymentStatus::Paid, $fresh->payment_status);
        $this->assertSame(ReservationStatus::Confirmed, $fresh->status);
        $this->assertSame('2026-11-01 08:00:00', $fresh->confirmed_at->toDateTimeString());

        $log = ReservationStatusLog::query()->sole();
        $this->assertSame(ReservationStatus::Confirmed, $log->to_status);
        $this->assertSame($this->admin->id, $log->admin_user_id);
        $this->assertSame('Payment received.', $log->note);

        $this->assertEqualsCanonicalizing(['payment.recorded', 'reservation.confirmed'], OutboxEvent::query()->pluck('type')->all());

        $event = OutboxEvent::query()->where('type', 'payment.recorded')->sole();
        $this->assertSame($payment->id, $event->payload['payment_id']);
        $this->assertSame('4500000.00', $event->payload['amount']);
        $this->assertSame('4500000.00', $event->payload['total_paid']);
        $this->assertSame('paid', $event->payload['payment_status']);
    }

    public function test_paid_at_defaults_to_now_and_blank_details_are_stored_as_null(): void
    {
        $this->record->handle($this->awaitingPayment(), $this->admin, new PaymentDetails('4500000', PaymentMethod::Cash, reference: ' ', proofPath: ''));

        $payment = Payment::query()->sole();
        $this->assertSame('2026-11-01 08:00:00', $payment->paid_at->toDateTimeString());
        $this->assertNull($payment->provider_ref);
        $this->assertNull($payment->proof_url);
    }

    public function test_partial_payment_is_a_deposit_and_does_not_confirm_by_default(): void
    {
        $reservation = $this->awaitingPayment();

        $result = $this->record->handle($reservation, $this->admin, $this->payment('1500000.50'));

        $this->assertFalse($result->isConfirmed());
        $this->assertNull($result->confirmationFailure);

        $fresh = $reservation->fresh();
        $this->assertSame(ReservationPaymentStatus::Deposit, $fresh->payment_status);
        $this->assertSame(ReservationStatus::AwaitingPayment, $fresh->status);
        $this->assertNull($fresh->confirmed_at);
        $this->assertSame('1500000.50', Payment::query()->sole()->amount);

        $this->assertSame(0, ReservationStatusLog::query()->count());
        $this->assertSame(['payment.recorded'], OutboxEvent::query()->pluck('type')->all());
    }

    public function test_partial_payment_confirms_when_the_property_confirms_on_deposit(): void
    {
        PropertyProfile::current()->update(['confirm_on_deposit' => true]);
        $reservation = $this->awaitingPayment();

        $result = $this->record->handle($reservation, $this->admin, $this->payment('1000000'));

        $this->assertTrue($result->isConfirmed());

        $fresh = $reservation->fresh();
        $this->assertSame(ReservationPaymentStatus::Deposit, $fresh->payment_status);
        $this->assertSame(ReservationStatus::Confirmed, $fresh->status);
    }

    public function test_payments_that_together_reach_the_total_confirm_the_reservation(): void
    {
        $reservation = $this->awaitingPayment();

        $first = $this->record->handle($reservation, $this->admin, $this->payment('4499999.99'));
        $this->assertFalse($first->isConfirmed());
        $this->assertSame(ReservationPaymentStatus::Deposit, $reservation->fresh()->payment_status);

        $second = $this->record->handle($reservation, $this->admin, $this->payment('0.01'));
        $this->assertTrue($second->isConfirmed());
        $this->assertSame(ReservationPaymentStatus::Paid, $reservation->fresh()->payment_status);
        $this->assertSame(2, Payment::query()->count());
    }

    public function test_overpayment_counts_as_paid(): void
    {
        $reservation = $this->awaitingPayment();

        $result = $this->record->handle($reservation, $this->admin, $this->payment('5000000'));

        $this->assertTrue($result->isConfirmed());
        $this->assertSame(ReservationPaymentStatus::Paid, $reservation->fresh()->payment_status);
        $this->assertSame('5000000.00', Payment::query()->sole()->amount);
    }

    public function test_payments_that_were_not_paid_are_not_counted(): void
    {
        $reservation = $this->awaitingPayment();
        Payment::factory()->for($reservation)->create(['amount' => 4000000, 'status' => PaymentStatus::Failed]);
        Payment::factory()->for($reservation)->create(['amount' => 4000000, 'status' => PaymentStatus::Pending]);

        $this->record->handle($reservation, $this->admin, $this->payment('1000000'));

        $this->assertSame(ReservationPaymentStatus::Deposit, $reservation->fresh()->payment_status);
        $this->assertSame(ReservationStatus::AwaitingPayment, $reservation->fresh()->status);
    }

    public function test_balance_after_a_deposit_can_be_recorded_on_a_confirmed_reservation(): void
    {
        PropertyProfile::current()->update(['confirm_on_deposit' => true]);
        $reservation = $this->awaitingPayment();
        $this->record->handle($reservation, $this->admin, $this->payment('1500000'));

        $result = $this->record->handle($reservation, $this->admin, $this->payment('3000000'));

        $this->assertTrue($result->isConfirmed());
        $this->assertSame(ReservationPaymentStatus::Paid, $reservation->fresh()->payment_status);
        // Confirmed once, by the deposit.
        $this->assertSame(1, ReservationStatusLog::query()->count());
    }

    public function test_payment_after_expiry_is_rejected_until_the_reservation_is_reactivated(): void
    {
        $reservation = $this->awaitingPayment();
        $reservation->update(['status' => ReservationStatus::Expired]);

        $this->assertNotRecorded(
            $reservation,
            'This reservation has expired. Reactivate it before recording a payment.',
            ReservationStatus::Expired,
        );
    }

    public function test_payment_past_the_deadline_is_accepted_while_the_reservation_still_awaits_payment(): void
    {
        $reservation = $this->awaitingPayment();
        $reservation->update(['expires_at' => now()->subHour()]);

        $result = $this->record->handle($reservation, $this->admin, $this->payment('4500000'));

        $this->assertTrue($result->isConfirmed());
    }

    #[DataProvider('notPayable')]
    public function test_only_reservations_awaiting_payment_or_confirmed_take_payments(ReservationStatus $status): void
    {
        $reservation = $this->awaitingPayment();
        $reservation->update(['status' => $status]);

        $this->assertNotRecorded(
            $reservation,
            "Payments can only be recorded for reservations awaiting payment or confirmed; this one is {$status->value}.",
            $status,
        );
    }

    public function test_status_is_read_from_the_database_not_from_a_stale_model(): void
    {
        $reservation = $this->awaitingPayment();
        Reservation::query()->whereKey($reservation->id)->update(['status' => ReservationStatus::Expired]);

        $this->assertNotRecorded($reservation, null, ReservationStatus::Expired);
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_amounts_are_rejected(string $amount): void
    {
        $this->assertNotRecorded($this->awaitingPayment(), null, ReservationStatus::AwaitingPayment, $amount);
    }

    public function test_payment_is_kept_when_the_confirmation_fails(): void
    {
        $reservation = $this->awaitingPayment();
        // An OTA booking was imported for the unit after the reservation was approved.
        AvailabilityBlock::factory()->for($this->unit)->create(['start_date' => '2026-12-25', 'end_date' => '2026-12-26']);

        $result = $this->record->handle($reservation, $this->admin, $this->payment('4500000'));

        $this->assertFalse($result->isConfirmed());
        $this->assertInstanceOf(DatesNotAvailableException::class, $result->confirmationFailure);

        $fresh = $reservation->fresh();
        $this->assertSame(ReservationStatus::AwaitingPayment, $fresh->status);
        $this->assertSame(ReservationPaymentStatus::Paid, $fresh->payment_status);
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(['payment.recorded'], OutboxEvent::query()->pluck('type')->all());
    }

    public function test_failure_while_recording_rolls_back_the_payment(): void
    {
        $reservation = $this->awaitingPayment();
        OutboxEvent::creating(fn () => throw new RuntimeException('Outbox unavailable.'));

        try {
            $this->record->handle($reservation, $this->admin, $this->payment('4500000'));
            $this->fail('Expected RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertSame('Outbox unavailable.', $e->getMessage());
        }

        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(ReservationPaymentStatus::Unpaid, $reservation->fresh()->payment_status);
    }

    /**
     * An approved, unpaid reservation of 4,500,000 holding the unit for 24–27 Dec.
     */
    private function awaitingPayment(): Reservation
    {
        $reservation = Reservation::factory()->awaitingPayment()->create();

        ReservationRoom::factory()->for($reservation)->create([
            'room_type_id' => $this->roomType->id,
            'unit_id' => $this->unit->id,
            'rate_plan_id' => $this->ratePlan->id,
        ]);

        return $reservation;
    }

    private function payment(string $amount): PaymentDetails
    {
        return new PaymentDetails($amount, PaymentMethod::BankTransfer);
    }

    /**
     * Expects recording a payment to be refused and to leave no trace.
     */
    private function assertNotRecorded(
        Reservation $reservation,
        ?string $message,
        ReservationStatus $status,
        string $amount = '4500000',
    ): void {
        try {
            $this->record->handle($reservation, $this->admin, $this->payment($amount));
        } catch (Throwable $e) {
            $this->assertInstanceOf(PaymentNotAcceptedException::class, $e);

            if ($message !== null) {
                $this->assertSame($message, $e->getMessage());
            }

            $fresh = $reservation->fresh();
            $this->assertSame($status, $fresh->status);
            $this->assertSame(ReservationPaymentStatus::Unpaid, $fresh->payment_status);
            $this->assertSame(0, Payment::query()->count());
            $this->assertSame(0, ReservationStatusLog::query()->count());
            $this->assertSame(0, OutboxEvent::query()->count());

            return;
        }

        $this->fail('Expected PaymentNotAcceptedException.');
    }
}

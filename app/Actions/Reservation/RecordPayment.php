<?php

namespace App\Actions\Reservation;

use App\Data\Reservation\PaymentDetails;
use App\Data\Reservation\RecordedPayment;
use App\Enums\OutboxEventStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\Reservation\PaymentNotAcceptedException;
use App\Exceptions\Reservation\ReservationException;
use App\Models\OutboxEvent;
use App\Models\Payment;
use App\Models\PropertyProfile;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * An admin records a payment received for a reservation, e.g. a bank transfer.
 *
 * Payments are accepted while the reservation awaits payment, once confirmed (the balance after
 * a deposit), and after the stay is completed: the property lets guests settle the balance at
 * check-out, and the admin may only record it after CompleteReservation has run. A payment on a
 * completed stay changes its payment_status only, never its status. An expired reservation has
 * released its dates and must be reactivated first. The deadline itself is not checked here: until ExpireStaleReservations moves it to
 * expired, an awaiting_payment reservation still holds its dates.
 *
 * paid_at is when the money was received, so it cannot be later than now.
 *
 * The reservation's payment_status is worked out from all its paid payments: paid once they
 * reach the total (overpayment included), deposit below it. The reservation row is locked
 * while doing so, so two payments recorded at once are both counted.
 *
 * When the admin names the guest's transfer proof, that pending payment becomes the paid one
 * and keeps its file, so one transfer is one payment. Once the total is reached, any other
 * proofs still waiting are closed as expired: there is nothing left for them to verify.
 *
 * When the payment makes the reservation eligible (see ConfirmReservation::isPaidEnough), it
 * is confirmed right after, in a separate transaction: money that was received stays recorded
 * even if the confirmation fails, and the failure is returned instead of thrown.
 */
class RecordPayment
{
    /** @var list<ReservationStatus> */
    public const PAYABLE_STATUSES = [
        ReservationStatus::AwaitingPayment,
        ReservationStatus::Confirmed,
        ReservationStatus::Completed,
    ];

    public function __construct(private readonly ConfirmReservation $confirm) {}

    /**
     * @throws PaymentNotAcceptedException when the reservation does not take payments, the amount is invalid,
     *                                     the payment is dated in the future or the named proof is not pending
     */
    public function handle(Reservation $reservation, User $admin, PaymentDetails $details): RecordedPayment
    {
        $amount = self::normalizeAmount($details->amount);

        if ($details->paidAt?->greaterThan(now())) {
            throw PaymentNotAcceptedException::receivedInTheFuture();
        }

        $payment = DB::transaction(function () use ($reservation, $admin, $details, $amount): Payment {
            $locked = Reservation::query()->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === ReservationStatus::Expired) {
                throw PaymentNotAcceptedException::expired();
            }

            if (! in_array($locked->status, self::PAYABLE_STATUSES, true)) {
                throw PaymentNotAcceptedException::notPayable($locked->status);
            }

            $guestProof = null;

            if ($details->guestProofId !== null) {
                $guestProof = $locked->pendingPaymentProofs()->whereKey($details->guestProofId)->first()
                    ?? throw PaymentNotAcceptedException::proofNoLongerPending();
            }

            $payment = $guestProof ?? $locked->payments()->make();

            $payment->fill([
                'verified_by' => $admin->getKey(),
                'amount' => $amount,
                'method' => $details->method,
                'status' => PaymentStatus::Paid,
                'provider' => null,
                'provider_ref' => self::clean($details->reference),
                'paid_at' => $details->paidAt ?? now(),
                'proof_url' => self::clean($details->proofPath) ?? $guestProof?->proof_url,
            ])->save();

            $paid = (string) $locked->payments()->where('status', PaymentStatus::Paid)->sum('amount');

            $locked->update([
                'payment_status' => self::toCents($paid) >= self::toCents($locked->total)
                    ? ReservationPaymentStatus::Paid
                    : ReservationPaymentStatus::Deposit,
            ]);

            if ($locked->payment_status === ReservationPaymentStatus::Paid) {
                $locked->pendingPaymentProofs()->update(['status' => PaymentStatus::Expired]);
            }

            OutboxEvent::create([
                'type' => 'payment.recorded',
                'payload' => [
                    'reservation_id' => $locked->getKey(),
                    'code' => $locked->code,
                    'payment_id' => $payment->getKey(),
                    'amount' => $amount,
                    'method' => $details->method->value,
                    'total_paid' => $paid,
                    'payment_status' => $locked->payment_status->value,
                    'admin_user_id' => $admin->getKey(),
                ],
                'status' => OutboxEventStatus::Pending,
            ]);

            return $payment;
        });

        $reservation->refresh();

        $failure = null;

        if ($reservation->status === ReservationStatus::AwaitingPayment
            && ConfirmReservation::isPaidEnough($reservation, PropertyProfile::current())) {
            try {
                $this->confirm->handle($reservation, $admin, 'Payment received.');
            } catch (ReservationException $e) {
                $failure = $e;
            }
        }

        return new RecordedPayment($payment, $reservation, $failure);
    }

    /**
     * The amount as a decimal with two places, e.g. "1500000" becomes "1500000.00".
     *
     * @throws PaymentNotAcceptedException
     */
    private static function normalizeAmount(string $amount): string
    {
        $amount = trim($amount);

        // decimal(14,2): at most 12 digits before the point.
        if (preg_match('/^\d{1,12}(\.\d{1,2})?$/', $amount) !== 1 || self::toCents($amount) === 0) {
            throw PaymentNotAcceptedException::invalidAmount($amount);
        }

        $cents = self::toCents($amount);

        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    /**
     * Converts a non-negative decimal string such as "1500000.50" to cents without floats.
     */
    private static function toCents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private static function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}

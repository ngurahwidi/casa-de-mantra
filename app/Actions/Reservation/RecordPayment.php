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
 * Payments are accepted while the reservation awaits payment, and once confirmed (the balance
 * after a deposit). An expired reservation has released its dates and must be reactivated
 * first. The deadline itself is not checked here: until ExpireStaleReservations moves it to
 * expired, an awaiting_payment reservation still holds its dates.
 *
 * The reservation's payment_status is worked out from all its paid payments: paid once they
 * reach the total (overpayment included), deposit below it. The reservation row is locked
 * while doing so, so two payments recorded at once are both counted.
 *
 * When the payment makes the reservation eligible (see ConfirmReservation::isPaidEnough), it
 * is confirmed right after, in a separate transaction: money that was received stays recorded
 * even if the confirmation fails, and the failure is returned instead of thrown.
 */
class RecordPayment
{
    public function __construct(private readonly ConfirmReservation $confirm) {}

    /**
     * @throws PaymentNotAcceptedException when the reservation does not take payments or the amount is invalid
     */
    public function handle(Reservation $reservation, User $admin, PaymentDetails $details): RecordedPayment
    {
        $amount = self::normalizeAmount($details->amount);

        $payment = DB::transaction(function () use ($reservation, $admin, $details, $amount): Payment {
            $locked = Reservation::query()->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === ReservationStatus::Expired) {
                throw PaymentNotAcceptedException::expired();
            }

            if (! in_array($locked->status, [ReservationStatus::AwaitingPayment, ReservationStatus::Confirmed], true)) {
                throw PaymentNotAcceptedException::notPayable($locked->status);
            }

            $payment = $locked->payments()->create([
                'verified_by' => $admin->getKey(),
                'amount' => $amount,
                'method' => $details->method,
                'status' => PaymentStatus::Paid,
                'provider' => null,
                'provider_ref' => self::clean($details->reference),
                'paid_at' => $details->paidAt ?? now(),
                'proof_url' => self::clean($details->proofPath),
            ]);

            $paid = (string) $locked->payments()->where('status', PaymentStatus::Paid)->sum('amount');

            $locked->update([
                'payment_status' => self::toCents($paid) >= self::toCents($locked->total)
                    ? ReservationPaymentStatus::Paid
                    : ReservationPaymentStatus::Deposit,
            ]);

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

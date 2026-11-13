<?php

namespace App\Actions\Reservation;

use App\Enums\OutboxEventStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\Reservation\PaymentNotAcceptedException;
use App\Models\OutboxEvent;
use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * A guest tells us they have transferred the payment and uploads their receipt.
 *
 * The file is stored on the private disk and a pending payment records the claim for an admin
 * to verify. Nothing on the reservation changes: the payment_status stays unpaid/deposit until
 * an admin records the payment through RecordPayment, and the status stays awaiting_payment.
 * While a proof is pending the reservation does not expire (see ExpireStaleReservations).
 * Uploading again simply adds another pending proof, so a guest can replace a bad photo.
 */
class SubmitPaymentProof
{
    /**
     * @throws PaymentNotAcceptedException when the reservation is not awaiting payment
     *                                     or has no outstanding balance
     */
    public function handle(Reservation $reservation, UploadedFile $proof): Payment
    {
        if ($reservation->status !== ReservationStatus::AwaitingPayment) {
            throw PaymentNotAcceptedException::proofNotAccepted($reservation->status);
        }

        $path = $proof->store(
            Payment::PROOF_DIRECTORY.'/'.$reservation->code,
            Payment::PROOF_DISK,
        );

        if ($path === false) {
            throw new RuntimeException('The transfer proof could not be stored.');
        }

        try {
            return DB::transaction(function () use ($reservation, $path): Payment {
                $locked = Reservation::query()->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->status !== ReservationStatus::AwaitingPayment) {
                    throw PaymentNotAcceptedException::proofNotAccepted($locked->status);
                }

                $amount = self::outstandingAmount($locked);

                if (self::toCents($amount) <= 0) {
                    throw new PaymentNotAcceptedException('There is no outstanding balance for this reservation.');
                }

                $payment = $locked->payments()->create([
                    'verified_by' => null,
                    'amount' => $amount,
                    'method' => PaymentMethod::BankTransfer,
                    'status' => PaymentStatus::Pending,
                    'provider' => null,
                    'provider_ref' => null,
                    'paid_at' => null,
                    'proof_url' => $path,
                ]);

                OutboxEvent::create([
                    'type' => 'payment.proof_uploaded',
                    'payload' => [
                        'reservation_id' => $locked->getKey(),
                        'code' => $locked->code,
                        'payment_id' => $payment->getKey(),
                        'amount' => $payment->amount,
                        'status' => PaymentStatus::Pending->value,
                    ],
                    'status' => OutboxEventStatus::Pending,
                ]);

                return $payment;
            });
        } catch (Throwable $exception) {
            Storage::disk(Payment::PROOF_DISK)->delete($path);

            throw $exception;
        }
    }

    /**
     * What is still owed, as a decimal string with two places.
     */
    private static function outstandingAmount(Reservation $reservation): string
    {
        $paid = (string) $reservation->payments()->where('status', PaymentStatus::Paid)->sum('amount');
        $cents = max(0, self::toCents((string) $reservation->total) - self::toCents($paid));

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
}

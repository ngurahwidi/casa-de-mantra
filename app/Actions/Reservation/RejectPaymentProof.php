<?php

namespace App\Actions\Reservation;

use App\Enums\OutboxEventStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\Reservation\PaymentNotAcceptedException;
use App\Models\OutboxEvent;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * An admin finds that a guest's transfer proof does not match a payment received.
 *
 * The proof is marked failed and its file is kept for the record. The reservation itself does
 * not change: the guest can upload another proof, and without one waiting the reservation can
 * expire again once its payment window has ended.
 */
class RejectPaymentProof
{
    /**
     * @throws PaymentNotAcceptedException when the proof is no longer pending
     */
    public function handle(Reservation $reservation, string $proofId, User $admin): Payment
    {
        return DB::transaction(function () use ($reservation, $proofId, $admin): Payment {
            $locked = Reservation::query()->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();

            $proof = $locked->pendingPaymentProofs()->whereKey($proofId)->first()
                ?? throw PaymentNotAcceptedException::proofNoLongerPending();

            $proof->update([
                'status' => PaymentStatus::Failed,
                'verified_by' => $admin->getKey(),
            ]);

            OutboxEvent::create([
                'type' => 'payment.proof_rejected',
                'payload' => [
                    'reservation_id' => $locked->getKey(),
                    'code' => $locked->code,
                    'payment_id' => $proof->getKey(),
                    'admin_user_id' => $admin->getKey(),
                ],
                'status' => OutboxEventStatus::Pending,
            ]);

            return $proof;
        });
    }
}

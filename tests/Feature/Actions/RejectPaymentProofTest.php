<?php

namespace Tests\Feature\Actions;

use App\Actions\Reservation\RejectPaymentProof;
use App\Enums\PaymentStatus;
use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\Reservation\PaymentNotAcceptedException;
use App\Models\OutboxEvent;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RejectPaymentProofTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejecting_marks_the_proof_failed_and_leaves_the_reservation_as_it_was(): void
    {
        $admin = User::factory()->create();
        $reservation = Reservation::factory()->awaitingPayment()->create();
        $proof = Payment::factory()->for($reservation)->create(['proof_url' => 'payment-proofs/blurry.jpg']);

        $this->app->make(RejectPaymentProof::class)->handle($reservation, $proof->id, $admin);

        $proof->refresh();
        $this->assertSame(PaymentStatus::Failed, $proof->status);
        $this->assertSame($admin->id, $proof->verified_by);
        $this->assertSame('payment-proofs/blurry.jpg', $proof->proof_url);
        $this->assertNull($proof->paid_at);

        $reservation->refresh();
        $this->assertSame(ReservationStatus::AwaitingPayment, $reservation->status);
        $this->assertSame(ReservationPaymentStatus::Unpaid, $reservation->payment_status);

        $event = OutboxEvent::query()->sole();
        $this->assertSame('payment.proof_rejected', $event->type);
        $this->assertSame($proof->id, $event->payload['payment_id']);
        $this->assertSame($admin->id, $event->payload['admin_user_id']);
    }

    public function test_a_proof_already_dealt_with_is_refused(): void
    {
        $reservation = Reservation::factory()->awaitingPayment()->create();
        $paid = Payment::factory()->paid()->for($reservation)->create();

        $this->assertRefused($reservation, $paid);

        $this->assertSame(PaymentStatus::Paid, $paid->refresh()->status);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_a_proof_of_another_reservation_is_refused(): void
    {
        $reservation = Reservation::factory()->awaitingPayment()->create();
        $otherProof = Payment::factory()->for(Reservation::factory()->awaitingPayment())->create();

        $this->assertRefused($reservation, $otherProof);

        $this->assertSame(PaymentStatus::Pending, $otherProof->refresh()->status);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    private function assertRefused(Reservation $reservation, Payment $proof): void
    {
        try {
            $this->app->make(RejectPaymentProof::class)->handle($reservation, $proof->id, User::factory()->create());
            $this->fail('Expected PaymentNotAcceptedException.');
        } catch (PaymentNotAcceptedException $e) {
            $this->assertSame('This transfer proof has already been dealt with. Reload the page to see its status.', $e->getMessage());
        }
    }
}

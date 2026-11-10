<?php

namespace Tests\Feature\Services;

use App\Enums\NotificationLogStatus;
use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Jobs\SendReservationNotification;
use App\Mail\ReservationNotificationMail;
use App\Models\NotificationLog;
use App\Models\Payment;
use App\Models\PropertyProfile;
use App\Models\Reservation;
use App\Services\Notification\ReservationNotifier;
use App\Services\Notification\ReservationReminders;
use App\Services\Reservation\ReservationStateMachine;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReservationRemindersTest extends TestCase
{
    use RefreshDatabase;

    private const GUEST_EMAIL = 'guest@example.com';

    private const PROPERTY_EMAIL = 'stay@villa.example';

    public function test_a_request_unanswered_past_the_response_time_reminds_the_property(): void
    {
        $this->seedNotificationSetup(pendingReminderHours: 12);
        $reservation = $this->websiteRequest('2026-12-01 08:00:00');
        $this->travelTo('2026-12-01 20:00:00');
        Mail::fake();

        $queued = app(ReservationReminders::class)->remindAdminOfUnansweredRequests();

        $this->assertSame(1, $queued);
        Mail::assertSent(ReservationNotificationMail::class, fn (ReservationNotificationMail $mail): bool => $mail->hasTo(self::PROPERTY_EMAIL)
            && $mail->subjectLine === "Reminder: booking request {$reservation->code} is still waiting for a reply");
        Mail::assertSentCount(1);
        $log = NotificationLog::query()->where('template', 'admin_pending_reminder')->sole();
        $this->assertSame($reservation->id, $log->reservation_id);
        $this->assertSame(NotificationLogStatus::Sent, $log->status);
    }

    public function test_a_request_within_the_response_time_is_not_reminded(): void
    {
        $this->seedNotificationSetup(pendingReminderHours: 12);
        $this->websiteRequest('2026-12-01 08:00:00');
        $this->travelTo('2026-12-01 19:59:59');
        Mail::fake();

        $queued = app(ReservationReminders::class)->remindAdminOfUnansweredRequests();

        $this->assertSame(0, $queued);
        Mail::assertNothingSent();
    }

    public function test_running_the_reminders_again_sends_each_reminder_once(): void
    {
        $this->seedNotificationSetup(pendingReminderHours: 12);
        $this->websiteRequest('2026-12-01 08:00:00');
        $this->travelTo('2026-12-01 20:00:00');
        Mail::fake();
        app(ReservationReminders::class)->remindAdminOfUnansweredRequests();
        $this->travelTo('2026-12-02 08:00:00');

        $queued = app(ReservationReminders::class)->remindAdminOfUnansweredRequests();

        $this->assertSame(0, $queued);
        Mail::assertSentCount(1);
    }

    /**
     * @return array<string, array{int, string, string}>
     */
    public static function paymentWindows(): array
    {
        return [
            'one-day window: 12 hours before it ends' => [24, '2026-12-01 11:59:59', '2026-12-01 12:00:00'],
            'two-day window: 12 hours before it ends' => [48, '2026-12-02 11:59:59', '2026-12-02 12:00:00'],
            'six-hour window: halfway through' => [6, '2026-12-01 02:59:59', '2026-12-01 03:00:00'],
        ];
    }

    #[DataProvider('paymentWindows')]
    public function test_the_guest_is_reminded_to_pay_before_the_payment_window_ends(int $windowHours, string $notYet, string $due): void
    {
        $this->seedNotificationSetup();
        $reservation = $this->websiteRequest('2026-11-30 12:00:00');
        $this->approve($reservation, '2026-12-01 00:00:00', $windowHours);
        Mail::fake();
        $this->travelTo($notYet);
        $queuedEarly = app(ReservationReminders::class)->remindGuestsOfPaymentDue();
        $this->travelTo($due);

        $queued = app(ReservationReminders::class)->remindGuestsOfPaymentDue();

        $this->assertSame(0, $queuedEarly);
        $this->assertSame(1, $queued);
        Mail::assertSent(ReservationNotificationMail::class, fn (ReservationNotificationMail $mail): bool => $mail->hasTo(self::GUEST_EMAIL)
            && $mail->subjectLine === "Reminder: payment for your booking {$reservation->code} is due soon");
        Mail::assertSentCount(1);
    }

    public function test_a_guest_who_uploaded_a_transfer_proof_is_not_reminded_to_pay(): void
    {
        $this->seedNotificationSetup();
        $reservation = $this->websiteRequest('2026-11-30 12:00:00');
        $this->approve($reservation, '2026-12-01 00:00:00');
        Payment::factory()->for($reservation)->create(['proof_url' => 'payment-proofs/transfer.jpg']);
        $this->travelTo('2026-12-01 18:00:00');
        Mail::fake();

        $queued = app(ReservationReminders::class)->remindGuestsOfPaymentDue();

        $this->assertSame(0, $queued);
        Mail::assertNothingSent();
    }

    public function test_a_guest_who_paid_a_deposit_is_not_reminded_to_pay(): void
    {
        $this->seedNotificationSetup();
        $reservation = $this->websiteRequest('2026-11-30 12:00:00');
        $this->approve($reservation, '2026-12-01 00:00:00');
        $reservation->update(['payment_status' => ReservationPaymentStatus::Deposit]);
        $this->travelTo('2026-12-01 18:00:00');
        Mail::fake();

        $queued = app(ReservationReminders::class)->remindGuestsOfPaymentDue();

        $this->assertSame(0, $queued);
        Mail::assertNothingSent();
    }

    public function test_a_reactivated_reservation_is_reminded_about_its_new_payment_window(): void
    {
        $this->seedNotificationSetup();
        $reservation = $this->websiteRequest('2026-11-30 12:00:00');
        $this->approve($reservation, '2026-12-01 00:00:00');
        Mail::fake();
        $this->travelTo('2026-12-01 12:00:00');
        app(ReservationReminders::class)->remindGuestsOfPaymentDue();
        $this->travelTo('2026-12-02 00:00:01');
        app(ReservationStateMachine::class)->transition($reservation, ReservationStatus::Expired);
        $this->approve($reservation, '2026-12-03 00:00:00');
        $this->travelTo('2026-12-03 12:00:00');

        $queued = app(ReservationReminders::class)->remindGuestsOfPaymentDue();

        $this->assertSame(1, $queued);
        $this->assertSame(2, NotificationLog::query()->where('template', 'payment_reminder')->count());
    }

    public function test_a_queued_payment_reminder_is_skipped_once_the_reservation_is_confirmed(): void
    {
        $this->seedNotificationSetup();
        $reservation = $this->websiteRequest('2026-11-30 12:00:00');
        $this->approve($reservation, '2026-12-01 00:00:00');
        $this->travelTo('2026-12-01 12:00:00');
        Queue::fake([SendReservationNotification::class]);
        app(ReservationReminders::class)->remindGuestsOfPaymentDue();
        $reservation->update(['payment_status' => ReservationPaymentStatus::Paid]);
        app(ReservationStateMachine::class)->transition($reservation, ReservationStatus::Confirmed);
        $reminder = NotificationLog::query()->where('template', 'payment_reminder')->sole();
        Mail::fake();

        app(ReservationNotifier::class)->deliver($reminder);

        Mail::assertNothingSent();
        $reminder->refresh();
        $this->assertSame(NotificationLogStatus::Skipped, $reminder->status);
        $this->assertSame('Not sent: the reservation is confirmed now and no longer needs this reminder.', $reminder->error_message);
        $this->assertNull($reminder->sent_at);
    }

    /**
     * Check-in is 24 Dec 2026; the property is in Asia/Makassar (UTC+8), where 21 Dec starts at
     * 20 Dec 16:00 UTC.
     *
     * @return array<string, array{string, int}>
     */
    public static function preArrivalMoments(): array
    {
        return [
            'four days before, property time' => ['2026-12-20 15:59:59', 0],
            'three days before, property time' => ['2026-12-20 16:00:00', 1],
            'the day before, catching up' => ['2026-12-23 08:00:00', 1],
            'on the check-in day' => ['2026-12-23 16:00:00', 0],
        ];
    }

    #[DataProvider('preArrivalMoments')]
    public function test_the_guest_is_reminded_three_days_before_check_in(string $now, int $expectedQueued): void
    {
        $this->seedNotificationSetup();
        $reservation = $this->websiteRequest('2026-11-30 12:00:00');
        $this->approve($reservation, '2026-12-01 00:00:00');
        $this->confirm($reservation, '2026-12-01 10:00:00');
        $this->travelTo($now);
        Mail::fake();

        $queued = app(ReservationReminders::class)->sendPreArrivalReminders();

        $this->assertSame($expectedQueued, $queued);
        Mail::assertSentCount($expectedQueued);
        $this->assertCount($expectedQueued, Mail::sent(ReservationNotificationMail::class, fn (ReservationNotificationMail $mail): bool => $mail->hasTo(self::GUEST_EMAIL)
            && $mail->subjectLine === 'See you soon at Villa Aruna'));
    }

    public function test_a_stay_confirmed_on_its_reminder_day_gets_no_pre_arrival_reminder(): void
    {
        $this->seedNotificationSetup();
        $reservation = $this->websiteRequest('2026-12-20 12:00:00');
        $this->approve($reservation, '2026-12-20 14:00:00');
        $this->confirm($reservation, '2026-12-20 16:00:00');
        $this->travelTo('2026-12-21 08:00:00');
        Mail::fake();

        $queued = app(ReservationReminders::class)->sendPreArrivalReminders();

        $this->assertSame(0, $queued);
        Mail::assertNothingSent();
    }

    public function test_a_booking_entered_by_an_admin_gets_no_pre_arrival_reminder(): void
    {
        $this->seedNotificationSetup();
        $this->travelTo('2026-11-30 12:00:00');
        $reservation = Reservation::factory()->confirmed()->create([
            'source' => ReservationSource::BookingCom,
            'guest_email' => self::GUEST_EMAIL,
        ]);
        app(ReservationStateMachine::class)->recordCreated($reservation);
        $this->travelTo('2026-12-21 08:00:00');
        Mail::fake();

        $queued = app(ReservationReminders::class)->sendPreArrivalReminders();

        $this->assertSame(0, $queued);
        Mail::assertNothingSent();
    }

    private function seedNotificationSetup(int $pendingReminderHours = 12): void
    {
        PropertyProfile::factory()->create([
            'name' => 'Villa Aruna',
            'email' => self::PROPERTY_EMAIL,
            'timezone' => 'Asia/Makassar',
            'pending_reminder_hours' => $pendingReminderHours,
        ]);
        $this->seed(NotificationTemplateSeeder::class);
    }

    /**
     * A website request for 24 to 27 Dec 2026, recorded as created at the given time.
     */
    private function websiteRequest(string $requestedAt): Reservation
    {
        $this->travelTo($requestedAt);
        $reservation = Reservation::factory()->create([
            'source' => ReservationSource::DirectWeb,
            'guest_email' => self::GUEST_EMAIL,
        ]);
        app(ReservationStateMachine::class)->recordCreated($reservation);

        return $reservation;
    }

    private function approve(Reservation $reservation, string $approvedAt, int $windowHours = 24): void
    {
        $this->travelTo($approvedAt);
        $reservation->expires_at = now()->addHours($windowHours);
        app(ReservationStateMachine::class)->transition($reservation, ReservationStatus::AwaitingPayment);
    }

    private function confirm(Reservation $reservation, string $confirmedAt): void
    {
        $this->travelTo($confirmedAt);
        $reservation->payment_status = ReservationPaymentStatus::Paid;
        $reservation->confirmed_at = now();
        app(ReservationStateMachine::class)->transition($reservation, ReservationStatus::Confirmed);
    }
}

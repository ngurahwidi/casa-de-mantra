<?php

namespace Tests\Feature\Services;

use App\Enums\NotificationLogStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Events\ReservationStatusChanged;
use App\Jobs\SendReservationNotification;
use App\Listeners\QueueReservationNotifications;
use App\Mail\ContactMessageMail;
use App\Mail\ReservationNotificationMail;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\PropertyProfile;
use App\Models\Reservation;
use App\Models\ReservationStatusLog;
use App\Services\Reservation\ReservationStateMachine;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ReservationNotifierTest extends TestCase
{
    use RefreshDatabase;

    private const GUEST_EMAIL = 'guest@example.com';

    private const PROPERTY_EMAIL = 'stay@villa.example';

    /**
     * @return array<string, array{ReservationStatus, ReservationStatus, string, string}>
     */
    public static function notifiedTransitions(): array
    {
        return [
            'approved' => [ReservationStatus::Pending, ReservationStatus::AwaitingPayment, 'reservation_awaiting_payment', 'is approved: payment required'],
            'confirmed' => [ReservationStatus::AwaitingPayment, ReservationStatus::Confirmed, 'reservation_confirmed', 'is confirmed'],
            'rejected' => [ReservationStatus::Pending, ReservationStatus::Rejected, 'reservation_rejected', 'Update on your booking request'],
            'expired' => [ReservationStatus::AwaitingPayment, ReservationStatus::Expired, 'reservation_expired', 'has expired'],
            'cancelled' => [ReservationStatus::Confirmed, ReservationStatus::Cancelled, 'reservation_cancelled', 'has been cancelled'],
        ];
    }

    #[DataProvider('notifiedTransitions')]
    public function test_a_transition_emails_the_guest_and_logs_it_as_sent(
        ReservationStatus $from,
        ReservationStatus $to,
        string $template,
        string $subject,
    ): void {
        $this->seedNotificationSetup();
        $reservation = $this->reservation($from);
        Mail::fake();

        app(ReservationStateMachine::class)->transition($reservation, $to);

        Mail::assertSentCount(1);
        Mail::assertSent(ReservationNotificationMail::class, fn (ReservationNotificationMail $mail): bool => $mail->hasTo(self::GUEST_EMAIL)
            && str_contains($mail->subjectLine, $subject)
            && str_contains($mail->subjectLine, $reservation->code));
        $log = NotificationLog::query()->sole();
        $this->assertSame($template, $log->template);
        $this->assertSame($reservation->id, $log->reservation_id);
        $this->assertSame(NotificationLogStatus::Sent, $log->status);
        $this->assertNotNull($log->sent_at);
    }

    public function test_a_new_website_request_emails_the_guest_and_the_property(): void
    {
        $this->seedNotificationSetup();
        $reservation = $this->reservation();
        Mail::fake();

        app(ReservationStateMachine::class)->recordCreated($reservation);

        Mail::assertSent(ReservationNotificationMail::class, fn (ReservationNotificationMail $mail): bool => $mail->hasTo(self::GUEST_EMAIL)
            && str_contains($mail->subjectLine, 'We received your booking request'));
        Mail::assertSent(ReservationNotificationMail::class, fn (ReservationNotificationMail $mail): bool => $mail->hasTo(self::PROPERTY_EMAIL)
            && str_contains($mail->subjectLine, 'New booking request'));
        Mail::assertSentCount(2);
        $this->assertEqualsCanonicalizing(
            ['reservation_received', 'admin_new_request'],
            NotificationLog::query()->pluck('template')->all(),
        );
    }

    public function test_emails_are_sent_from_the_property_profile(): void
    {
        $this->seedNotificationSetup();
        $reservation = $this->reservation();
        Mail::fake();

        app(ReservationStateMachine::class)->transition($reservation, ReservationStatus::Rejected);

        Mail::assertSent(ReservationNotificationMail::class, fn (ReservationNotificationMail $mail): bool => $mail->hasFrom(self::PROPERTY_EMAIL, 'Villa Aruna'));
    }

    public function test_the_contact_form_email_is_sent_from_the_property_profile(): void
    {
        $this->seedNotificationSetup();
        Mail::fake();

        $this->post(route('contact.store'), [
            'name' => 'Jane Guest',
            'email' => 'jane@example.com',
            'message' => 'We would like to stay for five nights in December.',
        ]);

        Mail::assertSent(ContactMessageMail::class, fn (ContactMessageMail $mail): bool => $mail->hasFrom(self::PROPERTY_EMAIL, 'Villa Aruna'));
    }

    public function test_emails_go_through_the_queue_with_a_queued_log(): void
    {
        $this->seedNotificationSetup();
        $reservation = $this->reservation();
        Queue::fake([SendReservationNotification::class]);
        Mail::fake();

        app(ReservationStateMachine::class)->transition($reservation, ReservationStatus::Rejected);

        $log = NotificationLog::query()->sole();
        $this->assertSame(NotificationLogStatus::Queued, $log->status);
        $this->assertNull($log->sent_at);
        Queue::assertPushed(SendReservationNotification::class, 1);
        Queue::assertPushed(SendReservationNotification::class, fn (SendReservationNotification $job): bool => $job->notificationLog->is($log));
        Mail::assertNothingSent();
    }

    public function test_a_queue_worker_delivers_the_email(): void
    {
        $this->seedNotificationSetup();
        $reservation = $this->reservation();
        config(['queue.default' => 'database']);
        Mail::fake();

        app(ReservationStateMachine::class)->transition($reservation, ReservationStatus::Rejected);

        Mail::assertNothingSent();
        $this->artisan('queue:work', ['--once' => true]);

        Mail::assertSent(ReservationNotificationMail::class, 1);
        $this->assertSame(NotificationLogStatus::Sent, NotificationLog::query()->sole()->status);
    }

    public function test_a_failed_attempt_stays_queued_until_the_retries_run_out(): void
    {
        $this->seedNotificationSetup();
        $reservation = $this->reservation();
        config(['queue.default' => 'database']);
        Exceptions::fake();
        Mail::shouldReceive('to')->andThrow(new RuntimeException('Connection to the mail server timed out.'));

        app(ReservationStateMachine::class)->transition($reservation, ReservationStatus::Rejected);
        $this->artisan('queue:work', ['--once' => true]);

        $this->assertSame(NotificationLogStatus::Queued, NotificationLog::query()->sole()->status);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_a_rolled_back_transition_notifies_nobody(): void
    {
        $this->seedNotificationSetup();
        $reservation = $this->reservation();
        Mail::fake();

        $this->assertThrows(fn () => DB::transaction(function () use ($reservation): void {
            app(ReservationStateMachine::class)->transition($reservation, ReservationStatus::Rejected);

            throw new RuntimeException('Something later in the action failed.');
        }), RuntimeException::class);

        Mail::assertNothingSent();
        $this->assertSame(0, NotificationLog::query()->count());
    }

    public function test_a_completed_stay_sends_no_email(): void
    {
        $this->seedNotificationSetup();
        $reservation = $this->reservation(ReservationStatus::Confirmed);
        Mail::fake();

        app(ReservationStateMachine::class)->transition($reservation, ReservationStatus::Completed);

        Mail::assertNothingSent();
        $this->assertSame(0, NotificationLog::query()->count());
    }

    public function test_a_booking_entered_by_an_admin_sends_no_email(): void
    {
        $this->seedNotificationSetup();
        $reservation = $this->reservation(ReservationStatus::Confirmed, ReservationSource::BookingCom);
        Mail::fake();

        app(ReservationStateMachine::class)->recordCreated($reservation);
        app(ReservationStateMachine::class)->transition($reservation, ReservationStatus::Cancelled, note: 'Cancelled on the OTA.');

        Mail::assertNothingSent();
        $this->assertSame(0, NotificationLog::query()->count());
    }

    public function test_an_inactive_template_sends_nothing_and_logs_nothing(): void
    {
        $this->seedNotificationSetup();
        NotificationTemplate::query()->where('code', 'reservation_rejected')->update(['is_active' => false]);
        $reservation = $this->reservation();
        Mail::fake();

        app(ReservationStateMachine::class)->transition($reservation, ReservationStatus::Rejected);

        Mail::assertNothingSent();
        $this->assertSame(0, NotificationLog::query()->count());
    }

    public function test_a_missing_template_is_logged_as_failed(): void
    {
        $this->seedNotificationSetup();
        NotificationTemplate::query()->where('code', 'reservation_rejected')->delete();
        $reservation = $this->reservation();
        Mail::fake();

        app(ReservationStateMachine::class)->transition($reservation, ReservationStatus::Rejected);

        Mail::assertNothingSent();
        $log = NotificationLog::query()->sole();
        $this->assertSame(NotificationLogStatus::Failed, $log->status);
        $this->assertSame('Notification template reservation_rejected does not exist.', $log->error_message);
    }

    public function test_a_delivery_failure_is_logged_with_the_error(): void
    {
        $this->seedNotificationSetup();
        $reservation = $this->reservation();
        Mail::shouldReceive('to')->andThrow(new RuntimeException('Connection to the mail server timed out.'));

        $this->assertThrows(
            fn () => app(ReservationStateMachine::class)->transition($reservation, ReservationStatus::Rejected),
            RuntimeException::class,
            'Connection to the mail server timed out.',
        );

        $log = NotificationLog::query()->sole();
        $this->assertSame(NotificationLogStatus::Failed, $log->status);
        $this->assertSame('Connection to the mail server timed out.', $log->error_message);
        $this->assertNull($log->sent_at);
    }

    public function test_an_admin_notification_without_a_property_email_is_logged_as_failed(): void
    {
        $this->seedNotificationSetup();
        PropertyProfile::query()->update(['email' => null]);
        PropertyProfile::forgetCurrent();
        $reservation = $this->reservation();
        $log = NotificationLog::factory()->create([
            'reservation_id' => $reservation->id,
            'template' => 'admin_new_request',
            'status' => NotificationLogStatus::Queued,
        ]);
        Mail::fake();

        $this->assertThrows(fn () => SendReservationNotification::dispatchSync($log), RuntimeException::class);

        Mail::assertNothingSent();
        $this->assertSame(NotificationLogStatus::Failed, $log->refresh()->status);
        $this->assertSame('No recipient email address for admin_new_request.', $log->error_message);
    }

    public function test_a_job_that_runs_again_does_not_send_a_second_email(): void
    {
        $this->seedNotificationSetup();
        $reservation = $this->reservation();
        Mail::fake();

        app(ReservationStateMachine::class)->transition($reservation, ReservationStatus::Rejected);
        SendReservationNotification::dispatchSync(NotificationLog::query()->sole());

        Mail::assertSentCount(1);
    }

    public function test_a_late_failure_does_not_overwrite_a_sent_notification(): void
    {
        $this->seedNotificationSetup();
        $reservation = $this->reservation();
        Mail::fake();
        app(ReservationStateMachine::class)->transition($reservation, ReservationStatus::Rejected);
        $log = NotificationLog::query()->sole();

        (new SendReservationNotification($log))->failed(new RuntimeException('Worker timed out.'));

        $this->assertSame(NotificationLogStatus::Sent, $log->refresh()->status);
        $this->assertNull($log->error_message);
    }

    public function test_handling_the_same_change_twice_sends_one_email(): void
    {
        $this->seedNotificationSetup();
        $reservation = $this->reservation();
        Mail::fake();

        app(ReservationStateMachine::class)->transition($reservation, ReservationStatus::Rejected);
        app(QueueReservationNotifications::class)->handle(
            new ReservationStatusChanged($reservation, ReservationStatusLog::query()->sole()),
        );

        Mail::assertSentCount(1);
        $this->assertSame(1, NotificationLog::query()->count());
    }

    public function test_a_reactivated_reservation_gets_the_payment_request_again(): void
    {
        $this->seedNotificationSetup();
        $reservation = $this->reservation(ReservationStatus::Pending);
        $stateMachine = app(ReservationStateMachine::class);
        Mail::fake();

        $stateMachine->transition($reservation, ReservationStatus::AwaitingPayment);
        $stateMachine->transition($reservation, ReservationStatus::Expired);
        $stateMachine->transition($reservation, ReservationStatus::AwaitingPayment);

        $this->assertSame(2, NotificationLog::query()->where('template', 'reservation_awaiting_payment')->count());
        Mail::assertSentCount(3);
    }

    private function seedNotificationSetup(): void
    {
        PropertyProfile::factory()->create(['name' => 'Villa Aruna', 'email' => self::PROPERTY_EMAIL]);
        $this->seed(NotificationTemplateSeeder::class);
    }

    private function reservation(
        ReservationStatus $status = ReservationStatus::Pending,
        ReservationSource $source = ReservationSource::DirectWeb,
    ): Reservation {
        return Reservation::factory()->create([
            'status' => $status,
            'source' => $source,
            'guest_email' => self::GUEST_EMAIL,
        ]);
    }
}

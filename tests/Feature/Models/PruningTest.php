<?php

namespace Tests\Feature\Models;

use App\Enums\OutboxEventStatus;
use App\Enums\ReservationStatus;
use App\Models\NotificationLog;
use App\Models\OutboxEvent;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The prunable() rules of NotificationLog and OutboxEvent, run through model:prune as the
 * scheduler does (retention: 365 days for logs, 90 days for processed outbox events).
 */
class PruningTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{ReservationStatus}>
     */
    public static function finishedStatuses(): array
    {
        return [
            'completed' => [ReservationStatus::Completed],
            'rejected' => [ReservationStatus::Rejected],
            'cancelled' => [ReservationStatus::Cancelled],
            'expired' => [ReservationStatus::Expired],
        ];
    }

    /**
     * @return array<string, array{ReservationStatus}>
     */
    public static function inProgressStatuses(): array
    {
        return [
            'pending' => [ReservationStatus::Pending],
            'awaiting payment' => [ReservationStatus::AwaitingPayment],
            'confirmed' => [ReservationStatus::Confirmed],
        ];
    }

    #[DataProvider('finishedStatuses')]
    public function test_a_notification_log_past_retention_of_a_finished_reservation_is_pruned(ReservationStatus $status): void
    {
        $log = $this->notificationLogCreatedAt('2025-11-10 23:59:59', $status);
        $this->travelTo('2026-11-11 00:00:00');

        $this->pruneNotificationLogs();

        $this->assertModelMissing($log);
    }

    public function test_a_notification_log_within_retention_is_kept(): void
    {
        $log = $this->notificationLogCreatedAt('2025-11-11 00:00:00', ReservationStatus::Completed);
        $this->travelTo('2026-11-11 00:00:00');

        $this->pruneNotificationLogs();

        $this->assertModelExists($log);
    }

    #[DataProvider('inProgressStatuses')]
    public function test_a_notification_log_of_a_reservation_in_progress_is_kept(ReservationStatus $status): void
    {
        $log = $this->notificationLogCreatedAt('2025-01-01 00:00:00', $status);
        $this->travelTo('2026-11-11 00:00:00');

        $this->pruneNotificationLogs();

        $this->assertModelExists($log);
    }

    public function test_a_queued_notification_log_is_kept(): void
    {
        $this->travelTo('2025-01-01 00:00:00');
        $log = NotificationLog::factory()
            ->for(Reservation::factory()->state(['status' => ReservationStatus::Cancelled]))
            ->create();
        $this->travelTo('2026-11-11 00:00:00');

        $this->pruneNotificationLogs();

        $this->assertModelExists($log);
    }

    public function test_the_notification_log_retention_comes_from_config(): void
    {
        config(['retention.notification_log_days' => 30]);
        $log = $this->notificationLogCreatedAt('2026-10-11 23:59:59', ReservationStatus::Completed);
        $this->travelTo('2026-11-11 00:00:00');

        $this->pruneNotificationLogs();

        $this->assertModelMissing($log);
    }

    public function test_a_processed_outbox_event_past_retention_is_pruned(): void
    {
        $this->travelTo('2026-08-12 23:59:59');
        $event = OutboxEvent::factory()->processed()->create();
        $this->travelTo('2026-11-11 00:00:00');

        $this->pruneOutboxEvents();

        $this->assertModelMissing($event);
    }

    public function test_a_processed_outbox_event_within_retention_is_kept(): void
    {
        $this->travelTo('2026-08-13 00:00:00');
        $event = OutboxEvent::factory()->processed()->create();
        $this->travelTo('2026-11-11 00:00:00');

        $this->pruneOutboxEvents();

        $this->assertModelExists($event);
    }

    public function test_pending_and_failed_outbox_events_are_kept_however_old(): void
    {
        $this->travelTo('2025-01-01 00:00:00');
        $pending = OutboxEvent::factory()->create();
        $failed = OutboxEvent::factory()->create(['status' => OutboxEventStatus::Failed]);
        $this->travelTo('2026-11-11 00:00:00');

        $this->pruneOutboxEvents();

        $this->assertModelExists($pending);
        $this->assertModelExists($failed);
    }

    private function notificationLogCreatedAt(string $createdAt, ReservationStatus $status): NotificationLog
    {
        $this->travelTo($createdAt);

        return NotificationLog::factory()
            ->for(Reservation::factory()->state(['status' => $status]))
            ->sent()
            ->create();
    }

    private function pruneNotificationLogs(): void
    {
        $this->artisan('model:prune', ['--model' => [NotificationLog::class]])->assertSuccessful();
    }

    private function pruneOutboxEvents(): void
    {
        $this->artisan('model:prune', ['--model' => [OutboxEvent::class]])->assertSuccessful();
    }
}

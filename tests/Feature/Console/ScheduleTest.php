<?php

namespace Tests\Feature\Console;

use App\Enums\OutboxEventStatus;
use App\Enums\ReservationStatus;
use App\Models\OutboxEvent;
use App\Models\Payment;
use App\Models\PropertyProfile;
use App\Models\Reservation;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The scheduled jobs of T9.1 (routes/console.php) and the commands they run.
 */
class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string}>
     */
    public static function scheduledCommands(): array
    {
        return [
            'expire stale reservations every ten minutes' => ['reservations:expire', '*/10 * * * *'],
            'complete checked-out stays hourly' => ['reservations:complete', '0 * * * *'],
            'send reminders every fifteen minutes' => ['reservations:remind', '*/15 * * * *'],
            'process the outbox every five minutes' => ['outbox:process', '*/5 * * * *'],
            'prune old logs and outbox events daily' => [
                "model:prune --model='App\\Models\\NotificationLog' --model='App\\Models\\OutboxEvent'",
                '0 0 * * *',
            ],
            'prune unsaved uploads daily' => ['uploads:prune', '0 0 * * *'],
        ];
    }

    #[DataProvider('scheduledCommands')]
    public function test_the_job_is_scheduled_without_overlapping(string $command, string $expression): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => Str::after((string) $event->command, "'artisan' ") === $command);

        $this->assertNotNull($event, "{$command} is not scheduled.");
        $this->assertSame($expression, $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_the_expire_command_expires_due_reservations(): void
    {
        $this->travelTo('2026-11-11 08:00:00');
        $reservation = Reservation::factory()->awaitingPayment()->create(['expires_at' => '2026-11-11 07:59:59']);

        $this->artisan('reservations:expire')
            ->expectsOutput('Expired 1 reservation(s).')
            ->assertSuccessful();

        $this->assertSame(ReservationStatus::Expired, $reservation->fresh()->status);
    }

    public function test_the_complete_command_completes_checked_out_stays(): void
    {
        $this->travelTo('2026-11-11 08:00:00');
        PropertyProfile::factory()->create(['timezone' => 'Asia/Makassar']);
        $reservation = Reservation::factory()->confirmed()->create(['check_in' => '2026-11-07', 'check_out' => '2026-11-10']);

        $this->artisan('reservations:complete')
            ->expectsOutput('Completed 1 reservation(s).')
            ->assertSuccessful();

        $this->assertSame(ReservationStatus::Completed, $reservation->fresh()->status);
    }

    public function test_the_remind_command_runs_every_reminder(): void
    {
        $this->travelTo('2026-11-11 08:00:00');
        PropertyProfile::factory()->create();

        $this->artisan('reservations:remind')
            ->expectsOutput('Queued 0 unanswered request reminder(s).')
            ->expectsOutput('Queued 0 payment reminder(s).')
            ->expectsOutput('Queued 0 pre-arrival reminder(s).')
            ->assertSuccessful();
    }

    public function test_the_outbox_command_processes_pending_events(): void
    {
        $event = OutboxEvent::factory()->create();

        $this->artisan('outbox:process')
            ->expectsOutput('Processed 1 outbox event(s).')
            ->assertSuccessful();

        $this->assertSame(OutboxEventStatus::Processed, $event->fresh()->status);
    }

    public function test_the_uploads_command_reports_what_it_deleted(): void
    {
        Storage::fake(Payment::PROOF_DISK);

        $this->artisan('uploads:prune')
            ->expectsOutput('Deleted 0 unsaved transfer proof(s) and 0 temporary upload(s).')
            ->assertSuccessful();
    }
}

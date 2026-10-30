<?php

namespace Tests\Feature\Services;

use App\Enums\OutboxEventStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\Reservation\InvalidStatusTransitionException;
use App\Models\OutboxEvent;
use App\Models\Reservation;
use App\Models\ReservationStatusLog;
use App\Models\User;
use App\Services\Reservation\ReservationStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ReservationStateMachineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every allowed transition, written out independently of the service.
     */
    private const VALID = [
        ['pending', 'awaiting_payment'],
        ['pending', 'rejected'],
        ['pending', 'cancelled'],
        ['awaiting_payment', 'confirmed'],
        ['awaiting_payment', 'cancelled'],
        ['awaiting_payment', 'expired'],
        ['confirmed', 'completed'],
        ['confirmed', 'cancelled'],
        ['expired', 'awaiting_payment'],
    ];

    private ReservationStateMachine $stateMachine;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-01 08:00:00');

        $this->stateMachine = $this->app->make(ReservationStateMachine::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, array{ReservationStatus, ReservationStatus}>
     */
    public static function validTransitions(): array
    {
        return self::pairs(valid: true);
    }

    /**
     * @return array<string, array{ReservationStatus, ReservationStatus}>
     */
    public static function invalidTransitions(): array
    {
        return self::pairs(valid: false);
    }

    #[DataProvider('validTransitions')]
    public function test_valid_transition_updates_status_and_writes_log_and_outbox_event(ReservationStatus $from, ReservationStatus $to): void
    {
        $admin = User::factory()->create();
        $reservation = Reservation::factory()->create(['status' => $from]);

        $result = $this->stateMachine->transition($reservation, $to, $admin, 'Checked with the owner.');

        $this->assertSame($reservation, $result);
        $this->assertSame($to, $reservation->status);
        $this->assertSame($to, $reservation->fresh()->status);

        $log = ReservationStatusLog::query()->sole();
        $this->assertSame($reservation->id, $log->reservation_id);
        $this->assertSame($admin->id, $log->admin_user_id);
        $this->assertSame($from, $log->from_status);
        $this->assertSame($to, $log->to_status);
        $this->assertSame('Checked with the owner.', $log->note);
        $this->assertSame('2026-11-01 08:00:00', $log->created_at->toDateTimeString());

        $event = OutboxEvent::query()->sole();
        $this->assertSame("reservation.{$to->value}", $event->type);
        $this->assertSame(OutboxEventStatus::Pending, $event->status);
        $this->assertNull($event->processed_at);
        // MySQL JSON does not keep key order, so compare without it.
        $this->assertEquals([
            'reservation_id' => $reservation->id,
            'code' => $reservation->code,
            'from_status' => $from->value,
            'to_status' => $to->value,
            'admin_user_id' => $admin->id,
            'note' => 'Checked with the owner.',
        ], $event->payload);
    }

    #[DataProvider('invalidTransitions')]
    public function test_invalid_transition_throws_and_changes_nothing(ReservationStatus $from, ReservationStatus $to): void
    {
        $reservation = Reservation::factory()->create(['status' => $from]);

        try {
            $this->stateMachine->transition($reservation, $to);
            $this->fail('Expected InvalidStatusTransitionException.');
        } catch (InvalidStatusTransitionException $e) {
            $this->assertSame($from, $e->from);
            $this->assertSame($to, $e->to);
            $this->assertSame("A reservation cannot change from {$from->value} to {$to->value}.", $e->getMessage());
        }

        $this->assertSame($from, $reservation->status);
        $this->assertSame($from, $reservation->fresh()->status);
        $this->assertDatabaseCount('reservation_status_logs', 0);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_system_transition_has_no_admin_user(): void
    {
        $reservation = Reservation::factory()->awaitingPayment()->create();

        $this->stateMachine->transition($reservation, ReservationStatus::Expired);

        $log = ReservationStatusLog::query()->sole();
        $this->assertNull($log->admin_user_id);
        $this->assertNull($log->note);
        $this->assertNull(OutboxEvent::query()->sole()->payload['admin_user_id']);
    }

    public function test_status_is_validated_against_the_database_not_a_stale_model(): void
    {
        $reservation = Reservation::factory()->create();
        $stale = Reservation::query()->findOrFail($reservation->id);

        $this->stateMachine->transition($reservation, ReservationStatus::Rejected);

        // The stale copy still says pending, which would allow awaiting_payment.
        $this->assertSame(ReservationStatus::Pending, $stale->status);

        $this->expectException(InvalidStatusTransitionException::class);
        $this->expectExceptionMessage('A reservation cannot change from rejected to awaiting_payment.');

        $this->stateMachine->transition($stale, ReservationStatus::AwaitingPayment);
    }

    public function test_log_records_the_database_status_as_from_status(): void
    {
        $reservation = Reservation::factory()->awaitingPayment()->create();
        // In memory only; the database still says awaiting_payment.
        $reservation->status = ReservationStatus::Pending;

        $this->stateMachine->transition($reservation, ReservationStatus::Confirmed);

        $this->assertSame(ReservationStatus::AwaitingPayment, ReservationStatusLog::query()->sole()->from_status);
        $this->assertSame('awaiting_payment', OutboxEvent::query()->sole()->payload['from_status']);
    }

    public function test_other_changed_attributes_are_saved_with_the_status(): void
    {
        $reservation = Reservation::factory()->create();
        $reservation->expires_at = now()->addHours(24);

        $this->stateMachine->transition($reservation, ReservationStatus::AwaitingPayment);

        $fresh = $reservation->fresh();
        $this->assertSame(ReservationStatus::AwaitingPayment, $fresh->status);
        $this->assertSame('2026-11-02 08:00:00', $fresh->expires_at->toDateTimeString());
    }

    public function test_failure_while_writing_the_outbox_event_rolls_back_the_transition(): void
    {
        $reservation = Reservation::factory()->create();

        OutboxEvent::creating(fn () => throw new RuntimeException('Outbox unavailable.'));

        try {
            $this->stateMachine->transition($reservation, ReservationStatus::Rejected);
            $this->fail('Expected RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertSame('Outbox unavailable.', $e->getMessage());
        }

        $this->assertSame(ReservationStatus::Pending, $reservation->fresh()->status);
        $this->assertDatabaseCount('reservation_status_logs', 0);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_full_lifecycle_keeps_an_ordered_history(): void
    {
        $admin = User::factory()->owner()->create();
        $reservation = Reservation::factory()->create();

        $this->stateMachine->transition($reservation, ReservationStatus::AwaitingPayment, $admin);
        Carbon::setTestNow('2026-11-02 08:00:00');
        $this->stateMachine->transition($reservation, ReservationStatus::Expired);
        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->stateMachine->transition($reservation, ReservationStatus::AwaitingPayment, $admin, 'Guest asked for more time.');
        Carbon::setTestNow('2026-11-02 10:00:00');
        $this->stateMachine->transition($reservation, ReservationStatus::Confirmed, $admin);
        Carbon::setTestNow('2026-12-28 00:00:00');
        $this->stateMachine->transition($reservation, ReservationStatus::Completed);

        $this->assertSame(
            [
                ['pending', 'awaiting_payment'],
                ['awaiting_payment', 'expired'],
                ['expired', 'awaiting_payment'],
                ['awaiting_payment', 'confirmed'],
                ['confirmed', 'completed'],
            ],
            $reservation->statusLogs->map(fn (ReservationStatusLog $log) => [$log->from_status->value, $log->to_status->value])->all(),
        );
        $this->assertSame(
            ['reservation.awaiting_payment', 'reservation.expired', 'reservation.awaiting_payment', 'reservation.confirmed', 'reservation.completed'],
            OutboxEvent::query()->orderBy('created_at')->orderBy('id')->pluck('type')->all(),
        );
    }

    public function test_record_created_writes_an_initial_log_and_created_event(): void
    {
        $admin = User::factory()->create();
        $reservation = Reservation::factory()->confirmed()->create();

        $this->stateMachine->recordCreated($reservation, $admin, 'Booked by phone.');

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);

        $log = ReservationStatusLog::query()->sole();
        $this->assertSame($reservation->id, $log->reservation_id);
        $this->assertSame($admin->id, $log->admin_user_id);
        $this->assertNull($log->from_status);
        $this->assertSame(ReservationStatus::Confirmed, $log->to_status);
        $this->assertSame('Booked by phone.', $log->note);

        $event = OutboxEvent::query()->sole();
        $this->assertSame('reservation.created', $event->type);
        $this->assertSame(OutboxEventStatus::Pending, $event->status);
        $this->assertEquals([
            'reservation_id' => $reservation->id,
            'code' => $reservation->code,
            'from_status' => null,
            'to_status' => 'confirmed',
            'admin_user_id' => $admin->id,
            'note' => 'Booked by phone.',
        ], $event->payload);
    }

    public function test_allowed_transitions_lists_the_next_statuses(): void
    {
        foreach (ReservationStatus::cases() as $from) {
            $expected = array_values(array_map(
                fn (array $pair) => ReservationStatus::from($pair[1]),
                array_filter(self::VALID, fn (array $pair) => $pair[0] === $from->value),
            ));

            $this->assertSame($expected, $this->stateMachine->allowedTransitions($from), "From {$from->value}");

            foreach (ReservationStatus::cases() as $to) {
                $this->assertSame(in_array($to, $expected, true), $this->stateMachine->canTransition($from, $to), "{$from->value} → {$to->value}");
            }
        }
    }

    public function test_final_statuses_allow_no_transition(): void
    {
        foreach ([ReservationStatus::Completed, ReservationStatus::Rejected, ReservationStatus::Cancelled] as $status) {
            $this->assertSame([], $this->stateMachine->allowedTransitions($status));
        }
    }

    /**
     * @return array<string, array{ReservationStatus, ReservationStatus}>
     */
    private static function pairs(bool $valid): array
    {
        $pairs = [];

        foreach (ReservationStatus::cases() as $from) {
            foreach (ReservationStatus::cases() as $to) {
                if (in_array([$from->value, $to->value], self::VALID, true) === $valid) {
                    $pairs["{$from->value} → {$to->value}"] = [$from, $to];
                }
            }
        }

        return $pairs;
    }
}

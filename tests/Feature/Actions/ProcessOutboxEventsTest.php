<?php

namespace Tests\Feature\Actions;

use App\Actions\Outbox\ProcessOutboxEvents;
use App\Enums\OutboxEventStatus;
use App\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessOutboxEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_events_are_marked_processed(): void
    {
        $this->travelTo('2026-11-11 08:00:00');
        $events = OutboxEvent::factory()->count(2)->create();
        $this->travelTo('2026-11-11 08:05:00');

        $processed = app(ProcessOutboxEvents::class)->handle();

        $this->assertSame(2, $processed);
        foreach ($events as $event) {
            $fresh = $event->fresh();
            $this->assertSame(OutboxEventStatus::Processed, $fresh->status);
            $this->assertSame('2026-11-11 08:05:00', $fresh->processed_at->toDateTimeString());
        }
    }

    public function test_processed_and_failed_events_are_left_as_they_are(): void
    {
        $this->travelTo('2026-11-10 08:00:00');
        $processedEvent = OutboxEvent::factory()->processed()->create();
        $failedEvent = OutboxEvent::factory()->create(['status' => OutboxEventStatus::Failed]);
        $this->travelTo('2026-11-11 08:00:00');

        $processed = app(ProcessOutboxEvents::class)->handle();

        $this->assertSame(0, $processed);
        $this->assertSame('2026-11-10 08:00:00', $processedEvent->fresh()->processed_at->toDateTimeString());
        $this->assertSame(OutboxEventStatus::Failed, $failedEvent->fresh()->status);
        $this->assertNull($failedEvent->fresh()->processed_at);
    }
}

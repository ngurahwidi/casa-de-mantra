<?php

namespace App\Actions\Outbox;

use App\Enums\OutboxEventStatus;
use App\Models\OutboxEvent;

/**
 * Processes pending outbox events. Meant to run on a schedule (T9.1).
 *
 * Phase 1 has no external consumer (no PMS or channel manager), so processing only marks the
 * events as processed. When an integration is added (T13.2), its consumer is called from here
 * for each event, in created_at order, and an event it cannot deliver is marked failed instead.
 *
 * The events are marked in a single statement, so a run that overlaps another cannot process an
 * event twice. An event written by a transaction that has not committed yet is not visible here
 * and is picked up by the next run.
 */
class ProcessOutboxEvents
{
    /**
     * @return int the number of events processed
     */
    public function handle(): int
    {
        return OutboxEvent::query()
            ->pending()
            ->update([
                'status' => OutboxEventStatus::Processed,
                'processed_at' => now(),
            ]);
    }
}

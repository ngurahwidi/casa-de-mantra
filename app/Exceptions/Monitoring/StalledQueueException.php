<?php

namespace App\Exceptions\Monitoring;

/**
 * Queued jobs have been waiting too long, which means no queue worker is running. Guest and admin
 * emails are not being sent.
 */
class StalledQueueException extends SystemHealthException
{
    public static function waiting(int $count, int $oldestWaitMinutes, int $maximumMinutes): self
    {
        return new self(
            "{$count} queued job(s) have waited longer than {$maximumMinutes} minutes (oldest: {$oldestWaitMinutes} minutes). Is the queue worker running?",
            ['waiting_jobs' => $count, 'oldest_wait_minutes' => $oldestWaitMinutes, 'maximum_minutes' => $maximumMinutes],
        );
    }
}

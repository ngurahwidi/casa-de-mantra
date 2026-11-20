<?php

namespace App\Exceptions\Monitoring;

/**
 * Queued jobs failed for good and were moved to the failed_jobs table. They are not retried
 * until someone runs `php artisan queue:retry`.
 */
class FailedJobsException extends SystemHealthException
{
    public static function since(int $count, int $total): self
    {
        return new self(
            "{$count} queued job(s) failed since the last check ({$total} in failed_jobs). Inspect them with `php artisan queue:failed`.",
            ['new_failed_jobs' => $count, 'total_failed_jobs' => $total],
        );
    }
}

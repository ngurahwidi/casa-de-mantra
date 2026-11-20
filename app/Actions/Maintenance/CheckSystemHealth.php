<?php

namespace App\Actions\Maintenance;

use App\Exceptions\Monitoring\DatabaseUnreachableException;
use App\Exceptions\Monitoring\FailedJobsException;
use App\Exceptions\Monitoring\LowDiskSpaceException;
use App\Exceptions\Monitoring\StalledQueueException;
use App\Exceptions\Monitoring\SystemHealthException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Checks the parts of the server the application depends on (T12.4). Meant to run hourly; the
 * command reports each problem to Sentry and the log.
 *
 * - Disk: the disk holding storage/ keeps at least monitoring.min_free_disk_percent free.
 * - Database: a query can be run. When it cannot, the checks below are skipped, since they read it.
 * - Failed jobs: no job landed in failed_jobs since the previous check. Each failure is reported
 *   once; failures stay in the table until someone retries or forgets them.
 * - Queue: on the database queue, no job has been ready for longer than
 *   monitoring.queue_max_wait_minutes, which would mean the worker is not running.
 */
class CheckSystemHealth
{
    /**
     * Cache key holding when failed_jobs was last checked.
     */
    public const FAILED_JOBS_CHECKED_AT = 'monitoring:failed-jobs-checked-at';

    /**
     * How far back the first check (or one after the cache was cleared) looks for failed jobs.
     */
    private const FIRST_FAILED_JOBS_WINDOW_MINUTES = 60;

    /**
     * @return list<SystemHealthException> one per failed check, empty when everything is healthy
     */
    public function handle(): array
    {
        $problems = [$this->checkDiskSpace()];

        try {
            DB::select('select 1');
        } catch (Throwable $exception) {
            return array_values(array_filter([...$problems, DatabaseUnreachableException::because($exception)]));
        }

        $problems[] = $this->checkFailedJobs();
        $problems[] = $this->checkQueue();

        return array_values(array_filter($problems));
    }

    private function checkDiskSpace(): ?LowDiskSpaceException
    {
        $path = storage_path();
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        if ($free === false || $total === false || $total <= 0) {
            return LowDiskSpaceException::unreadable($path);
        }

        $freePercent = $free / $total * 100;
        $minimumPercent = (int) config('monitoring.min_free_disk_percent');

        return $freePercent < $minimumPercent
            ? LowDiskSpaceException::at($path, $freePercent, $minimumPercent)
            : null;
    }

    private function checkFailedJobs(): ?FailedJobsException
    {
        $checkedAt = now();
        $previousCheck = Cache::get(self::FAILED_JOBS_CHECKED_AT);
        $since = is_string($previousCheck)
            ? Carbon::parse($previousCheck)
            : $checkedAt->copy()->subMinutes(self::FIRST_FAILED_JOBS_WINDOW_MINUTES);

        $failedJobs = DB::connection(config('queue.failed.database'))->table(config('queue.failed.table', 'failed_jobs'));
        $newFailures = (clone $failedJobs)->where('failed_at', '>', $since)->where('failed_at', '<=', $checkedAt)->count();

        Cache::forever(self::FAILED_JOBS_CHECKED_AT, $checkedAt->toIso8601String());

        return $newFailures > 0
            ? FailedJobsException::since($newFailures, $failedJobs->count())
            : null;
    }

    private function checkQueue(): ?StalledQueueException
    {
        if (config('queue.default') !== 'database') {
            return null;
        }

        $maximumMinutes = (int) config('monitoring.queue_max_wait_minutes');
        $now = now()->getTimestamp();

        $waiting = DB::connection(config('queue.connections.database.connection'))
            ->table(config('queue.connections.database.table', 'jobs'))
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $now - $maximumMinutes * 60);

        $count = (clone $waiting)->count();

        if ($count === 0) {
            return null;
        }

        $oldestWaitMinutes = intdiv($now - (int) $waiting->min('available_at'), 60);

        return StalledQueueException::waiting($count, $oldestWaitMinutes, $maximumMinutes);
    }
}

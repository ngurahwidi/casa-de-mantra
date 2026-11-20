<?php

namespace Tests\Feature\Actions;

use App\Actions\Maintenance\CheckSystemHealth;
use App\Exceptions\Monitoring\DatabaseUnreachableException;
use App\Exceptions\Monitoring\FailedJobsException;
use App\Exceptions\Monitoring\LowDiskSpaceException;
use App\Exceptions\Monitoring\StalledQueueException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Thresholds: the disk check is switched off (0% free required) unless a test is about it, and a
 * queued job may wait 15 minutes. The clock stands at 2026-11-11 08:00 UTC.
 */
class CheckSystemHealthTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-11 08:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'monitoring.min_free_disk_percent' => 0,
            'monitoring.queue_max_wait_minutes' => 15,
            'queue.default' => 'database',
        ]);
    }

    public function test_a_healthy_system_has_no_problems(): void
    {
        $this->travelTo(self::NOW);

        $this->assertSame([], app(CheckSystemHealth::class)->handle());
    }

    public function test_a_disk_with_less_free_space_than_the_minimum_is_reported(): void
    {
        $this->travelTo(self::NOW);
        config(['monitoring.min_free_disk_percent' => 100]);

        $problems = app(CheckSystemHealth::class)->handle();

        $this->assertCount(1, $problems);
        $this->assertInstanceOf(LowDiskSpaceException::class, $problems[0]);
        $this->assertSame(100, $problems[0]->context()['minimum_percent']);
    }

    public function test_an_unreachable_database_is_reported_and_skips_the_checks_that_read_it(): void
    {
        $this->travelTo(self::NOW);
        $this->failJobAt('2026-11-11 07:30:00');
        config([
            'database.connections.unreachable' => [...config('database.connections.mysql'), 'host' => '127.0.0.1', 'port' => 1],
            'database.default' => 'unreachable',
        ]);

        $problems = app(CheckSystemHealth::class)->handle();
        // RefreshDatabase rolls back on the default connection after the test.
        config(['database.default' => 'mysql']);

        $this->assertCount(1, $problems);
        $this->assertInstanceOf(DatabaseUnreachableException::class, $problems[0]);
    }

    public function test_the_first_check_reports_jobs_that_failed_in_the_last_hour(): void
    {
        $this->travelTo(self::NOW);
        $this->failJobAt('2026-11-11 06:59:59');
        $this->failJobAt('2026-11-11 07:00:01');

        $problems = app(CheckSystemHealth::class)->handle();

        $this->assertCount(1, $problems);
        $this->assertInstanceOf(FailedJobsException::class, $problems[0]);
        $this->assertSame(['new_failed_jobs' => 1, 'total_failed_jobs' => 2], $problems[0]->context());
    }

    public function test_a_failed_job_is_reported_once(): void
    {
        $this->travelTo(self::NOW);
        $this->failJobAt('2026-11-11 07:30:00');
        app(CheckSystemHealth::class)->handle();

        $this->travelTo('2026-11-11 09:00:00');
        $problems = app(CheckSystemHealth::class)->handle();

        $this->assertSame([], $problems);
    }

    public function test_a_job_that_failed_after_the_previous_check_is_reported(): void
    {
        $this->travelTo(self::NOW);
        app(CheckSystemHealth::class)->handle();
        $this->failJobAt('2026-11-11 08:30:00');

        $this->travelTo('2026-11-11 09:00:00');
        $problems = app(CheckSystemHealth::class)->handle();

        $this->assertCount(1, $problems);
        $this->assertInstanceOf(FailedJobsException::class, $problems[0]);
    }

    public function test_jobs_ready_for_longer_than_the_maximum_wait_are_reported(): void
    {
        $this->travelTo(self::NOW);
        $this->queueJobAvailableAt('2026-11-11 07:30:00');
        $this->queueJobAvailableAt('2026-11-11 07:44:59');

        $problems = app(CheckSystemHealth::class)->handle();

        $this->assertCount(1, $problems);
        $this->assertInstanceOf(StalledQueueException::class, $problems[0]);
        $this->assertSame(['waiting_jobs' => 2, 'oldest_wait_minutes' => 30, 'maximum_minutes' => 15], $problems[0]->context());
    }

    public function test_jobs_within_the_maximum_wait_reserved_or_delayed_are_not_reported(): void
    {
        $this->travelTo(self::NOW);
        $this->queueJobAvailableAt('2026-11-11 07:45:01');
        $this->queueJobAvailableAt('2026-11-11 07:00:00', reservedAt: '2026-11-11 07:59:00');
        $this->queueJobAvailableAt('2026-11-11 09:00:00');

        $this->assertSame([], app(CheckSystemHealth::class)->handle());
    }

    public function test_the_queue_is_not_checked_when_it_does_not_use_the_database(): void
    {
        $this->travelTo(self::NOW);
        config(['queue.default' => 'sync']);
        $this->queueJobAvailableAt('2026-11-11 07:00:00');

        $this->assertSame([], app(CheckSystemHealth::class)->handle());
    }

    private function failJobAt(string $failedAt): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'RuntimeException',
            'failed_at' => $failedAt,
        ]);
    }

    private function queueJobAvailableAt(string $availableAt, ?string $reservedAt = null): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => $reservedAt === null ? null : strtotime($reservedAt.' UTC'),
            'available_at' => strtotime($availableAt.' UTC'),
            'created_at' => strtotime(self::NOW.' UTC'),
        ]);
    }
}

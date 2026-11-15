<?php

namespace Tests\Support;

use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * For tests that race a reservation action in this process against one in another PHP process.
 *
 * The other process has its own database connection, so the data has to be committed: the test
 * class truncates instead of wrapping each test in a transaction, and empties the tables again
 * afterwards for the tests that follow.
 *
 * The usual shape: begin a transaction here and run the first action, start the other one with
 * startSupportScript(), wait until it is blocked on the unit lock, then commit.
 */
trait RunsConcurrentReservationActions
{
    use DatabaseTruncation;

    /**
     * Created by their migration, like the website source every request needs, so kept.
     *
     * @var list<string>
     */
    protected array $exceptTables = ['reservation_sources'];

    protected function setUpRunsConcurrentReservationActions(): void
    {
        $this->beforeApplicationDestroyed(function (): void {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $this->truncateTablesForAllConnections();
        });
    }

    /**
     * Runs a script from tests/Support in a new PHP process on this test's database.
     *
     * @param  list<string>  $arguments
     */
    protected function startSupportScript(string $script, array $arguments): InvokedProcess
    {
        return Process::timeout(60)
            ->env(['DB_DATABASE' => DB::connection()->getDatabaseName()])
            ->start([PHP_BINARY, base_path("tests/Support/{$script}"), ...$arguments]);
    }

    /**
     * Commits this test's transaction once the other process is blocked on the unit lock, then
     * returns what that process printed.
     */
    protected function commitOnceWaitingForUnitLock(InvokedProcess $process): string
    {
        try {
            $this->waitUntilWaitingForUnitLock($process);

            DB::commit();

            $result = $process->wait();
        } finally {
            if ($process->running()) {
                $process->signal(SIGKILL);
            }
        }

        $this->assertTrue($result->successful(), $result->output().$result->errorOutput());

        return $result->output();
    }

    /**
     * Waits until the other process has sent its locking read on `units`, which cannot return
     * while this test's transaction holds the lock.
     */
    protected function waitUntilWaitingForUnitLock(InvokedProcess $process): void
    {
        $deadline = microtime(true) + 15;

        while (microtime(true) < $deadline) {
            if (! $process->running()) {
                $this->fail('The other process ended without waiting for the lock: '.$process->output().$process->errorOutput());
            }

            // Without the PROCESS privilege this lists only threads of the same user, which is enough.
            $waiting = DB::table('information_schema.processlist')
                ->where('id', '!=', DB::raw('connection_id()'))
                ->where('db', DB::connection()->getDatabaseName())
                ->where('info', 'like', '%`units`%for update%')
                ->exists();

            if ($waiting) {
                return;
            }

            usleep(20_000);
        }

        $this->fail('The other process never reached the unit lock.');
    }
}

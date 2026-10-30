<?php

namespace Tests\Feature\Actions;

use App\Actions\Reservation\ApproveReservation;
use App\Enums\ReservationStatus;
use App\Models\PropertyProfile;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\RoomType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Two admins approve overlapping requests for the only unit at the same moment.
 *
 * The second approval runs in another PHP process with its own connection, so the data has
 * to be committed: this class truncates instead of wrapping each test in a transaction, and
 * empties the tables again afterwards for the tests that follow.
 */
class ApproveReservationConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->beforeApplicationDestroyed(function (): void {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $this->truncateTablesForAllConnections();
        });
    }

    public function test_only_one_of_two_concurrent_approvals_for_the_same_dates_succeeds(): void
    {
        PropertyProfile::factory()->create();
        $admin = User::factory()->create();
        $roomType = RoomType::factory()->published()->create();
        $ratePlan = RatePlan::factory()->for($roomType)->create();
        $unit = Unit::factory()->for($roomType)->create();

        // The other process runs on the real clock, so the stay is placed relative to it.
        $checkIn = now()->addMonth()->toDateString();
        [$first, $second] = Reservation::factory()->count(2)->create([
            'check_in' => $checkIn,
            'check_out' => now()->addMonth()->addDays(3)->toDateString(),
        ])->all();

        foreach ([$first, $second] as $reservation) {
            ReservationRoom::factory()->for($reservation)->create(['room_type_id' => $roomType->id, 'rate_plan_id' => $ratePlan->id]);
        }

        // The first approval succeeds but stays uncommitted, holding its locks...
        DB::beginTransaction();
        $this->app->make(ApproveReservation::class)->handle($first, $admin);

        // ...while the second one starts and has to wait for the unit lock.
        $process = Process::timeout(60)
            ->env(['DB_DATABASE' => DB::connection()->getDatabaseName()])
            ->start([PHP_BINARY, base_path('tests/Support/approve-reservation.php'), $second->id, (string) $admin->id]);

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
        $this->assertSame('not_available', $result->output());

        $this->assertSame(ReservationStatus::AwaitingPayment, $first->fresh()->status);
        $this->assertSame($unit->id, ReservationRoom::query()->whereBelongsTo($first)->value('unit_id'));

        $this->assertSame(ReservationStatus::Pending, $second->fresh()->status);
        $this->assertNull($second->fresh()->expires_at);
        $this->assertNull(ReservationRoom::query()->whereBelongsTo($second)->value('unit_id'));

        $this->assertDatabaseCount('reservation_status_logs', 1);
        $this->assertDatabaseCount('outbox_events', 1);
    }

    /**
     * Waits until the other process has sent its locking read on `units`, which cannot return
     * while this test's transaction holds the lock.
     */
    private function waitUntilWaitingForUnitLock(InvokedProcess $process): void
    {
        $deadline = microtime(true) + 15;

        while (microtime(true) < $deadline) {
            if (! $process->running()) {
                $this->fail('The other approval ended without waiting for the lock: '.$process->output().$process->errorOutput());
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

        $this->fail('The other approval never reached the unit lock.');
    }
}

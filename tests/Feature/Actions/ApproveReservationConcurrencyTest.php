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
use Illuminate\Support\Facades\DB;
use Tests\Support\RunsConcurrentReservationActions;
use Tests\TestCase;

/**
 * Two admins approve overlapping requests for the only unit at the same moment.
 *
 * The second approval runs in another PHP process with its own connection (see
 * RunsConcurrentReservationActions).
 */
class ApproveReservationConcurrencyTest extends TestCase
{
    use RunsConcurrentReservationActions;

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
        $process = $this->startSupportScript('approve-reservation.php', [$second->id, (string) $admin->id]);
        $output = $this->commitOnceWaitingForUnitLock($process);

        $this->assertSame('not_available', $output);

        $this->assertSame(ReservationStatus::AwaitingPayment, $first->fresh()->status);
        $this->assertSame($unit->id, ReservationRoom::query()->whereBelongsTo($first)->value('unit_id'));

        $this->assertSame(ReservationStatus::Pending, $second->fresh()->status);
        $this->assertNull($second->fresh()->expires_at);
        $this->assertNull(ReservationRoom::query()->whereBelongsTo($second)->value('unit_id'));

        $this->assertDatabaseCount('reservation_status_logs', 1);
        $this->assertDatabaseCount('outbox_events', 1);
    }
}

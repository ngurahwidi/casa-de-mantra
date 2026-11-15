<?php

namespace Tests\Feature\Actions;

use App\Actions\Reservation\ConfirmReservation;
use App\Enums\AvailabilityBlockSource;
use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\AvailabilityBlock;
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
 * A paid reservation is confirmed while an OTA booking for the same dates is being entered.
 *
 * The other process runs on the real clock, so the stay is placed relative to it.
 */
class ConfirmReservationConcurrencyTest extends TestCase
{
    use RunsConcurrentReservationActions;

    public function test_a_confirmation_moving_to_the_last_free_unit_keeps_it_from_a_concurrent_ota_booking(): void
    {
        PropertyProfile::factory()->create();
        $admin = User::factory()->create();
        $roomType = RoomType::factory()->published()->create();
        $ratePlan = RatePlan::factory()->for($roomType)->create();
        $unitA = Unit::factory()->for($roomType)->create(['code' => 'A']);
        $unitB = Unit::factory()->for($roomType)->create(['code' => 'B']);
        $checkIn = now()->addMonth()->toDateString();
        $checkOut = now()->addMonth()->addDays(3)->toDateString();

        // Approved on unit A, which the OTA has since closed, so confirming has to move it to unit B.
        $paid = Reservation::factory()->awaitingPayment()->create([
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'payment_status' => ReservationPaymentStatus::Paid,
        ]);
        ReservationRoom::factory()->for($paid)->create(['room_type_id' => $roomType->id, 'rate_plan_id' => $ratePlan->id, 'unit_id' => $unitA->id]);
        AvailabilityBlock::factory()->for($unitA)->create(['start_date' => $checkIn, 'end_date' => $checkOut, 'source' => AvailabilityBlockSource::OtaManual]);

        DB::beginTransaction();
        $this->app->make(ConfirmReservation::class)->handle($paid, $admin);

        $process = $this->startSupportScript('create-manual-reservation.php', [$ratePlan->id, (string) $admin->id, $checkIn, $checkOut]);
        $output = $this->commitOnceWaitingForUnitLock($process);

        $this->assertSame('not_available', $output);
        $this->assertSame(ReservationStatus::Confirmed, $paid->fresh()->status);
        $this->assertSame($unitB->id, ReservationRoom::query()->whereBelongsTo($paid)->value('unit_id'));
        $this->assertSame(1, Reservation::query()->count());
    }
}

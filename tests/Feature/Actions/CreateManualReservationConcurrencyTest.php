<?php

namespace Tests\Feature\Actions;

use App\Actions\Reservation\ApproveReservation;
use App\Actions\Reservation\CreateManualReservation;
use App\Data\Reservation\GuestDetails;
use App\Data\Reservation\ManualReservationData;
use App\Enums\ReservationStatus;
use App\Models\PropertyProfile;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\ReservationSource;
use App\Models\RoomType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\RunsConcurrentReservationActions;
use Tests\TestCase;

/**
 * An OTA booking is entered while another action takes the same dates for the only unit.
 *
 * The other process runs on the real clock, so stays are placed relative to it.
 */
class CreateManualReservationConcurrencyTest extends TestCase
{
    use RunsConcurrentReservationActions;

    public function test_an_ota_booking_entered_during_an_approval_for_the_same_dates_is_refused(): void
    {
        [$ratePlan, $unit, $admin] = $this->villaWithOneUnit();
        $request = Reservation::factory()->create(['check_in' => $this->checkIn(), 'check_out' => $this->checkOut()]);
        ReservationRoom::factory()->for($request)->create(['room_type_id' => $ratePlan->room_type_id, 'rate_plan_id' => $ratePlan->id]);

        DB::beginTransaction();
        $this->app->make(ApproveReservation::class)->handle($request, $admin);

        $output = $this->commitOnceWaitingForUnitLock($this->startManualReservation($ratePlan, $admin));

        $this->assertSame('not_available', $output);
        $this->assertSame(ReservationStatus::AwaitingPayment, $request->fresh()->status);
        $this->assertSame($unit->id, ReservationRoom::query()->whereBelongsTo($request)->value('unit_id'));
        $this->assertSame(1, Reservation::query()->count());
    }

    public function test_only_one_of_two_ota_bookings_entered_at_once_for_the_same_dates_is_stored(): void
    {
        [$ratePlan, $unit, $admin] = $this->villaWithOneUnit();

        DB::beginTransaction();
        $first = $this->app->make(CreateManualReservation::class)->handle(new ManualReservationData(
            source: ReservationSource::query()->where('code', 'expedia')->sole(),
            roomType: $ratePlan->roomType,
            ratePlan: $ratePlan,
            checkIn: Carbon::parse($this->checkIn()),
            checkOut: Carbon::parse($this->checkOut()),
            adults: 2,
            children: 0,
            guest: new GuestDetails('First Guest', 'first@example.com', '+62 811 1111 1111'),
            total: '4500000',
        ), $admin);

        $output = $this->commitOnceWaitingForUnitLock($this->startManualReservation($ratePlan, $admin));

        $this->assertSame('not_available', $output);
        $this->assertSame([$first->id], Reservation::query()->pluck('id')->all());
        $this->assertSame(ReservationStatus::Confirmed, $first->fresh()->status);
        $this->assertSame($unit->id, ReservationRoom::query()->whereBelongsTo($first)->value('unit_id'));
    }

    /**
     * @return array{RatePlan, Unit, User}
     */
    private function villaWithOneUnit(): array
    {
        PropertyProfile::factory()->create();
        $roomType = RoomType::factory()->published()->create();

        return [
            RatePlan::factory()->for($roomType)->create(),
            Unit::factory()->for($roomType)->create(),
            User::factory()->create(),
        ];
    }

    private function startManualReservation(RatePlan $ratePlan, User $admin): InvokedProcess
    {
        return $this->startSupportScript('create-manual-reservation.php', [$ratePlan->id, (string) $admin->id, $this->checkIn(), $this->checkOut()]);
    }

    private function checkIn(): string
    {
        return now()->addMonth()->toDateString();
    }

    private function checkOut(): string
    {
        return now()->addMonth()->addDays(3)->toDateString();
    }
}

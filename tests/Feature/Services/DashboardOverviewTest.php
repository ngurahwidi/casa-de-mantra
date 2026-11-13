<?php

namespace Tests\Feature\Services;

use App\Enums\PaymentStatus;
use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Enums\UnitStatus;
use App\Models\Payment;
use App\Models\PropertyProfile;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Unit;
use App\Services\Dashboard\DashboardOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 1 Dec 2026, 01:00 in Asia/Makassar (UTC+8), while it is still 30 Nov in UTC.
        Carbon::setTestNow('2026-11-30 17:00:00');

        PropertyProfile::factory()->create(['timezone' => 'Asia/Makassar']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_pending_requests_are_listed_oldest_first(): void
    {
        $newer = Reservation::factory()->create(['created_at' => '2026-11-30 12:00:00']);
        $older = Reservation::factory()->create(['created_at' => '2026-11-29 08:00:00']);
        Reservation::factory()->awaitingPayment()->create(['created_at' => '2026-11-20 08:00:00']);

        $overview = app(DashboardOverview::class);

        $this->assertSame([$older->id, $newer->id], $overview->pendingRequests()->pluck('id')->all());
        $this->assertSame('2026-11-29 08:00:00', $overview->oldestPendingRequestedAt()?->toDateTimeString());
    }

    public function test_oldest_pending_request_is_null_without_pending_requests(): void
    {
        $this->assertNull(app(DashboardOverview::class)->oldestPendingRequestedAt());
    }

    public function test_payments_due_are_unpaid_reservations_expiring_within_twelve_hours_or_overdue(): void
    {
        $inTwelveHours = Reservation::factory()->awaitingPayment()->create(['expires_at' => '2026-12-01 05:00:00']);
        $overdue = Reservation::factory()->awaitingPayment()->create(['expires_at' => '2026-11-30 16:00:00']);
        Reservation::factory()->awaitingPayment()->create(['expires_at' => '2026-12-01 05:00:01']);
        Reservation::factory()->awaitingPayment()->create(['expires_at' => '2026-11-30 20:00:00', 'payment_status' => ReservationPaymentStatus::Deposit]);
        Reservation::factory()->create(['status' => ReservationStatus::Expired, 'expires_at' => '2026-11-30 16:00:00']);

        $due = app(DashboardOverview::class)->paymentsDue()->pluck('id')->all();

        $this->assertSame([$overdue->id, $inTwelveHours->id], $due);
    }

    public function test_payments_due_leave_out_reservations_with_a_proof_waiting(): void
    {
        $withProof = Reservation::factory()->awaitingPayment()->create(['expires_at' => '2026-11-30 18:00:00']);
        Payment::factory()->for($withProof)->create();

        $this->assertSame(0, app(DashboardOverview::class)->paymentsDue()->count());
    }

    public function test_proofs_to_verify_are_reservations_with_a_proof_still_pending(): void
    {
        $waiting = Reservation::factory()->awaitingPayment()->create();
        Payment::factory()->for($waiting)->create();
        $rejected = Reservation::factory()->awaitingPayment()->create();
        Payment::factory()->for($rejected)->create(['status' => PaymentStatus::Failed]);
        $paid = Reservation::factory()->confirmed()->create();
        Payment::factory()->paid()->for($paid)->create();

        $this->assertSame([$waiting->id], app(DashboardOverview::class)->proofsToVerify()->pluck('id')->all());
    }

    public function test_todays_stays_follow_the_property_timezone_and_list_check_outs_first(): void
    {
        $checkIn = Reservation::factory()->confirmed()->create(['check_in' => '2026-12-01', 'check_out' => '2026-12-04']);
        $checkOut = Reservation::factory()->awaitingPayment()->create(['check_in' => '2026-11-28', 'check_out' => '2026-12-01']);
        Reservation::factory()->create(['check_in' => '2026-12-01', 'check_out' => '2026-12-03']);
        Reservation::factory()->confirmed()->create(['check_in' => '2026-11-30', 'check_out' => '2026-12-02']);

        $overview = app(DashboardOverview::class);

        $this->assertSame([$checkOut->id, $checkIn->id], $overview->todaysStays()->pluck('id')->all());
        $this->assertSame([$checkIn->id], $overview->checkInsToday()->pluck('id')->all());
        $this->assertSame([$checkOut->id], $overview->checkOutsToday()->pluck('id')->all());
    }

    public function test_not_blocked_on_ota_lists_confirmed_stays_that_have_not_ended(): void
    {
        $upcoming = Reservation::factory()->confirmed()->create(['check_in' => '2026-12-10', 'check_out' => '2026-12-12']);
        $inHouse = Reservation::factory()->confirmed()->create(['check_in' => '2026-11-29', 'check_out' => '2026-12-02']);
        Reservation::factory()->confirmed()->create(['check_in' => '2026-11-28', 'check_out' => '2026-12-01']);
        Reservation::factory()->confirmed()->create(['check_in' => '2026-12-10', 'check_out' => '2026-12-12', 'ota_blocked_at' => now()]);
        Reservation::factory()->awaitingPayment()->create(['check_in' => '2026-12-10', 'check_out' => '2026-12-12']);

        $notBlocked = app(DashboardOverview::class)->notBlockedOnOta()->pluck('id')->all();

        $this->assertSame([$inHouse->id, $upcoming->id], $notBlocked);
    }

    public function test_outstanding_balances_are_completed_stays_not_fully_paid_with_their_balance(): void
    {
        $deposit = $this->completedStay(ReservationPaymentStatus::Deposit, ['check_in' => '2026-11-17', 'check_out' => '2026-11-20']);
        Payment::factory()->for($deposit)->paid()->create(['amount' => '1500000.50']);
        Payment::factory()->for($deposit)->create(['amount' => 3000000]);
        $unpaidOta = $this->completedStay(ReservationPaymentStatus::Unpaid, ['check_in' => '2026-11-07', 'check_out' => '2026-11-10', 'source' => ReservationSource::BookingCom]);
        $this->completedStay(ReservationPaymentStatus::Paid);
        Reservation::factory()->confirmed()->create(['payment_status' => ReservationPaymentStatus::Deposit]);

        $outstanding = app(DashboardOverview::class)->outstandingBalances()->get();

        $this->assertSame([$unpaidOta->id, $deposit->id], $outstanding->modelKeys());
        $this->assertSame(['4500000.00', '2999999.50'], $outstanding->pluck('balance')->map(fn (mixed $balance): string => (string) $balance)->all());
    }

    public function test_occupancy_counts_sold_nights_of_this_month_on_active_units(): void
    {
        [$unit] = Unit::factory()->count(2)->create();
        Unit::factory()->create(['status' => UnitStatus::Inactive]);
        $this->stay(ReservationStatus::Confirmed, $unit, ['2026-11-30', '2026-12-01', '2026-12-02']);
        $this->stay(ReservationStatus::Completed, $unit, ['2026-12-05']);
        $this->stay(ReservationStatus::Confirmed, $unit, ['2026-12-31', '2027-01-01']);
        $this->stay(ReservationStatus::AwaitingPayment, $unit, ['2026-12-10']);
        $this->stay(ReservationStatus::Cancelled, $unit, ['2026-12-11']);

        $occupancy = app(DashboardOverview::class)->occupancyThisMonth();

        $this->assertSame('2026-12-01', $occupancy->month);
        $this->assertSame(4, $occupancy->soldNights);
        $this->assertSame(62, $occupancy->availableNights);
        $this->assertSame(6, $occupancy->percentage());
    }

    public function test_occupancy_has_no_percentage_without_active_units(): void
    {
        $occupancy = app(DashboardOverview::class)->occupancyThisMonth();

        $this->assertSame(0, $occupancy->availableNights);
        $this->assertNull($occupancy->percentage());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function completedStay(ReservationPaymentStatus $paymentStatus, array $attributes = []): Reservation
    {
        return Reservation::factory()->create([
            'status' => ReservationStatus::Completed,
            'payment_status' => $paymentStatus,
            ...$attributes,
        ]);
    }

    /**
     * @param  list<string>  $nights
     */
    private function stay(ReservationStatus $status, Unit $unit, array $nights): void
    {
        $room = ReservationRoom::factory()
            ->for(Reservation::factory()->state(['status' => $status]))
            ->for($unit->roomType)
            ->create(['unit_id' => $unit->id]);

        foreach ($nights as $night) {
            $room->nights()->create(['night_date' => $night, 'price' => 1500000]);
        }
    }
}

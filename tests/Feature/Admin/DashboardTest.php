<?php

namespace Tests\Feature\Admin;

use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationStatus;
use App\Filament\Resources\Reservations\Pages\ListReservations;
use App\Filament\Widgets\OutstandingBalancesWidget;
use App\Filament\Widgets\PaymentsDueWidget;
use App\Filament\Widgets\ReservationStatsOverview;
use App\Filament\Widgets\TodaysStaysWidget;
use App\Models\Payment;
use App\Models\PropertyProfile;
use App\Models\Reservation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 1 Dec 2026, 08:00 in Asia/Makassar.
        Carbon::setTestNow('2026-12-01 00:00:00');

        PropertyProfile::factory()->create(['timezone' => 'Asia/Makassar', 'currency' => 'IDR']);

        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dashboard_shows_the_reservation_widgets(): void
    {
        $widgets = Filament::getPanel('admin')->getWidgets();

        $this->assertContains(ReservationStatsOverview::class, $widgets);
        $this->assertContains(TodaysStaysWidget::class, $widgets);
        $this->assertContains(PaymentsDueWidget::class, $widgets);
        $this->assertContains(OutstandingBalancesWidget::class, $widgets);
    }

    public function test_stats_show_the_age_of_the_oldest_pending_request(): void
    {
        Reservation::factory()->create(['created_at' => '2026-11-30 21:00:00']);
        Reservation::factory()->create(['created_at' => '2026-11-30 23:00:00']);

        Livewire::test(ReservationStatsOverview::class)
            ->assertSee('Pending requests')
            ->assertSee('Oldest requested 3 hours ago');
    }

    public function test_stats_show_occupancy_of_the_current_month(): void
    {
        Livewire::test(ReservationStatsOverview::class)
            ->assertSee('Occupancy this month')
            ->assertSee('0 of 0 nights sold in December');
    }

    public function test_not_blocked_on_ota_link_lists_the_confirmed_stays_still_to_block(): void
    {
        $toBlock = Reservation::factory()->confirmed()->create(['check_in' => '2026-12-10', 'check_out' => '2026-12-12']);
        $blocked = Reservation::factory()->confirmed()->create(['check_in' => '2026-12-10', 'check_out' => '2026-12-12', 'ota_blocked_at' => now()]);
        $ended = Reservation::factory()->confirmed()->create(['check_in' => '2026-11-28', 'check_out' => '2026-12-01']);
        $pending = Reservation::factory()->create(['check_in' => '2026-12-10', 'check_out' => '2026-12-12']);

        Livewire::withQueryParams(['filters' => [
            'status' => ['values' => ['confirmed']],
            'ota_blocked_at' => ['value' => '0'],
            'stay' => ['from' => '2026-12-01'],
        ]])
            ->test(ListReservations::class)
            ->assertCanSeeTableRecords([$toBlock])
            ->assertCanNotSeeTableRecords([$blocked, $ended, $pending]);
    }

    public function test_stats_count_the_transfer_proofs_to_verify_and_link_to_them(): void
    {
        $withProof = Reservation::factory()->awaitingPayment()->create();
        Payment::factory()->for($withProof)->create();
        $withoutProof = Reservation::factory()->awaitingPayment()->create();

        Livewire::test(ReservationStatsOverview::class)
            ->assertSee('Transfer proofs to verify')
            ->assertSeeHtml('filters%5Bproof_to_verify%5D%5BisActive%5D=1');

        Livewire::withQueryParams(['filters' => ['proof_to_verify' => ['isActive' => true]]])
            ->test(ListReservations::class)
            ->assertCanSeeTableRecords([$withProof])
            ->assertCanNotSeeTableRecords([$withoutProof])
            ->assertSee('Proof to verify');
    }

    public function test_todays_stays_widget_lists_check_ins_and_check_outs_of_today(): void
    {
        $checkIn = Reservation::factory()->confirmed()->create(['check_in' => '2026-12-01', 'check_out' => '2026-12-04']);
        $checkOut = Reservation::factory()->confirmed()->create(['check_in' => '2026-11-28', 'check_out' => '2026-12-01']);
        $inHouse = Reservation::factory()->confirmed()->create(['check_in' => '2026-11-30', 'check_out' => '2026-12-02']);

        Livewire::test(TodaysStaysWidget::class)
            ->assertCanSeeTableRecords([$checkOut, $checkIn], inOrder: true)
            ->assertCanNotSeeTableRecords([$inHouse])
            ->assertTableColumnStateSet('movement', 'Check-in', $checkIn)
            ->assertTableColumnStateSet('movement', 'Check-out', $checkOut);
    }

    public function test_payments_due_widget_lists_unpaid_reservations_about_to_expire(): void
    {
        $due = Reservation::factory()->awaitingPayment()->create(['expires_at' => '2026-12-01 06:00:00']);
        $later = Reservation::factory()->awaitingPayment()->create(['expires_at' => '2026-12-02 00:00:00']);

        Livewire::test(PaymentsDueWidget::class)
            ->assertCanSeeTableRecords([$due])
            ->assertCanNotSeeTableRecords([$later]);
    }

    public function test_outstanding_balances_widget_lists_completed_stays_with_a_balance_to_collect(): void
    {
        $owing = Reservation::factory()->create(['status' => ReservationStatus::Completed, 'payment_status' => ReservationPaymentStatus::Deposit]);
        Payment::factory()->for($owing)->paid()->create(['amount' => 1500000]);
        $settled = Reservation::factory()->create(['status' => ReservationStatus::Completed, 'payment_status' => ReservationPaymentStatus::Paid]);

        Livewire::test(OutstandingBalancesWidget::class)
            ->assertCanSeeTableRecords([$owing])
            ->assertCanNotSeeTableRecords([$settled])
            ->assertTableColumnStateSet('balance', '3000000.00', $owing);
    }
}

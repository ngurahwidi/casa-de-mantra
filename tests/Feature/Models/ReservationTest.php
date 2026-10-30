<?php

namespace Tests\Feature\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationNight;
use App\Models\ReservationRoom;
use App\Models\ReservationStatusLog;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_keys_are_uuids(): void
    {
        $this->assertTrue(Str::isUuid(Guest::factory()->create()->id));
        $this->assertTrue(Str::isUuid(Reservation::factory()->create()->id));
        $this->assertTrue(Str::isUuid(ReservationRoom::factory()->create()->id));
        $this->assertTrue(Str::isUuid(ReservationNight::factory()->create()->id));
        $this->assertTrue(Str::isUuid(ReservationStatusLog::factory()->create()->id));
        $this->assertTrue(Str::isUuid(Payment::factory()->create()->id));
    }

    public function test_reservation_defaults_casts_and_relations(): void
    {
        $guest = Guest::factory()->create();
        $reservation = Reservation::create([
            'code' => 'abc12345',
            'guest_id' => $guest->id,
            'guest_full_name' => 'Jane Doe',
            'guest_email' => 'jane@example.com',
            'guest_phone' => '+62 812 3456 7890',
            'check_in' => '2026-12-24',
            'check_out' => '2026-12-27',
            'adults' => 2,
            'currency' => 'IDR',
            'subtotal' => 4500000,
            'total' => 4500000,
            'policy_snapshot' => ['name' => 'Non-Refundable'],
        ])->fresh();

        $this->assertSame(ReservationStatus::Pending, $reservation->status);
        $this->assertSame(ReservationSource::DirectWeb, $reservation->source);
        $this->assertSame(ReservationPaymentStatus::Unpaid, $reservation->payment_status);
        $this->assertSame(0, $reservation->children);
        $this->assertSame('0.00', $reservation->tax_total);
        $this->assertSame('4500000.00', $reservation->total);
        $this->assertEquals(Carbon::parse('2026-12-24'), $reservation->check_in);
        $this->assertEquals(Carbon::parse('2026-12-27'), $reservation->check_out);
        $this->assertSame(['name' => 'Non-Refundable'], $reservation->policy_snapshot);
        $this->assertNull($reservation->expires_at);
        $this->assertNull($reservation->confirmed_at);
        $this->assertNull($reservation->ota_blocked_at);
        $this->assertNull($reservation->external_source);
        $this->assertSame('Jane Doe', $reservation->guest_full_name);
        $this->assertNull($reservation->guest_country);
        $this->assertTrue($reservation->guest->is($guest));
        $this->assertTrue($guest->reservations->first()->is($reservation));
    }

    public function test_factory_copies_the_guest_details_into_the_contact_snapshot(): void
    {
        $guest = Guest::factory()->create(['full_name' => 'Jane Doe', 'email' => 'jane@example.com', 'phone' => '+62 812 0000 0000', 'country' => 'Australia']);

        $reservation = Reservation::factory()->for($guest)->create(['guest_phone' => '+1 555 0100']);

        $this->assertSame('Jane Doe', $reservation->guest_full_name);
        $this->assertSame('jane@example.com', $reservation->guest_email);
        $this->assertSame('+1 555 0100', $reservation->guest_phone);
        $this->assertSame('Australia', $reservation->guest_country);
    }

    public function test_reservation_timestamps_are_cast_to_datetimes(): void
    {
        $reservation = Reservation::factory()->create([
            'expires_at' => '2026-12-01 10:00:00',
            'confirmed_at' => '2026-12-02 10:00:00',
            'ota_blocked_at' => '2026-12-03 10:00:00',
        ])->fresh();

        $this->assertInstanceOf(Carbon::class, $reservation->expires_at);
        $this->assertInstanceOf(Carbon::class, $reservation->confirmed_at);
        $this->assertInstanceOf(Carbon::class, $reservation->ota_blocked_at);
    }

    public function test_reservation_rooms_nights_and_units_are_related(): void
    {
        $reservation = Reservation::factory()->create();
        $room = ReservationRoom::factory()->for($reservation)->create();
        $unit = Unit::factory()->for($room->roomType)->create();
        $room->update(['unit_id' => $unit->id]);
        ReservationNight::factory()->for($room)->create(['night_date' => '2026-12-25']);
        ReservationNight::factory()->for($room)->create(['night_date' => '2026-12-24']);

        $room = $room->fresh();

        $this->assertTrue($reservation->reservationRooms->first()->is($room));
        $this->assertTrue($room->reservation->is($reservation));
        $this->assertTrue($room->unit->is($unit));
        $this->assertTrue($unit->reservationRooms->first()->is($room));
        $this->assertTrue($room->roomType->reservationRooms->first()->is($room));
        $this->assertSame(
            ['2026-12-24', '2026-12-25'],
            $room->nights->map(fn (ReservationNight $night) => $night->night_date->toDateString())->all(),
        );
        $this->assertCount(2, $reservation->nights);
        $this->assertSame('1500000.00', $room->nights->first()->price);
    }

    public function test_a_room_cannot_have_two_nights_on_the_same_date(): void
    {
        $room = ReservationRoom::factory()->create();
        ReservationNight::factory()->for($room)->create(['night_date' => '2026-12-24']);

        $this->assertThrowsQuery(fn () => ReservationNight::factory()->for($room)->create(['night_date' => '2026-12-24']));
    }

    public function test_status_logs_are_ordered_and_have_no_updated_at(): void
    {
        $reservation = Reservation::factory()->create();
        $admin = User::factory()->create();
        ReservationStatusLog::factory()->for($reservation)->create([
            'from_status' => ReservationStatus::Pending,
            'to_status' => ReservationStatus::AwaitingPayment,
            'admin_user_id' => $admin->id,
            'note' => 'Approved',
            'created_at' => '2026-12-02 10:00:00',
        ]);
        ReservationStatusLog::factory()->for($reservation)->create([
            'to_status' => ReservationStatus::Pending,
            'created_at' => '2026-12-01 10:00:00',
        ]);

        $logs = $reservation->fresh()->statusLogs;

        $this->assertSame([null, ReservationStatus::Pending], [$logs[0]->from_status, $logs[0]->to_status]);
        $this->assertNull($logs[0]->admin_user_id);
        $this->assertSame(ReservationStatus::AwaitingPayment, $logs[1]->to_status);
        $this->assertTrue($logs[1]->adminUser->is($admin));
        $this->assertFalse(array_key_exists('updated_at', $logs[1]->getAttributes()));
        $this->assertInstanceOf(Carbon::class, $logs[1]->created_at);
    }

    public function test_status_log_is_kept_when_the_admin_is_deleted(): void
    {
        $admin = User::factory()->create();
        $log = ReservationStatusLog::factory()->create(['admin_user_id' => $admin->id]);

        $admin->delete();

        $this->assertNull($log->fresh()->admin_user_id);
    }

    public function test_payment_casts_and_relations(): void
    {
        $reservation = Reservation::factory()->create();
        $admin = User::factory()->create();
        $payment = Payment::factory()->for($reservation)->paid()->create([
            'amount' => 1500000,
            'verified_by' => $admin->id,
            'proof_url' => 'payment-proofs/abc.jpg',
        ])->fresh();

        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame('1500000.00', $payment->amount);
        $this->assertInstanceOf(Carbon::class, $payment->paid_at);
        $this->assertNull($payment->provider);
        $this->assertNull($payment->provider_ref);
        $this->assertTrue($payment->reservation->is($reservation));
        $this->assertTrue($payment->verifier->is($admin));
        $this->assertTrue($reservation->payments->first()->is($payment));
    }

    public function test_payment_defaults_to_pending(): void
    {
        $payment = Payment::create([
            'reservation_id' => Reservation::factory()->create()->id,
            'amount' => 1000000,
            'method' => PaymentMethod::BankTransfer,
        ])->fresh();

        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(PaymentMethod::BankTransfer, $payment->method);
    }

    public function test_guest_email_is_unique(): void
    {
        Guest::factory()->create(['email' => 'jane@example.com']);

        $this->assertThrowsQuery(fn () => Guest::factory()->create(['email' => 'jane@example.com']));
    }

    public function test_reservation_code_is_unique_and_case_sensitive(): void
    {
        Reservation::factory()->create(['code' => 'AbCd1234']);

        $other = Reservation::factory()->create(['code' => 'abcd1234']);

        $this->assertSame('abcd1234', $other->fresh()->code);
        $this->assertThrowsQuery(fn () => Reservation::factory()->create(['code' => 'AbCd1234']));
    }

    public function test_reservation_constraints_are_enforced_by_the_database(): void
    {
        $this->assertThrowsQuery(fn () => Reservation::factory()->create(['check_out' => '2026-12-24']));
        $this->assertThrowsQuery(fn () => Reservation::factory()->create(['check_in' => '2026-12-28']));
        $this->assertThrowsQuery(fn () => Reservation::factory()->create(['adults' => 0]));
        $this->assertThrowsQuery(fn () => Reservation::factory()->create(['total' => -1]));
        $this->assertThrowsQuery(fn () => Reservation::factory()->create(['tax_total' => -1]));

        $reservation = Reservation::factory()->create();
        $this->assertThrowsQuery(fn () => DB::table('reservations')->where('id', $reservation->id)->update(['status' => 'approved']));
        $this->assertThrowsQuery(fn () => DB::table('reservations')->where('id', $reservation->id)->update(['source' => 'agoda']));
        $this->assertThrowsQuery(fn () => DB::table('reservations')->where('id', $reservation->id)->update(['payment_status' => 'partial']));
    }

    public function test_every_enum_case_is_accepted_by_the_database(): void
    {
        foreach (ReservationStatus::cases() as $status) {
            $this->assertSame($status, Reservation::factory()->create(['status' => $status])->fresh()->status);
            $this->assertSame($status, ReservationStatusLog::factory()->create(['from_status' => $status, 'to_status' => $status])->fresh()->to_status);
        }
        foreach (ReservationSource::cases() as $source) {
            $this->assertSame($source, Reservation::factory()->create(['source' => $source])->fresh()->source);
        }
        foreach (ReservationPaymentStatus::cases() as $paymentStatus) {
            $this->assertSame($paymentStatus, Reservation::factory()->create(['payment_status' => $paymentStatus])->fresh()->payment_status);
        }
        foreach (PaymentStatus::cases() as $status) {
            $this->assertSame($status, Payment::factory()->create(['status' => $status])->fresh()->status);
        }
        foreach (PaymentMethod::cases() as $method) {
            $this->assertSame($method, Payment::factory()->create(['method' => $method])->fresh()->method);
        }
    }

    public function test_child_row_constraints_are_enforced_by_the_database(): void
    {
        $this->assertThrowsQuery(fn () => ReservationRoom::factory()->create(['total_price' => -1]));
        $this->assertThrowsQuery(fn () => ReservationNight::factory()->create(['price' => -1]));

        $log = ReservationStatusLog::factory()->create();
        $this->assertThrowsQuery(fn () => DB::table('reservation_status_logs')->where('id', $log->id)->update(['to_status' => 'approved']));
        $this->assertThrowsQuery(fn () => DB::table('reservation_status_logs')->where('id', $log->id)->update(['from_status' => 'approved']));

        $payment = Payment::factory()->create();
        $this->assertThrowsQuery(fn () => DB::table('payments')->where('id', $payment->id)->update(['status' => 'partial']));
        $this->assertThrowsQuery(fn () => DB::table('payments')->where('id', $payment->id)->update(['method' => 'bitcoin']));
        $this->assertThrowsQuery(fn () => Payment::factory()->create(['amount' => 0]));
    }

    public function test_deleting_a_reservation_removes_its_rooms_nights_and_logs(): void
    {
        $room = ReservationRoom::factory()->create();
        ReservationNight::factory()->for($room)->create();
        ReservationStatusLog::factory()->for($room->reservation)->create();

        $room->reservation->delete();

        $this->assertSame(0, ReservationRoom::count());
        $this->assertSame(0, ReservationNight::count());
        $this->assertSame(0, ReservationStatusLog::count());
    }

    public function test_records_in_use_cannot_be_deleted(): void
    {
        $payment = Payment::factory()->create();
        $this->assertThrowsQuery(fn () => $payment->reservation->delete());

        $room = ReservationRoom::factory()->create();
        $this->assertThrowsQuery(fn () => $room->reservation->guest->delete());
        $this->assertThrowsQuery(fn () => $room->ratePlan->delete());
        $this->assertThrowsQuery(fn () => $room->roomType->delete());

        $unit = Unit::factory()->create();
        ReservationRoom::factory()->create(['unit_id' => $unit->id, 'room_type_id' => $unit->room_type_id]);
        $this->assertThrowsQuery(fn () => $unit->delete());
    }

    public function test_reservations_have_a_composite_index_for_status_and_date_lookups(): void
    {
        $columns = collect(DB::select('SHOW INDEX FROM reservations'))
            ->groupBy('Key_name')
            ->map(fn ($rows) => $rows->sortBy('Seq_in_index')->pluck('Column_name')->all());

        $this->assertContains(['status', 'check_in', 'check_out'], $columns->values()->all());
    }

    private function assertThrowsQuery(callable $callback): void
    {
        try {
            $callback();
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected the database to reject the statement.');
    }
}

<?php

namespace Tests\Feature\Actions;

use App\Actions\Reservation\CreateManualReservation;
use App\Data\Reservation\GuestDetails;
use App\Data\Reservation\ManualReservationData;
use App\Enums\OutboxEventStatus;
use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Exceptions\Pricing\GuestCapacityExceededException;
use App\Exceptions\Pricing\PriceNotConfiguredException;
use App\Exceptions\Pricing\RoomTypeNotBookableException;
use App\Exceptions\Reservation\DatesNotAvailableException;
use App\Exceptions\Reservation\DuplicateReservationException;
use App\Exceptions\Reservation\InvalidAmountException;
use App\Models\AvailabilityBlock;
use App\Models\CancellationPolicy;
use App\Models\Guest;
use App\Models\OutboxEvent;
use App\Models\PropertyProfile;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Reservation;
use App\Models\ReservationNight;
use App\Models\ReservationRoom;
use App\Models\ReservationStatusLog;
use App\Models\RoomType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class CreateManualReservationTest extends TestCase
{
    use RefreshDatabase;

    private const WEEKEND = 16 | 32;

    private CreateManualReservation $create;

    private User $admin;

    private RoomType $roomType;

    private Unit $unit;

    private RatePlan $ratePlan;

    private CancellationPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        // 16:00 on 2026-11-01 in the property timezone (Asia/Makassar, UTC+8).
        Carbon::setTestNow('2026-11-01 08:00:00');

        PropertyProfile::factory()->create(['timezone' => 'Asia/Makassar', 'currency' => 'IDR']);

        $this->create = $this->app->make(CreateManualReservation::class);
        $this->admin = User::factory()->create();
        $this->roomType = RoomType::factory()->published()->create(['max_adults' => 4, 'max_children' => 2]);
        $this->unit = Unit::factory()->for($this->roomType)->create(['code' => 'A']);
        $this->policy = CancellationPolicy::factory()->create([
            'name' => CancellationPolicy::NON_REFUNDABLE,
            'rules' => [['refund_percent' => 0]],
            'description' => 'This booking is non-refundable.',
        ]);
        $this->ratePlan = RatePlan::factory()->for($this->roomType)->for($this->policy)->create(['name' => 'Non-Refundable']);

        RateRule::factory()->for($this->ratePlan)->create([
            'name' => 'Base Price',
            'price_per_night' => 1500000,
            'priority' => 0,
        ]);
        RateRule::factory()->for($this->ratePlan)->create([
            'name' => 'Weekend',
            'days_of_week_mask' => self::WEEKEND,
            'price_per_night' => 2000000,
            'priority' => 10,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidTotals(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-1500000'],
            'three decimals' => ['1500000.005'],
            'not a number' => ['1.5 million'],
            'blank' => [' '],
            'too large' => ['1000000000000'],
        ];
    }

    public function test_phone_booking_is_stored_as_confirmed_at_the_rate_prices(): void
    {
        // Thu 24, Fri 25 and Sat 26 Dec: one weekday and two weekend nights.
        $reservation = $this->create->handle($this->data(
            guest: new GuestDetails('  Jane Doe ', ' Jane@Example.com ', ' +62 812 3456 7890 ', ' Australia '),
            specialRequests: '  Airport pickup. ',
        ), $this->admin, 'Booked by phone.');

        $fresh = Reservation::query()->sole();
        $this->assertSame($reservation->id, $fresh->id);
        $this->assertMatchesRegularExpression('/^RSV-[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/', $fresh->code);
        $this->assertSame(ReservationStatus::Confirmed, $fresh->status);
        $this->assertSame(ReservationSource::Manual, $fresh->source);
        $this->assertSame(ReservationPaymentStatus::Unpaid, $fresh->payment_status);
        $this->assertSame('2026-11-01 08:00:00', $fresh->confirmed_at->toDateTimeString());
        $this->assertNull($fresh->expires_at);
        $this->assertSame('2026-12-24', $fresh->check_in->toDateString());
        $this->assertSame('2026-12-27', $fresh->check_out->toDateString());
        $this->assertSame(2, $fresh->adults);
        $this->assertSame(1, $fresh->children);
        $this->assertSame('Airport pickup.', $fresh->special_requests);
        $this->assertSame('IDR', $fresh->currency);
        $this->assertSame('5500000.00', $fresh->subtotal);
        $this->assertSame('0.00', $fresh->tax_total);
        $this->assertSame('5500000.00', $fresh->total);
        $this->assertNull($fresh->external_source);
        $this->assertNull($fresh->external_id);
        $this->assertNull($fresh->ota_blocked_at);
        $this->assertSame('Non-Refundable', $fresh->policy_snapshot['name']);
        $this->assertSame($this->policy->id, $fresh->policy_snapshot['cancellation_policy_id']);

        $this->assertSame('Jane Doe', $fresh->guest_full_name);
        $this->assertSame('jane@example.com', $fresh->guest_email);
        $this->assertSame('+62 812 3456 7890', $fresh->guest_phone);
        $this->assertSame('Australia', $fresh->guest_country);
        $this->assertSame(Guest::query()->sole()->id, $fresh->guest_id);

        $room = ReservationRoom::query()->sole();
        $this->assertSame($this->unit->id, $room->unit_id);
        $this->assertSame($this->ratePlan->id, $room->rate_plan_id);
        $this->assertSame('5500000.00', $room->total_price);
        $this->assertSame($room->id, $reservation->reservationRooms->sole()->id);
        $this->assertSame(
            ['2026-12-24' => '1500000.00', '2026-12-25' => '2000000.00', '2026-12-26' => '2000000.00'],
            $this->nightPrices($room),
        );

        $log = ReservationStatusLog::query()->sole();
        $this->assertSame($this->admin->id, $log->admin_user_id);
        $this->assertNull($log->from_status);
        $this->assertSame(ReservationStatus::Confirmed, $log->to_status);
        $this->assertSame('Booked by phone.', $log->note);

        $event = OutboxEvent::query()->sole();
        $this->assertSame('reservation.created', $event->type);
        $this->assertSame(OutboxEventStatus::Pending, $event->status);
        // MySQL JSON does not keep key order, so compare without it.
        $this->assertEquals([
            'reservation_id' => $fresh->id,
            'code' => $fresh->code,
            'from_status' => null,
            'to_status' => 'confirmed',
            'admin_user_id' => $this->admin->id,
            'note' => 'Booked by phone.',
        ], $event->payload);
    }

    public function test_ota_booking_is_stored_at_the_total_entered(): void
    {
        $reservation = $this->create->handle($this->data(
            source: ReservationSource::BookingCom,
            total: ' 4000000.01 ',
            externalId: ' 4211337890 ',
        ), $this->admin);

        $fresh = $reservation->fresh();
        $this->assertSame(ReservationSource::BookingCom, $fresh->source);
        $this->assertSame('booking_com', $fresh->external_source);
        $this->assertSame('4211337890', $fresh->external_id);
        $this->assertSame('4000000.01', $fresh->subtotal);
        $this->assertSame('0.00', $fresh->tax_total);
        $this->assertSame('4000000.01', $fresh->total);

        // The leftover cent goes to the first night, so the nights add up to the total.
        $room = ReservationRoom::query()->sole();
        $this->assertSame('4000000.01', $room->total_price);
        $this->assertSame(
            ['2026-12-24' => '1333333.34', '2026-12-25' => '1333333.34', '2026-12-26' => '1333333.33'],
            $this->nightPrices($room),
        );
    }

    public function test_entered_total_does_not_need_rate_rules_stay_length_or_a_published_room_type(): void
    {
        RateRule::query()->delete();
        $this->ratePlan->update(['is_active' => false, 'min_stay' => 5]);
        $this->roomType->update(['is_published' => false]);

        $reservation = $this->create->handle($this->data(source: ReservationSource::Expedia, total: '4500000'), $this->admin);

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
        $this->assertSame('4500000.00', $reservation->fresh()->total);
    }

    public function test_without_a_total_the_rate_rules_apply(): void
    {
        RateRule::query()->delete();
        RateRule::factory()->for($this->ratePlan)->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-25']);

        $exception = $this->assertRejected(fn () => $this->create->handle($this->data(), $this->admin), PriceNotConfiguredException::class);

        $this->assertSame(['2026-12-26'], $exception->dates);

        $this->roomType->update(['is_published' => false]);

        $this->assertRejected(fn () => $this->create->handle($this->data(), $this->admin), RoomTypeNotBookableException::class);
    }

    public function test_guests_over_capacity_are_rejected_with_or_without_a_total(): void
    {
        $this->assertRejected(
            fn () => $this->create->handle($this->data(adults: 5), $this->admin),
            GuestCapacityExceededException::class,
            'This stay allows up to 4 adults and 2 children.',
        );

        $this->assertRejected(
            fn () => $this->create->handle($this->data(children: 3, total: '4500000'), $this->admin),
            GuestCapacityExceededException::class,
            'This stay allows up to 4 adults and 2 children.',
        );
    }

    public function test_dates_held_by_a_confirmed_reservation_are_not_available(): void
    {
        $this->holdUnit(Reservation::factory()->confirmed()->create(['check_in' => '2026-12-26', 'check_out' => '2026-12-29']));

        $this->assertRejected(
            fn () => $this->create->handle($this->data(total: '4500000'), $this->admin),
            DatesNotAvailableException::class,
            'The selected dates are not available.',
        );
    }

    public function test_dates_held_by_an_awaiting_payment_reservation_are_not_available(): void
    {
        $this->holdUnit(Reservation::factory()->awaitingPayment()->create(['check_in' => '2026-12-20', 'check_out' => '2026-12-25']));

        $this->assertRejected(fn () => $this->create->handle($this->data(), $this->admin), DatesNotAvailableException::class);
    }

    public function test_blocked_dates_are_not_available(): void
    {
        AvailabilityBlock::factory()->for($this->unit)->create(['start_date' => '2026-12-25', 'end_date' => '2026-12-26']);

        $this->assertRejected(fn () => $this->create->handle($this->data(), $this->admin), DatesNotAvailableException::class);
    }

    public function test_a_pending_request_does_not_take_the_dates(): void
    {
        $pending = Reservation::factory()->create(['check_in' => '2026-12-24', 'check_out' => '2026-12-27']);
        $this->holdUnit($pending, assignUnit: false);

        $reservation = $this->create->handle($this->data(), $this->admin);

        $this->assertSame($this->unit->id, $reservation->reservationRooms->sole()->unit_id);
    }

    public function test_stay_starting_on_another_guest_check_out_day_is_available(): void
    {
        $this->holdUnit(Reservation::factory()->confirmed()->create(['check_in' => '2026-12-20', 'check_out' => '2026-12-24']));

        $reservation = $this->create->handle($this->data(), $this->admin);

        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
    }

    public function test_the_free_unit_is_assigned_when_another_is_taken(): void
    {
        $other = Unit::factory()->for($this->roomType)->create(['code' => 'B']);
        $this->holdUnit(Reservation::factory()->confirmed()->create());

        $reservation = $this->create->handle($this->data(), $this->admin);

        $this->assertSame($other->id, $reservation->reservationRooms->sole()->unit_id);
    }

    public function test_the_same_ota_booking_cannot_be_entered_twice(): void
    {
        Unit::factory()->for($this->roomType)->create(['code' => 'B']);
        $first = $this->create->handle($this->data(source: ReservationSource::BookingCom, total: '4500000', externalId: '4211337890'), $this->admin);

        $this->assertRejected(
            fn () => $this->create->handle($this->data(source: ReservationSource::BookingCom, total: '4500000', externalId: '4211337890'), $this->admin),
            DuplicateReservationException::class,
            "The booking_com booking 4211337890 has already been entered as reservation {$first->code}.",
        );

        // The same number on another OTA is a different booking.
        $this->create->handle($this->data(source: ReservationSource::Expedia, total: '4500000', externalId: '4211337890'), $this->admin);

        $this->assertDatabaseCount('reservations', 2);
    }

    public function test_an_ota_booking_can_be_entered_again_after_its_entry_was_cancelled(): void
    {
        Reservation::factory()->create([
            'status' => ReservationStatus::Cancelled,
            'source' => ReservationSource::BookingCom,
            'external_source' => 'booking_com',
            'external_id' => '4211337890',
        ]);

        $reservation = $this->create->handle($this->data(source: ReservationSource::BookingCom, total: '4500000', externalId: '4211337890'), $this->admin);

        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
    }

    public function test_direct_web_is_not_a_manual_source(): void
    {
        $this->assertRejected(
            fn () => $this->create->handle($this->data(source: ReservationSource::DirectWeb), $this->admin),
            InvalidArgumentException::class,
            'A manual reservation cannot have source direct_web.',
        );
    }

    public function test_check_in_before_today_in_the_property_timezone_is_rejected(): void
    {
        // Still 2026-11-01 in UTC, already 2026-11-02 04:00 in Asia/Makassar.
        Carbon::setTestNow('2026-11-01 20:00:00');

        $this->assertRejected(
            fn () => $this->create->handle($this->data(checkIn: '2026-11-01', checkOut: '2026-11-03', total: '3000000'), $this->admin),
            DatesNotAvailableException::class,
            'Check-in cannot be in the past.',
        );

        $reservation = $this->create->handle($this->data(checkIn: '2026-11-02', checkOut: '2026-11-04', total: '3000000'), $this->admin);

        $this->assertSame('2026-11-02', $reservation->check_in->toDateString());
    }

    #[DataProvider('invalidTotals')]
    public function test_invalid_total_is_rejected(string $total): void
    {
        $this->assertRejected(
            fn () => $this->create->handle($this->data(total: $total), $this->admin),
            InvalidAmountException::class,
            'The total must be a positive number with at most two decimals; got "'.trim($total).'".',
        );
    }

    public function test_returning_guest_takes_the_details_entered_by_the_admin(): void
    {
        $guest = Guest::factory()->create([
            'full_name' => 'J. Doe',
            'email' => 'jane@example.com',
            'phone' => '+62 812 0000 0000',
            'country' => 'Australia',
        ]);

        $reservation = $this->create->handle($this->data(
            guest: new GuestDetails('Jane Doe', 'JANE@example.com', '+62 812 3456 7890', ' '),
        ), $this->admin);

        $this->assertSame($guest->id, $reservation->guest_id);
        $this->assertDatabaseCount('guests', 1);

        $guest->refresh();
        $this->assertSame('Jane Doe', $guest->full_name);
        $this->assertSame('jane@example.com', $guest->email);
        $this->assertSame('+62 812 3456 7890', $guest->phone);
        // A blank country does not erase the one already known.
        $this->assertSame('Australia', $guest->country);
        $this->assertNull($reservation->fresh()->guest_country);
    }

    public function test_failure_part_way_rolls_back_everything(): void
    {
        $guest = Guest::factory()->create(['full_name' => 'Old Name', 'email' => 'jane@example.com']);
        OutboxEvent::creating(fn () => throw new RuntimeException('Outbox unavailable.'));

        $this->assertRejected(fn () => $this->create->handle($this->data(), $this->admin), RuntimeException::class, 'Outbox unavailable.');

        $this->assertDatabaseCount('reservation_rooms', 0);
        $this->assertDatabaseCount('reservation_nights', 0);
        $this->assertSame('Old Name', $guest->fresh()->full_name);
    }

    private function data(
        ReservationSource $source = ReservationSource::Manual,
        string $checkIn = '2026-12-24',
        string $checkOut = '2026-12-27',
        int $adults = 2,
        int $children = 1,
        ?GuestDetails $guest = null,
        ?string $total = null,
        ?string $externalId = null,
        ?string $specialRequests = null,
    ): ManualReservationData {
        return new ManualReservationData(
            source: $source,
            roomType: $this->roomType,
            ratePlan: $this->ratePlan,
            checkIn: Carbon::parse($checkIn),
            checkOut: Carbon::parse($checkOut),
            adults: $adults,
            children: $children,
            guest: $guest ?? new GuestDetails('Jane Doe', 'jane@example.com', '+62 812 3456 7890', 'Australia'),
            total: $total,
            externalId: $externalId,
            specialRequests: $specialRequests,
        );
    }

    private function holdUnit(Reservation $reservation, bool $assignUnit = true): void
    {
        ReservationRoom::factory()->for($reservation)->create([
            'room_type_id' => $this->roomType->id,
            'unit_id' => $assignUnit ? $this->unit->id : null,
            'rate_plan_id' => $this->ratePlan->id,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function nightPrices(ReservationRoom $room): array
    {
        return $room->nights->mapWithKeys(fn (ReservationNight $night) => [$night->night_date->toDateString() => $night->price])->all();
    }

    /**
     * Runs the call, expects it to fail with the given exception and to write no reservation.
     *
     * @template T of Throwable
     *
     * @param  class-string<T>  $exception
     * @return T
     */
    private function assertRejected(callable $call, string $exception, ?string $message = null): Throwable
    {
        $reservations = Reservation::query()->count();
        $guests = Guest::query()->count();
        $logs = ReservationStatusLog::query()->count();
        $events = OutboxEvent::query()->count();

        try {
            $call();
        } catch (Throwable $e) {
            $this->assertInstanceOf($exception, $e);

            if ($message !== null) {
                $this->assertSame($message, $e->getMessage());
            }

            $this->assertDatabaseCount('reservations', $reservations);
            $this->assertDatabaseCount('guests', $guests);
            $this->assertDatabaseCount('reservation_status_logs', $logs);
            $this->assertDatabaseCount('outbox_events', $events);

            return $e;
        }

        $this->fail("Expected {$exception}.");
    }
}

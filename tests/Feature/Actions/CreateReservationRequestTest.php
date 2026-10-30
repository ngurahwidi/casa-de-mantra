<?php

namespace Tests\Feature\Actions;

use App\Actions\Reservation\CreateReservationRequest;
use App\Contracts\ReservationProvider;
use App\Data\Reservation\GuestDetails;
use App\Data\Reservation\ReservationRequestData;
use App\Enums\OutboxEventStatus;
use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Exceptions\Pricing\GuestCapacityExceededException;
use App\Exceptions\Pricing\PriceNotConfiguredException;
use App\Exceptions\Reservation\DatesNotAvailableException;
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
use App\Services\Reservation\InternalReservationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class CreateReservationRequestTest extends TestCase
{
    use RefreshDatabase;

    private const WEEKEND = 16 | 32;

    private ReservationProvider $provider;

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

        $this->provider = $this->app->make(ReservationProvider::class);
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

    public function test_container_resolves_the_internal_provider(): void
    {
        $this->assertInstanceOf(InternalReservationProvider::class, $this->provider);
    }

    public function test_request_is_stored_as_a_pending_reservation(): void
    {
        // Thursday 2026-12-24 → Sunday 2026-12-27: Thu at base, Fri and Sat at the weekend price.
        $reservation = $this->provider->createRequest($this->request(
            guest: new GuestDetails('  Jane Doe ', ' Jane@Example.com ', ' +62 812 3456 7890 ', ' Australia '),
            specialRequests: '  Late arrival, around 22:00. ',
        ));

        $fresh = Reservation::query()->sole();
        $this->assertSame($reservation->id, $fresh->id);
        $this->assertMatchesRegularExpression('/^RSV-[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/', $fresh->code);
        $this->assertSame(ReservationStatus::Pending, $fresh->status);
        $this->assertSame(ReservationSource::DirectWeb, $fresh->source);
        $this->assertSame(ReservationPaymentStatus::Unpaid, $fresh->payment_status);
        $this->assertSame('2026-12-24', $fresh->check_in->toDateString());
        $this->assertSame('2026-12-27', $fresh->check_out->toDateString());
        $this->assertSame(2, $fresh->adults);
        $this->assertSame(1, $fresh->children);
        $this->assertSame('Late arrival, around 22:00.', $fresh->special_requests);
        $this->assertSame('IDR', $fresh->currency);
        $this->assertSame('5500000.00', $fresh->subtotal);
        $this->assertSame('0.00', $fresh->tax_total);
        $this->assertSame('5500000.00', $fresh->total);
        $this->assertNull($fresh->expires_at);
        $this->assertNull($fresh->confirmed_at);
        $this->assertNull($fresh->external_source);
        $this->assertNull($fresh->external_id);
        $this->assertEquals([
            'cancellation_policy_id' => $this->policy->id,
            'name' => 'Non-Refundable',
            'description' => 'This booking is non-refundable.',
            'rules' => [['refund_percent' => 0]],
            'rate_plan_name' => 'Non-Refundable',
            'meal_plan' => 'room_only',
        ], $fresh->policy_snapshot);

        $this->assertSame('Jane Doe', $fresh->guest_full_name);
        $this->assertSame('jane@example.com', $fresh->guest_email);
        $this->assertSame('+62 812 3456 7890', $fresh->guest_phone);
        $this->assertSame('Australia', $fresh->guest_country);

        $guest = Guest::query()->sole();
        $this->assertSame($guest->id, $fresh->guest_id);
        $this->assertSame('Jane Doe', $guest->full_name);
        $this->assertSame('jane@example.com', $guest->email);
        $this->assertSame('+62 812 3456 7890', $guest->phone);
        $this->assertSame('Australia', $guest->country);

        $room = ReservationRoom::query()->sole();
        $this->assertSame($fresh->id, $room->reservation_id);
        $this->assertSame($this->roomType->id, $room->room_type_id);
        $this->assertSame($this->ratePlan->id, $room->rate_plan_id);
        $this->assertNull($room->unit_id);
        $this->assertSame('5500000.00', $room->total_price);

        $this->assertSame(
            ['2026-12-24' => '1500000.00', '2026-12-25' => '2000000.00', '2026-12-26' => '2000000.00'],
            $room->nights->mapWithKeys(fn (ReservationNight $night) => [$night->night_date->toDateString() => $night->price])->all(),
        );

        $log = ReservationStatusLog::query()->sole();
        $this->assertSame($fresh->id, $log->reservation_id);
        $this->assertNull($log->admin_user_id);
        $this->assertNull($log->from_status);
        $this->assertSame(ReservationStatus::Pending, $log->to_status);

        $event = OutboxEvent::query()->sole();
        $this->assertSame('reservation.created', $event->type);
        $this->assertSame(OutboxEventStatus::Pending, $event->status);
        // MySQL JSON does not keep key order, so compare without it.
        $this->assertEquals([
            'reservation_id' => $fresh->id,
            'code' => $fresh->code,
            'from_status' => null,
            'to_status' => 'pending',
            'admin_user_id' => null,
            'note' => null,
        ], $event->payload);
    }

    public function test_returning_guest_is_matched_by_email_and_new_details_stay_on_the_reservation(): void
    {
        $guest = Guest::factory()->create([
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '+62 812 0000 0000',
            'country' => 'Australia',
        ]);
        $earlier = Reservation::factory()->for($guest)->confirmed()->create(['check_in' => '2026-11-10', 'check_out' => '2026-11-12']);

        $reservation = $this->provider->createRequest($this->request(
            guest: new GuestDetails('Someone Else', 'JANE@example.com', '+1 555 0100', 'Canada'),
        ));

        $this->assertSame($guest->id, $reservation->guest_id);
        $this->assertDatabaseCount('guests', 1);

        // The new request keeps exactly what was entered with it.
        $fresh = $reservation->fresh();
        $this->assertSame('Someone Else', $fresh->guest_full_name);
        $this->assertSame('jane@example.com', $fresh->guest_email);
        $this->assertSame('+1 555 0100', $fresh->guest_phone);
        $this->assertSame('Canada', $fresh->guest_country);

        // Neither the guest record nor the earlier reservation change.
        $guest->refresh();
        $this->assertSame('Jane Doe', $guest->full_name);
        $this->assertSame('+62 812 0000 0000', $guest->phone);
        $this->assertSame('Australia', $guest->country);

        $earlier->refresh();
        $this->assertSame('Jane Doe', $earlier->guest_full_name);
        $this->assertSame('+62 812 0000 0000', $earlier->guest_phone);
        $this->assertSame('Australia', $earlier->guest_country);
    }

    public function test_each_request_gets_its_own_code(): void
    {
        $first = $this->provider->createRequest($this->request());
        $second = $this->provider->createRequest($this->request());

        $this->assertNotSame($first->code, $second->code);
        $this->assertSame($first->guest_id, $second->guest_id);
    }

    public function test_blank_optional_fields_are_stored_as_null(): void
    {
        $reservation = $this->provider->createRequest($this->request(
            guest: new GuestDetails('Jane Doe', 'jane@example.com', '+62 812 3456 7890', '  '),
            specialRequests: "  \n ",
        ));

        $this->assertNull($reservation->fresh()->special_requests);
        $this->assertNull(Guest::query()->sole()->country);
    }

    public function test_dates_held_by_a_confirmed_reservation_are_not_available(): void
    {
        $this->holdUnit(Reservation::factory()->confirmed()->create(['check_in' => '2026-12-26', 'check_out' => '2026-12-29']));

        $this->assertRejected(fn () => $this->provider->createRequest($this->request()), DatesNotAvailableException::class, 'The selected dates are not available.');
    }

    public function test_dates_held_by_an_awaiting_payment_reservation_are_not_available(): void
    {
        $this->holdUnit(Reservation::factory()->awaitingPayment()->create(['check_in' => '2026-12-20', 'check_out' => '2026-12-25']));

        $this->assertRejected(fn () => $this->provider->createRequest($this->request()), DatesNotAvailableException::class, 'The selected dates are not available.');
    }

    public function test_blocked_dates_are_not_available(): void
    {
        AvailabilityBlock::factory()->for($this->unit)->create(['start_date' => '2026-12-25', 'end_date' => '2026-12-26']);

        $this->assertRejected(fn () => $this->provider->createRequest($this->request()), DatesNotAvailableException::class, 'The selected dates are not available.');
    }

    public function test_another_pending_request_does_not_take_the_dates(): void
    {
        $other = Reservation::factory()->create(['check_in' => '2026-12-24', 'check_out' => '2026-12-27']);
        $this->holdUnit($other, assignUnit: false);

        $reservation = $this->provider->createRequest($this->request());

        $this->assertSame(ReservationStatus::Pending, $reservation->status);
        $this->assertDatabaseCount('reservations', 2);
    }

    public function test_stay_starting_on_another_guest_check_out_day_is_available(): void
    {
        $this->holdUnit(Reservation::factory()->confirmed()->create(['check_in' => '2026-12-20', 'check_out' => '2026-12-24']));

        $reservation = $this->provider->createRequest($this->request());

        $this->assertSame('2026-12-24', $reservation->check_in->toDateString());
    }

    public function test_night_without_a_price_cannot_be_requested(): void
    {
        RateRule::query()->delete();
        RateRule::factory()->for($this->ratePlan)->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-25']);

        $exception = $this->assertRejected(fn () => $this->provider->createRequest($this->request()), PriceNotConfiguredException::class);

        $this->assertSame(['2026-12-26'], $exception->dates);
    }

    public function test_guests_over_capacity_cannot_be_requested(): void
    {
        $this->assertRejected(
            fn () => $this->provider->createRequest($this->request(adults: 5)),
            GuestCapacityExceededException::class,
            'This stay allows up to 4 adults and 2 children.',
        );

        $this->assertRejected(
            fn () => $this->provider->createRequest($this->request(children: 3)),
            GuestCapacityExceededException::class,
        );
    }

    public function test_check_in_before_today_in_the_property_timezone_is_rejected(): void
    {
        // Still 2026-11-01 in UTC, already 2026-11-02 04:00 in Asia/Makassar.
        Carbon::setTestNow('2026-11-01 20:00:00');

        $this->assertRejected(
            fn () => $this->provider->createRequest($this->request(checkIn: '2026-11-01', checkOut: '2026-11-03')),
            DatesNotAvailableException::class,
            'Check-in cannot be in the past.',
        );

        $reservation = $this->provider->createRequest($this->request(checkIn: '2026-11-02', checkOut: '2026-11-03'));

        $this->assertSame('2026-11-02', $reservation->check_in->toDateString());
    }

    public function test_nightly_prices_are_a_snapshot(): void
    {
        $reservation = $this->provider->createRequest($this->request());

        RateRule::query()->update(['price_per_night' => 9900000]);
        $this->policy->update(['description' => 'Changed later.']);

        $fresh = $reservation->fresh();
        $this->assertSame('5500000.00', $fresh->total);
        $this->assertSame(['1500000.00', '2000000.00', '2000000.00'], $fresh->nights()->orderBy('night_date')->pluck('price')->all());
        $this->assertSame('This booking is non-refundable.', $fresh->policy_snapshot['description']);
    }

    public function test_failure_part_way_rolls_back_everything(): void
    {
        OutboxEvent::creating(fn () => throw new RuntimeException('Outbox unavailable.'));

        try {
            $this->app->make(CreateReservationRequest::class)->handle($this->request());
            $this->fail('Expected RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertSame('Outbox unavailable.', $e->getMessage());
        }

        $this->assertNothingWritten();
    }

    private function request(
        string $checkIn = '2026-12-24',
        string $checkOut = '2026-12-27',
        int $adults = 2,
        int $children = 1,
        ?GuestDetails $guest = null,
        ?string $specialRequests = null,
    ): ReservationRequestData {
        return new ReservationRequestData(
            roomType: $this->roomType,
            ratePlan: $this->ratePlan,
            checkIn: Carbon::parse($checkIn),
            checkOut: Carbon::parse($checkOut),
            adults: $adults,
            children: $children,
            guest: $guest ?? new GuestDetails('Jane Doe', 'jane@example.com', '+62 812 3456 7890', 'Australia'),
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
     * Runs the request, expects it to fail with the given exception and to leave no trace.
     *
     * @template T of Throwable
     *
     * @param  class-string<T>  $exception
     * @return T
     */
    private function assertRejected(callable $request, string $exception, ?string $message = null): Throwable
    {
        $reservations = Reservation::query()->count();
        $guests = Guest::query()->count();

        try {
            $request();
        } catch (Throwable $e) {
            $this->assertInstanceOf($exception, $e);

            if ($message !== null) {
                $this->assertSame($message, $e->getMessage());
            }

            $this->assertDatabaseCount('reservations', $reservations);
            $this->assertDatabaseCount('guests', $guests);
            $this->assertDatabaseCount('reservation_status_logs', 0);
            $this->assertDatabaseCount('outbox_events', 0);

            return $e;
        }

        $this->fail("Expected {$exception}.");
    }

    private function assertNothingWritten(): void
    {
        $this->assertDatabaseCount('guests', 0);
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('reservation_rooms', 0);
        $this->assertDatabaseCount('reservation_nights', 0);
        $this->assertDatabaseCount('reservation_status_logs', 0);
        $this->assertDatabaseCount('outbox_events', 0);
    }
}

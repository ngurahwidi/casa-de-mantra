<?php

namespace Tests\Feature\Actions;

use App\Actions\Reservation\ConfirmReservation;
use App\Enums\OutboxEventStatus;
use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\Reservation\DatesNotAvailableException;
use App\Exceptions\Reservation\InvalidStatusTransitionException;
use App\Exceptions\Reservation\PaymentRequiredException;
use App\Models\AvailabilityBlock;
use App\Models\Guest;
use App\Models\OutboxEvent;
use App\Models\PropertyProfile;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\ReservationStatusLog;
use App\Models\RoomType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class ConfirmReservationTest extends TestCase
{
    use RefreshDatabase;

    private ConfirmReservation $confirm;

    private User $admin;

    private RoomType $roomType;

    private RatePlan $ratePlan;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-01 08:00:00');

        PropertyProfile::factory()->create();

        $this->confirm = $this->app->make(ConfirmReservation::class);
        $this->admin = User::factory()->create();
        $this->roomType = RoomType::factory()->published()->create();
        $this->ratePlan = RatePlan::factory()->for($this->roomType)->create();
        $this->unit = Unit::factory()->for($this->roomType)->create(['code' => 'A']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, array{ReservationStatus}>
     */
    public static function notAwaitingPayment(): array
    {
        return collect(ReservationStatus::cases())
            ->reject(fn (ReservationStatus $status) => $status === ReservationStatus::AwaitingPayment)
            ->mapWithKeys(fn (ReservationStatus $status) => [$status->value => [$status]])
            ->all();
    }

    public function test_paid_reservation_is_confirmed(): void
    {
        $reservation = $this->awaitingPayment();

        $result = $this->confirm->handle($reservation, $this->admin, 'Transfer verified.');

        $this->assertSame($reservation, $result);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);

        $fresh = $reservation->fresh();
        $this->assertSame(ReservationStatus::Confirmed, $fresh->status);
        $this->assertSame('2026-11-01 08:00:00', $fresh->confirmed_at->toDateTimeString());
        $this->assertSame($this->unit->id, ReservationRoom::query()->sole()->unit_id);

        $log = ReservationStatusLog::query()->sole();
        $this->assertSame($this->admin->id, $log->admin_user_id);
        $this->assertSame(ReservationStatus::AwaitingPayment, $log->from_status);
        $this->assertSame(ReservationStatus::Confirmed, $log->to_status);
        $this->assertSame('Transfer verified.', $log->note);

        $event = OutboxEvent::query()->sole();
        $this->assertSame('reservation.confirmed', $event->type);
        $this->assertSame(OutboxEventStatus::Pending, $event->status);
        $this->assertSame($reservation->id, $event->payload['reservation_id']);
    }

    public function test_a_system_action_can_confirm_without_an_admin(): void
    {
        $reservation = $this->confirm->handle($this->awaitingPayment());

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
        $this->assertNull(ReservationStatusLog::query()->sole()->admin_user_id);
    }

    public function test_unpaid_reservation_cannot_be_confirmed(): void
    {
        $reservation = $this->awaitingPayment(ReservationPaymentStatus::Unpaid);

        $this->assertNotConfirmed(
            $reservation,
            PaymentRequiredException::class,
            'A reservation can only be confirmed once it is paid; its payment status is unpaid.',
        );
    }

    public function test_deposit_is_not_enough_by_default(): void
    {
        $reservation = $this->awaitingPayment(ReservationPaymentStatus::Deposit);

        $this->assertNotConfirmed(
            $reservation,
            PaymentRequiredException::class,
            'A reservation can only be confirmed once it is paid; its payment status is deposit.',
        );
    }

    public function test_deposit_is_enough_when_the_property_confirms_on_deposit(): void
    {
        PropertyProfile::current()->update(['confirm_on_deposit' => true]);

        $reservation = $this->confirm->handle($this->awaitingPayment(ReservationPaymentStatus::Deposit), $this->admin);

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
    }

    #[DataProvider('notAwaitingPayment')]
    public function test_only_reservations_awaiting_payment_can_be_confirmed(ReservationStatus $status): void
    {
        $reservation = $this->awaitingPayment();
        $reservation->update(['status' => $status]);

        $exception = $this->assertNotConfirmed(
            $reservation,
            InvalidStatusTransitionException::class,
            "Only reservations awaiting payment can be confirmed; this one is {$status->value}.",
            $status,
        );

        $this->assertSame($status, $exception->from);
        $this->assertSame(ReservationStatus::Confirmed, $exception->to);
    }

    public function test_status_is_read_from_the_database_not_from_a_stale_model(): void
    {
        $reservation = $this->awaitingPayment();
        Reservation::query()->whereKey($reservation->id)->update(['status' => ReservationStatus::Expired]);

        $this->assertNotConfirmed($reservation, InvalidStatusTransitionException::class, null, ReservationStatus::Expired);
    }

    public function test_the_reservation_own_hold_does_not_block_it(): void
    {
        // A pending request for the same dates and another guest's stay ending on check-in day.
        $this->room(Reservation::factory()->create());
        $this->room(Reservation::factory()->confirmed()->create(['check_in' => '2026-12-20', 'check_out' => '2026-12-24']), $this->unit);

        $reservation = $this->confirm->handle($this->awaitingPayment(), $this->admin);

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
    }

    public function test_dates_blocked_since_approval_cannot_be_confirmed(): void
    {
        AvailabilityBlock::factory()->for($this->unit)->create(['start_date' => '2026-12-26', 'end_date' => '2026-12-27']);

        $this->assertNotConfirmed($this->awaitingPayment(), DatesNotAvailableException::class, 'The selected dates are not available.');
    }

    public function test_room_moves_to_another_free_unit_when_its_own_was_blocked(): void
    {
        $unitB = Unit::factory()->for($this->roomType)->create(['code' => 'B']);
        AvailabilityBlock::factory()->for($this->unit)->create(['start_date' => '2026-12-25', 'end_date' => '2026-12-26']);

        $reservation = $this->confirm->handle($this->awaitingPayment(), $this->admin);

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
        $this->assertSame($unitB->id, ReservationRoom::query()->sole()->unit_id);
    }

    public function test_room_keeps_its_unit_while_it_is_free(): void
    {
        $unitB = Unit::factory()->for($this->roomType)->create(['code' => 'B']);
        $reservation = Reservation::factory()->awaitingPayment()->create(['payment_status' => ReservationPaymentStatus::Paid]);
        $this->room($reservation, $unitB);

        $this->confirm->handle($reservation, $this->admin);

        $this->assertSame($unitB->id, ReservationRoom::query()->sole()->unit_id);
    }

    public function test_guest_record_takes_the_contact_details_of_the_confirmed_reservation(): void
    {
        $guest = Guest::factory()->create([
            'full_name' => 'Old Name',
            'email' => 'guest@example.com',
            'phone' => '+62 800 000',
            'country' => 'Australia',
        ]);

        $reservation = $this->awaitingPayment(attributes: [
            'guest_id' => $guest->id,
            'guest_full_name' => 'New Name',
            'guest_email' => 'guest@example.com',
            'guest_phone' => '+62 811 111',
            'guest_country' => 'New Zealand',
        ]);

        $this->confirm->handle($reservation, $this->admin);

        $guest->refresh();
        $this->assertSame('New Name', $guest->full_name);
        $this->assertSame('guest@example.com', $guest->email);
        $this->assertSame('+62 811 111', $guest->phone);
        $this->assertSame('New Zealand', $guest->country);
    }

    public function test_guest_country_is_kept_when_the_reservation_has_none(): void
    {
        $guest = Guest::factory()->create(['country' => 'Australia']);

        $this->confirm->handle($this->awaitingPayment(attributes: ['guest_id' => $guest->id, 'guest_country' => null]), $this->admin);

        $this->assertSame('Australia', $guest->fresh()->country);
    }

    public function test_guest_record_is_untouched_when_confirmation_fails(): void
    {
        $guest = Guest::factory()->create(['full_name' => 'Old Name']);
        $reservation = $this->awaitingPayment(ReservationPaymentStatus::Unpaid, ['guest_id' => $guest->id, 'guest_full_name' => 'New Name']);

        $this->assertNotConfirmed($reservation, PaymentRequiredException::class);
        $this->assertSame('Old Name', $guest->fresh()->full_name);
    }

    public function test_failure_part_way_rolls_back_everything(): void
    {
        $guest = Guest::factory()->create(['full_name' => 'Old Name']);
        Unit::factory()->for($this->roomType)->create(['code' => 'B']);
        AvailabilityBlock::factory()->for($this->unit)->create(['start_date' => '2026-12-25', 'end_date' => '2026-12-26']);
        $reservation = $this->awaitingPayment(attributes: ['guest_id' => $guest->id, 'guest_full_name' => 'New Name']);
        OutboxEvent::creating(fn () => throw new RuntimeException('Outbox unavailable.'));

        $this->assertNotConfirmed($reservation, RuntimeException::class, 'Outbox unavailable.');
        $this->assertSame($this->unit->id, ReservationRoom::query()->sole()->unit_id);
        $this->assertSame('Old Name', $guest->fresh()->full_name);
    }

    /**
     * An approved reservation holding unit A for 24–27 Dec.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function awaitingPayment(
        ReservationPaymentStatus $paymentStatus = ReservationPaymentStatus::Paid,
        array $attributes = [],
    ): Reservation {
        $reservation = Reservation::factory()->awaitingPayment()->create(['payment_status' => $paymentStatus, ...$attributes]);
        $this->room($reservation, $this->unit);

        return $reservation;
    }

    private function room(Reservation $reservation, ?Unit $unit = null): ReservationRoom
    {
        return ReservationRoom::factory()->for($reservation)->create([
            'room_type_id' => $this->roomType->id,
            'unit_id' => $unit?->id,
            'rate_plan_id' => $this->ratePlan->id,
        ]);
    }

    /**
     * Expects confirming to fail with the given exception and to leave the reservation untouched.
     *
     * @template T of Throwable
     *
     * @param  class-string<T>  $exception
     * @return T
     */
    private function assertNotConfirmed(
        Reservation $reservation,
        string $exception,
        ?string $message = null,
        ReservationStatus $status = ReservationStatus::AwaitingPayment,
    ): Throwable {
        try {
            $this->confirm->handle($reservation, $this->admin);
        } catch (Throwable $e) {
            $this->assertInstanceOf($exception, $e);

            if ($message !== null) {
                $this->assertSame($message, $e->getMessage());
            }

            $fresh = $reservation->fresh();
            $this->assertSame($status, $fresh->status);
            $this->assertNull($fresh->confirmed_at);
            $this->assertSame(0, ReservationStatusLog::query()->whereBelongsTo($reservation)->count());
            $this->assertSame(0, OutboxEvent::query()->where('payload->reservation_id', $reservation->id)->count());

            return $e;
        }

        $this->fail("Expected {$exception}.");
    }
}

<?php

namespace App\Actions\Reservation;

use App\Actions\Pricing\CalculatePrice;
use App\Data\Pricing\NightPrice;
use App\Data\Pricing\PriceQuote;
use App\Data\Reservation\ManualReservationData;
use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Exceptions\Pricing\GuestCapacityExceededException;
use App\Exceptions\Pricing\PricingException;
use App\Exceptions\Reservation\DatesNotAvailableException;
use App\Exceptions\Reservation\DuplicateReservationException;
use App\Exceptions\Reservation\InvalidAmountException;
use App\Models\Guest;
use App\Models\PropertyProfile;
use App\Models\Reservation;
use App\Models\Unit;
use App\Models\User;
use App\Services\Reservation\ReservationCodeGenerator;
use App\Services\Reservation\ReservationStateMachine;
use App\Services\Reservation\UnitAllocator;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * An admin enters a booking taken outside the website: by phone, or made on Booking.com or Expedia.
 *
 * The booking is already agreed with the guest, so it is stored as confirmed straight away and
 * unpaid until payments are recorded (RecordPayment). Overlaps are still not allowed: inside one
 * transaction every unit of the room type is locked before any plain read (see
 * ApproveReservation), and the room is given a free unit by UnitAllocator.
 *
 * Without a total the stay is priced from the rate rules by CalculatePrice, with all of its
 * checks. With a total, e.g. the price on the OTA, that amount is the price with taxes included,
 * spread evenly over the nights; only the room type's capacity is checked then, since the stay
 * has already been sold elsewhere.
 *
 * An OTA booking number is stored as external_id, with the source as external_source. The same
 * booking cannot be entered twice, unless the earlier entry was cancelled.
 *
 * Unlike a request from the public site, the details come from an admin, so the guest record
 * takes them right away, as ConfirmReservation does.
 */
class CreateManualReservation
{
    private const SOURCES = [ReservationSource::BookingCom, ReservationSource::Expedia, ReservationSource::Manual];

    public function __construct(
        private readonly CalculatePrice $calculatePrice,
        private readonly UnitAllocator $units,
        private readonly ReservationCodeGenerator $codes,
        private readonly ReservationStateMachine $stateMachine,
    ) {}

    /**
     * @throws PricingException when the stay cannot be sold under the rate plan or exceeds the capacity
     * @throws InvalidAmountException when the total is not a valid amount
     * @throws DatesNotAvailableException when no unit is free or check-in has passed
     * @throws DuplicateReservationException when the OTA booking has already been entered
     */
    public function handle(ManualReservationData $data, User $admin, ?string $note = null): Reservation
    {
        if (! in_array($data->source, self::SOURCES, true)) {
            throw new InvalidArgumentException("A manual reservation cannot have source {$data->source->value}.");
        }

        $profile = PropertyProfile::current();

        if ($data->checkIn->toDateString() < now($profile->timezone)->toDateString()) {
            throw DatesNotAvailableException::inThePast();
        }

        $total = $data->total === null ? null : self::normalizeAmount($data->total);
        $externalId = self::clean($data->externalId);

        // Inserting the room can wait on a gap lock that a concurrent approval holds on
        // reservation_rooms while that approval waits on the units locked here. InnoDB then
        // rolls one of them back, so this is retried.
        return DB::transaction(function () use ($data, $admin, $note, $profile, $total, $externalId): Reservation {
            Unit::query()->where('room_type_id', $data->roomType->getKey())->orderBy('id')->lockForUpdate()->get();

            if ($externalId !== null) {
                $this->ensureNotEntered($data->source, $externalId);
            }

            $quote = $total === null
                ? $this->calculatePrice->handle(
                    $data->roomType,
                    $data->ratePlan,
                    $data->checkIn,
                    $data->checkOut,
                    $data->adults,
                    $data->children,
                )
                : self::agreedPrice($data, $total);

            $contact = CreateReservationRequest::contact($data->guest);

            $reservation = Reservation::create([
                'code' => $this->codes->generate(),
                'guest_id' => $this->guest($contact)->getKey(),
                'guest_full_name' => $contact['full_name'],
                'guest_email' => $contact['email'],
                'guest_phone' => $contact['phone'],
                'guest_country' => $contact['country'],
                'status' => ReservationStatus::Confirmed,
                'source' => $data->source,
                'check_in' => $quote->checkIn,
                'check_out' => $quote->checkOut,
                'adults' => $data->adults,
                'children' => $data->children,
                'special_requests' => self::clean($data->specialRequests),
                'currency' => $profile->currency,
                'subtotal' => $quote->subtotal,
                'tax_total' => $quote->taxTotal,
                'total' => $quote->total,
                'payment_status' => ReservationPaymentStatus::Unpaid,
                'confirmed_at' => now(),
                'external_source' => $externalId === null ? null : $data->source->value,
                'external_id' => $externalId,
                'policy_snapshot' => CreateReservationRequest::policySnapshot($data->ratePlan),
            ]);

            $room = $reservation->reservationRooms()->create([
                'room_type_id' => $data->roomType->getKey(),
                'unit_id' => null,
                'rate_plan_id' => $data->ratePlan->getKey(),
                'total_price' => $quote->subtotal,
            ]);

            $room->nights()->createMany(array_map(fn (NightPrice $night) => [
                'night_date' => $night->date,
                'price' => $night->price,
            ], $quote->nights));

            $rooms = $this->units->allocate($reservation);

            $this->stateMachine->recordCreated($reservation, $admin, $note);

            return $reservation->setRelation('reservationRooms', $rooms);
        }, attempts: 3);
    }

    /**
     * @throws DuplicateReservationException
     */
    private function ensureNotEntered(ReservationSource $source, string $externalId): void
    {
        $code = Reservation::query()
            ->where('external_source', $source->value)
            ->where('external_id', $externalId)
            ->where('status', '!=', ReservationStatus::Cancelled)
            ->value('code');

        if ($code !== null) {
            throw DuplicateReservationException::external($source, $externalId, $code);
        }
    }

    /**
     * The stay at the total the admin entered, with no taxes on top.
     *
     * Each night gets an equal share; the first nights take the leftover cents, so the nights
     * add up to the total.
     *
     * @throws GuestCapacityExceededException
     */
    private static function agreedPrice(ManualReservationData $data, string $total): PriceQuote
    {
        $start = $data->checkIn->toDateString();
        $end = $data->checkOut->toDateString();

        if ($end <= $start) {
            throw new InvalidArgumentException("Check-out {$end} must be after check-in {$start}.");
        }

        if ($data->adults < 1 || $data->children < 0) {
            throw new InvalidArgumentException('A stay needs at least one adult and cannot have negative children.');
        }

        if ($data->ratePlan->room_type_id !== $data->roomType->id) {
            throw new InvalidArgumentException("Rate plan {$data->ratePlan->id} does not belong to room type {$data->roomType->id}.");
        }

        if ($data->adults > $data->roomType->max_adults || $data->children > $data->roomType->max_children) {
            throw GuestCapacityExceededException::for($data->roomType);
        }

        $dates = [];
        foreach (CarbonPeriod::create($start, $end)->excludeEndDate() as $night) {
            $dates[] = $night->toDateString();
        }

        $cents = self::toCents($total);
        $share = intdiv($cents, count($dates));
        $leftover = $cents % count($dates);

        $nights = [];
        foreach ($dates as $i => $date) {
            $nights[] = new NightPrice($date, self::format($share + ($i < $leftover ? 1 : 0)), null);
        }

        return new PriceQuote(
            checkIn: $start,
            checkOut: $end,
            nights: $nights,
            subtotal: $total,
            taxTotal: '0.00',
            total: $total,
        );
    }

    /**
     * The guest with this email, given the details entered, or a new one.
     *
     * @param  array{full_name: string, email: string, phone: string, country: string|null}  $contact
     */
    private function guest(array $contact): Guest
    {
        $guest = Guest::query()->where('email', $contact['email'])->lockForUpdate()->first();

        if ($guest === null) {
            return Guest::create($contact);
        }

        $guest->update([
            'full_name' => $contact['full_name'],
            'phone' => $contact['phone'],
            // An empty country on this booking does not erase one known from an earlier stay.
            'country' => $contact['country'] ?? $guest->country,
        ]);

        return $guest;
    }

    /**
     * The amount as a decimal with two places, e.g. "1500000" becomes "1500000.00".
     *
     * @throws InvalidAmountException
     */
    private static function normalizeAmount(string $amount): string
    {
        $amount = trim($amount);

        // decimal(14,2): at most 12 digits before the point.
        if (preg_match('/^\d{1,12}(\.\d{1,2})?$/', $amount) !== 1 || self::toCents($amount) === 0) {
            throw InvalidAmountException::forTotal($amount);
        }

        return self::format(self::toCents($amount));
    }

    /**
     * Converts a non-negative decimal string such as "1500000.50" to cents without floats.
     */
    private static function toCents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private static function format(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    private static function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}

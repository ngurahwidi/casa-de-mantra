<?php

namespace App\Actions\Reservation;

use App\Actions\Pricing\CalculatePrice;
use App\Contracts\AvailabilityProvider;
use App\Data\Pricing\NightPrice;
use App\Data\Reservation\GuestDetails;
use App\Data\Reservation\ReservationRequestData;
use App\Enums\ReservationPaymentStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Exceptions\Pricing\PricingException;
use App\Exceptions\Reservation\DatesNotAvailableException;
use App\Models\Guest;
use App\Models\PropertyProfile;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Services\Reservation\ReservationCodeGenerator;
use App\Services\Reservation\ReservationStateMachine;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stores a guest's request-to-book from the public site as a pending reservation.
 *
 * The stay is priced with CalculatePrice and must be available right now, but a pending
 * request does not hold its dates: no unit is assigned and nothing is locked. Availability
 * is checked again under lock when an admin approves it (T4.5).
 *
 * Guests are matched by email (case-insensitive). The contact details entered are stored on
 * the reservation itself; an existing guest record keeps its details, because the form is
 * unauthenticated and anyone who knows an email address could otherwise change them. The
 * guest record is refreshed from a reservation only once it is confirmed (T4.6).
 *
 * The nightly prices and the cancellation policy are copied onto the reservation, so later
 * changes to rate rules or the policy do not alter what the guest was quoted.
 */
class CreateReservationRequest
{
    public function __construct(
        private readonly CalculatePrice $calculatePrice,
        private readonly AvailabilityProvider $availability,
        private readonly ReservationCodeGenerator $codes,
        private readonly ReservationStateMachine $stateMachine,
    ) {}

    /**
     * @throws PricingException
     * @throws DatesNotAvailableException
     */
    public function handle(ReservationRequestData $data): Reservation
    {
        $profile = PropertyProfile::current();

        if ($data->checkIn->toDateString() < now($profile->timezone)->toDateString()) {
            throw DatesNotAvailableException::inThePast();
        }

        return DB::transaction(function () use ($data, $profile): Reservation {
            $quote = $this->calculatePrice->handle(
                $data->roomType,
                $data->ratePlan,
                $data->checkIn,
                $data->checkOut,
                $data->adults,
                $data->children,
            );

            if (! $this->availability->isAvailable($data->roomType, $data->checkIn, $data->checkOut)) {
                throw DatesNotAvailableException::taken();
            }

            $contact = self::contact($data->guest);

            $guest = Guest::query()->firstOrCreate(
                ['email' => $contact['email']],
                Arr::except($contact, 'email'),
            );

            $reservation = Reservation::create([
                'code' => $this->codes->generate(),
                'guest_id' => $guest->getKey(),
                'guest_full_name' => $contact['full_name'],
                'guest_email' => $contact['email'],
                'guest_phone' => $contact['phone'],
                'guest_country' => $contact['country'],
                'status' => ReservationStatus::Pending,
                'source' => ReservationSource::DirectWeb,
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
                'policy_snapshot' => $this->policySnapshot($data->ratePlan),
            ]);

            // Taxes are charged per reservation, so the room carries the nightly prices only.
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

            $this->stateMachine->recordCreated($reservation);

            return $reservation;
        });
    }

    /**
     * The guest's details trimmed, with the email lower-cased so it matches one guest.
     *
     * @return array{full_name: string, email: string, phone: string, country: string|null}
     */
    private static function contact(GuestDetails $details): array
    {
        return [
            'full_name' => trim($details->fullName),
            'email' => Str::lower(trim($details->email)),
            'phone' => trim($details->phone),
            'country' => self::clean($details->country),
        ];
    }

    /**
     * The cancellation policy and rate as the guest saw them when requesting.
     *
     * @return array<string, mixed>
     */
    private function policySnapshot(RatePlan $ratePlan): array
    {
        $policy = $ratePlan->cancellationPolicy()->firstOrFail();

        return [
            'cancellation_policy_id' => $policy->getKey(),
            'name' => $policy->name,
            'description' => $policy->description,
            'rules' => $policy->rules,
            'rate_plan_name' => $ratePlan->name,
            'meal_plan' => $ratePlan->meal_plan->value,
        ];
    }

    private static function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}

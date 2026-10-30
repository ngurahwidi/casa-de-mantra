<?php

namespace App\Data\Reservation;

/**
 * Contact details a guest enters with a booking request.
 */
final readonly class GuestDetails
{
    public function __construct(
        public string $fullName,
        public string $email,
        public string $phone,
        public ?string $country = null,
    ) {}
}

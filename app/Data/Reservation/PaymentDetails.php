<?php

namespace App\Data\Reservation;

use App\Enums\PaymentMethod;
use Carbon\CarbonInterface;

/**
 * A payment an admin received for a reservation, in the reservation's currency.
 */
final readonly class PaymentDetails
{
    /**
     * @param  string  $amount  positive decimal with at most two places, e.g. "1500000" or "1500000.50"
     * @param  CarbonInterface|null  $paidAt  when the money was received; now when omitted
     * @param  string|null  $reference  the transfer reference from the bank, if any
     * @param  string|null  $proofPath  path of the transfer proof on the private disk
     */
    public function __construct(
        public string $amount,
        public PaymentMethod $method,
        public ?CarbonInterface $paidAt = null,
        public ?string $reference = null,
        public ?string $proofPath = null,
    ) {}
}

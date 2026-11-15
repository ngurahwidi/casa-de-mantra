<?php

/**
 * Enters one Booking.com booking in its own PHP process and database connection, for
 * the concurrency tests in tests/Feature/Actions.
 *
 * Usage: php tests/Support/create-manual-reservation.php <rate plan id> <admin user id> <check-in> <check-out>
 * The booking is entered at an agreed total, so no rate rules are needed.
 * Prints "created" or "not_available"; any other failure exits non-zero.
 */

use App\Actions\Reservation\CreateManualReservation;
use App\Data\Reservation\GuestDetails;
use App\Data\Reservation\ManualReservationData;
use App\Enums\ReservationSource;
use App\Exceptions\Reservation\DatesNotAvailableException;
use App\Models\RatePlan;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Carbon;

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $ratePlanId, $adminId, $checkIn, $checkOut] = $_SERVER['argv'];

$ratePlan = RatePlan::query()->with('roomType')->findOrFail($ratePlanId);

try {
    $app->make(CreateManualReservation::class)->handle(
        new ManualReservationData(
            source: ReservationSource::BookingCom,
            roomType: $ratePlan->roomType,
            ratePlan: $ratePlan,
            checkIn: Carbon::parse($checkIn),
            checkOut: Carbon::parse($checkOut),
            adults: 2,
            children: 0,
            guest: new GuestDetails('Ota Guest', 'ota-guest@example.com', '+62 811 0000 0000'),
            total: '4500000',
        ),
        User::query()->findOrFail($adminId),
    );

    echo 'created';
} catch (DatesNotAvailableException) {
    echo 'not_available';
}

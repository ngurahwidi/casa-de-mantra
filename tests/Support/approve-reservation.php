<?php

/**
 * Approves one reservation in its own PHP process and database connection, for
 * ApproveReservationConcurrencyTest.
 *
 * Usage: php tests/Support/approve-reservation.php <reservation id> <admin user id>
 * Prints "approved" or "not_available"; any other failure exits non-zero.
 */

use App\Actions\Reservation\ApproveReservation;
use App\Exceptions\Reservation\DatesNotAvailableException;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $reservationId, $adminId] = $_SERVER['argv'];

try {
    $app->make(ApproveReservation::class)->handle(
        Reservation::query()->findOrFail($reservationId),
        User::query()->findOrFail($adminId),
    );

    echo 'approved';
} catch (DatesNotAvailableException) {
    echo 'not_available';
}

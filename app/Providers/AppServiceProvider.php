<?php

namespace App\Providers;

use App\Contracts\AvailabilityProvider;
use App\Contracts\ReservationProvider;
use App\Services\Availability\InternalAvailabilityProvider;
use App\Services\Reservation\InternalReservationProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * All of the container bindings that should be registered.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        AvailabilityProvider::class => InternalAvailabilityProvider::class,
        ReservationProvider::class => InternalReservationProvider::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

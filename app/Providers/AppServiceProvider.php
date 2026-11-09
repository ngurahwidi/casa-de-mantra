<?php

namespace App\Providers;

use App\Contracts\AvailabilityProvider;
use App\Contracts\ReservationProvider;
use App\Services\Availability\InternalAvailabilityProvider;
use App\Services\Reservation\InternalReservationProvider;
use App\View\Composers\SiteLayoutComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
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
        View::composer(
            ['layouts.public', 'site.*'],
            SiteLayoutComposer::class,
        );

        RateLimiter::for('booking', function (Request $request): Limit {
            return Limit::perMinute(5)
                ->by($request->ip())
                ->response(fn () => response(
                    'Too many booking requests from this address. Please wait a minute and try again.',
                    429,
                ));
        });

        RateLimiter::for('booking-status', function (Request $request): Limit {
            return Limit::perMinute(5)
                ->by($request->ip())
                ->response(fn () => response(
                    'Too many status checks from this address. Please wait a minute and try again.',
                    429,
                ));
        });

        RateLimiter::for('booking-proof', function (Request $request): Limit {
            return Limit::perMinute(5)
                ->by($request->ip())
                ->response(fn () => response(
                    'Too many uploads from this address. Please wait a minute and try again.',
                    429,
                ));
        });
    }
}

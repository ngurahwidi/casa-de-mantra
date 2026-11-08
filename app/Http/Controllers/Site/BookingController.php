<?php

namespace App\Http\Controllers\Site;

use App\Contracts\ReservationProvider;
use App\Data\Reservation\GuestDetails;
use App\Data\Reservation\ReservationRequestData;
use App\Data\Site\StaySearchResult;
use App\Exceptions\Pricing\PricingException;
use App\Exceptions\Reservation\DatesNotAvailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Site\StoreBookingRequest;
use App\Models\PropertyProfile;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\Site\StaySearch;
use App\Services\Site\VillaCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The guest-facing request-to-book flow (T6.3).
 *
 * The stay is searched again on the server before the form is shown and before it is
 * submitted, so the summary always matches what CreateReservationRequest will accept.
 * A request never takes a payment and never holds its dates; it becomes a pending
 * reservation for an admin to approve.
 */
class BookingController extends Controller
{
    public function create(Request $request, RoomType $villa, StaySearch $search): View
    {
        $this->ensurePublished($villa);

        $stay = $this->stay(
            $request->query('check_in'),
            $request->query('check_out'),
            $request->query('adults'),
            $request->query('children'),
        );

        if ($stay === null) {
            return $this->render($villa, null);
        }

        $result = $search->search($villa, $stay['check_in'], $stay['check_out'], $stay['adults'], $stay['children']);

        return $this->render($villa, $result, $stay);
    }

    public function store(
        StoreBookingRequest $request,
        RoomType $villa,
        StaySearch $search,
        VillaCatalog $catalog,
        ReservationProvider $reservations,
    ): RedirectResponse {
        $this->ensurePublished($villa);

        $data = $request->validated();
        $stay = [
            'check_in' => CarbonImmutable::parse($data['check_in']),
            'check_out' => CarbonImmutable::parse($data['check_out']),
            'adults' => (int) $data['adults'],
            'children' => (int) ($data['children'] ?? 0),
        ];

        $result = $search->search($villa, $stay['check_in'], $stay['check_out'], $stay['adults'], $stay['children']);

        if (! $result->isAvailable) {
            return $this->backToBooking($villa, $stay, $result->message ?? 'The selected dates are not available.');
        }

        $ratePlan = $catalog->activeRatePlan($villa);

        if ($ratePlan === null) {
            return $this->backToBooking($villa, $stay, 'This villa is not open for booking yet.');
        }

        try {
            $reservation = $reservations->createRequest(new ReservationRequestData(
                roomType: $villa,
                ratePlan: $ratePlan,
                checkIn: $stay['check_in'],
                checkOut: $stay['check_out'],
                adults: $stay['adults'],
                children: $stay['children'],
                guest: new GuestDetails(
                    fullName: $data['full_name'],
                    email: $data['email'],
                    phone: $data['phone'],
                    country: $data['country'] ?? null,
                ),
                specialRequests: $data['special_requests'] ?? null,
            ));
        } catch (DatesNotAvailableException|PricingException $exception) {
            return $this->backToBooking($villa, $stay, $exception->getMessage());
        }

        return redirect()->route('booking.success', ['code' => $reservation->code]);
    }

    public function success(string $code): View
    {
        $reservation = Reservation::query()
            ->with(['reservationRooms.roomType', 'reservationRooms.nights'])
            ->where('code', Str::upper($code))
            ->firstOrFail();

        $profile = rescue(fn () => PropertyProfile::current(), null, false);
        $responseHours = $profile?->pending_reminder_hours;

        return view('site.booking.success', [
            'title' => 'Request received',
            'description' => 'Your booking request has been received. We will confirm availability and reply shortly.',
            'robots' => 'noindex,nofollow',
            'reservation' => $reservation,
            'room' => $reservation->reservationRooms->first(),
            'responseHours' => $responseHours ?? 12,
        ]);
    }

    private function ensurePublished(RoomType $villa): void
    {
        abort_unless($villa->is_published, 404);
    }

    /**
     * The chosen stay from raw request input, or null when it is not a usable date range.
     *
     * @return array{check_in: CarbonImmutable, check_out: CarbonImmutable, adults: int, children: int}|null
     */
    private function stay(mixed $checkIn, mixed $checkOut, mixed $adults, mixed $children): ?array
    {
        $checkIn = self::parseDate($checkIn);
        $checkOut = self::parseDate($checkOut);
        $adults = filter_var($adults, FILTER_VALIDATE_INT);
        $children = filter_var($children ?? 0, FILTER_VALIDATE_INT);

        if ($checkIn === null || $checkOut === null || $checkOut <= $checkIn) {
            return null;
        }

        if ($adults === false || $children === false || $adults < 1 || $children < 0) {
            return null;
        }

        return [
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'adults' => $adults,
            'children' => $children,
        ];
    }

    /**
     * @param  array{check_in: CarbonImmutable, check_out: CarbonImmutable, adults: int, children: int}|null  $stay
     */
    private function render(RoomType $villa, ?StaySearchResult $result, ?array $stay = null): View
    {
        return view('site.booking.create', [
            'title' => 'Request to book',
            'description' => 'Send a request to book '.$villa->name.'. No payment is taken online.',
            'robots' => 'noindex,nofollow',
            'villa' => $villa,
            'result' => $result,
            'adults' => $stay['adults'] ?? null,
            'children' => $stay['children'] ?? null,
        ]);
    }

    /**
     * @param  array{check_in: CarbonImmutable, check_out: CarbonImmutable, adults: int, children: int}  $stay
     */
    private function backToBooking(RoomType $villa, array $stay, string $message): RedirectResponse
    {
        return redirect()
            ->route('booking.create', [
                'villa' => $villa->slug,
                'check_in' => $stay['check_in']->toDateString(),
                'check_out' => $stay['check_out']->toDateString(),
                'adults' => $stay['adults'],
                'children' => $stay['children'],
            ])
            ->withErrors(['stay' => $message])
            ->withInput();
    }

    /**
     * The date as Y-m-d when it is a real calendar date in that format, otherwise null.
     */
    private static function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return null;
        }

        if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return CarbonImmutable::parse($value);
    }
}

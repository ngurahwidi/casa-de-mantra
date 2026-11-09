<?php

namespace App\Http\Controllers\Site;

use App\Actions\Reservation\SubmitPaymentProof;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\Reservation\PaymentNotAcceptedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Site\LookupBookingStatusRequest;
use App\Http\Requests\Site\UploadPaymentProofRequest;
use App\Models\Reservation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The guest-facing booking status page (T6.4).
 *
 * A booking is opened with the reference code and the email address given with the request.
 * Only the reservation id is kept in the session; opening the page on another device asks for
 * the code and email again. Status lookups and proof uploads are rate limited so codes cannot
 * be enumerated.
 */
class BookingStatusController extends Controller
{
    private const SESSION_KEY = 'booking_status.reservation_id';

    public function index(Request $request): View
    {
        $reservation = $this->verified($request);
        $paid = $reservation === null
            ? 0.0
            : (float) $reservation->payments()->where('status', PaymentStatus::Paid)->sum('amount');
        $balance = $reservation === null
            ? 0.0
            : max(0, round((float) $reservation->total - $paid, 2));
        $hasProofAwaitingVerification = $reservation?->pendingPaymentProofs()->exists() ?? false;
        $lastPaymentUpdate = $reservation?->payments()->latest('updated_at')->first();

        return view('site.booking.status', [
            'title' => 'Booking status',
            'description' => 'Check the status of your booking request and see what happens next.',
            'robots' => 'noindex,nofollow',
            'reservation' => $reservation,
            'room' => $reservation?->reservationRooms->first(),
            'paid' => $paid,
            'balance' => $balance,
            'showProofUpload' => $reservation?->status === ReservationStatus::AwaitingPayment && $balance > 0,
            'hasProofAwaitingVerification' => $hasProofAwaitingVerification,
            'lastProofWasRejected' => ! $hasProofAwaitingVerification && $lastPaymentUpdate?->status === PaymentStatus::Failed,
            'lookupCode' => $this->lookupCode($request),
        ]);
    }

    public function lookup(LookupBookingStatusRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $reservation = Reservation::query()
            ->where('code', Str::upper(trim((string) $data['code'])))
            ->where('guest_email', trim((string) $data['email']))
            ->first();

        if ($reservation === null) {
            return redirect()
                ->route('booking.status')
                ->withInput($request->only('code', 'email'))
                ->withErrors([
                    'lookup' => 'We could not find a booking with that code and email address. Please check both and try again.',
                ]);
        }

        $request->session()->put(self::SESSION_KEY, $reservation->getKey());

        return redirect()->route('booking.status');
    }

    public function uploadProof(UploadPaymentProofRequest $request, SubmitPaymentProof $submit): RedirectResponse
    {
        $reservation = $this->verified($request);

        if ($reservation === null) {
            return redirect()
                ->route('booking.status')
                ->withErrors(['lookup' => 'Please enter your booking code and email address again.']);
        }

        /** @var UploadedFile $proof */
        $proof = $request->file('proof');

        try {
            $submit->handle($reservation, $proof);
        } catch (PaymentNotAcceptedException) {
            return redirect()
                ->route('booking.status')
                ->withErrors([
                    'proof' => 'This booking is not waiting for a transfer proof. Please check the status or contact us.',
                ]);
        }

        return redirect()
            ->route('booking.status')
            ->with('status', 'Thank you. We received your transfer proof and will verify it shortly.');
    }

    public function forget(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('booking.status');
    }

    private function verified(Request $request): ?Reservation
    {
        $id = $request->session()->get(self::SESSION_KEY);

        if (! is_string($id)) {
            return null;
        }

        $reservation = Reservation::query()
            ->with(['reservationRooms.roomType', 'reservationRooms.nights'])
            ->find($id);

        if ($reservation === null) {
            $request->session()->forget(self::SESSION_KEY);
        }

        return $reservation;
    }

    private function lookupCode(Request $request): ?string
    {
        $code = $request->query('code');

        return is_string($code) && $code !== '' ? Str::limit($code, 32, '') : null;
    }
}

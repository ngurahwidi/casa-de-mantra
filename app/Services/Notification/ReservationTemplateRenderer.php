<?php

namespace App\Services\Notification;

use App\Actions\Reservation\CancelReservation;
use App\Enums\ReservationStatus;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\NotificationTemplate;
use App\Models\PropertyProfile;
use App\Models\Reservation;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Fills the {{ variable }} placeholders of notification templates.
 *
 * Templates are edited in the admin panel and the values contain guest input, so this is plain
 * text substitution in one pass: never Blade, and a placeholder typed into a value stays as text.
 */
class ReservationTemplateRenderer
{
    /**
     * @param  array<string, string>  $variables
     */
    public function fill(string $template, array $variables): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z_]+)\s*\}\}/',
            fn (array $match): string => $variables[$match[1]] ?? $match[0],
            $template,
        );
    }

    /**
     * The value of every placeholder in NotificationTemplate::PLACEHOLDERS.
     *
     * @return array<string, string>
     */
    public function variables(Reservation $reservation, PropertyProfile $profile): array
    {
        $variables = [
            'property_name' => $profile->name,
            'property_email' => (string) $profile->email,
            'property_whatsapp' => (string) $profile->whatsapp,
            'property_address' => (string) $profile->address,
            'checkin_time' => Carbon::parse($profile->checkin_time)->format('H:i'),
            'checkout_time' => Carbon::parse($profile->checkout_time)->format('H:i'),
            'guest_name' => $reservation->guest_full_name,
            'guest_email' => $reservation->guest_email,
            'guest_phone' => $reservation->guest_phone,
            'reservation_code' => $reservation->code,
            'check_in' => $reservation->check_in->format('j M Y'),
            'check_out' => $reservation->check_out->format('j M Y'),
            'nights' => (string) (int) $reservation->check_in->diffInDays($reservation->check_out),
            'adults' => (string) $reservation->adults,
            'children' => (string) $reservation->children,
            'special_requests' => $reservation->special_requests ?? '-',
            'total' => Money::format($reservation->total, $reservation->currency),
            'status_url' => route('booking.status'),
            'payment_deadline' => self::paymentDeadline($reservation, $profile),
            'payment_instructions' => self::paymentInstructions($profile),
            'reject_reason' => (string) $reservation->reject_reason,
            'cancel_reason' => self::cancelReason($reservation),
            'admin_url' => ReservationResource::getUrl('view', ['record' => $reservation], panel: 'admin'),
        ];

        return array_intersect_key($variables, array_flip(NotificationTemplate::PLACEHOLDERS));
    }

    private static function paymentDeadline(Reservation $reservation, PropertyProfile $profile): string
    {
        return $reservation->expires_at === null
            ? ''
            : $reservation->expires_at->copy()->timezone($profile->timezone)->format('j M Y, H:i').' ('.$profile->timezone.')';
    }

    private static function paymentInstructions(PropertyProfile $profile): string
    {
        $bank = $profile->bank_account_info ?? [];

        return collect([
            'Bank' => $bank['bank'] ?? null,
            'Account name' => $bank['account_name'] ?? null,
            'Account number' => $bank['account_number'] ?? null,
            'SWIFT code' => $bank['swift_code'] ?? null,
        ])
            ->filter(fn (mixed $value): bool => filled($value))
            ->map(fn (mixed $value, string $label): string => "{$label}: {$value}")
            ->implode("\n");
    }

    /**
     * The reason the admin gave, without the refund remark CancelReservation adds for the status
     * history; the email template states the refund policy itself.
     */
    private static function cancelReason(Reservation $reservation): string
    {
        if ($reservation->status !== ReservationStatus::Cancelled) {
            return '';
        }

        $note = (string) $reservation->statusLogs()->where('to_status', ReservationStatus::Cancelled)->value('note');

        return Str::replaceLast("\n".CancelReservation::NO_REFUND_NOTE, '', $note);
    }
}

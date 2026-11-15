<?php

namespace App\Enums;

/**
 * Where the bookings of a reservation source come from.
 *
 * Website is the booking form on this site and belongs only to the built-in source; admins
 * give the sources they add one of the other categories.
 */
enum ReservationSourceCategory: string
{
    case Website = 'website';
    case Ota = 'ota';
    case Direct = 'direct';

    public function label(): string
    {
        return match ($this) {
            self::Website => 'Website',
            self::Ota => 'OTA',
            self::Direct => 'Direct',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Website => 'Requests from the booking form on this website.',
            self::Ota => 'Booked on an online travel agency, e.g. Booking.com or Agoda.',
            self::Direct => 'Taken directly from the guest, e.g. by phone, WhatsApp, email or walk-in.',
        };
    }

    /**
     * The categories an admin can give a source.
     *
     * @return list<self>
     */
    public static function selectable(): array
    {
        return [self::Ota, self::Direct];
    }
}

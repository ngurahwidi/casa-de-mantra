<?php

namespace App\Support;

use App\Models\PropertyProfile;

/**
 * Builds wa.me chat links to the property's WhatsApp number from property_profiles.
 */
final class WhatsApp
{
    /**
     * A chat link with a prefilled message, or null when the property has no WhatsApp number.
     *
     * @param  string|null  $message  defaults to a general enquiry addressed to the property
     */
    public static function url(?PropertyProfile $profile, ?string $message = null): ?string
    {
        $number = preg_replace('/\D+/', '', (string) $profile?->whatsapp);

        if ($profile === null || $number === '') {
            return null;
        }

        $message ??= sprintf('Hello %s, I would like to ask about a stay.', $profile->name);

        return "https://wa.me/{$number}?text=".rawurlencode($message);
    }
}

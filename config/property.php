<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Initial Property Profile
    |--------------------------------------------------------------------------
    |
    | Starting values for the single property_profiles row created by
    | PropertyProfileSeeder. After the first seed the row is managed from the
    | admin panel; re-running the seeder never overwrites it.
    |
    */

    'type' => env('PROPERTY_TYPE', 'villa'),

    'name' => env('PROPERTY_NAME', 'Demo Villa'),

    'timezone' => env('PROPERTY_TIMEZONE', 'Asia/Makassar'),

    'currency' => env('PROPERTY_CURRENCY', 'IDR'),

    'checkin_time' => env('PROPERTY_CHECKIN_TIME', '14:00'),

    'checkout_time' => env('PROPERTY_CHECKOUT_TIME', '12:00'),

    'whatsapp' => env('PROPERTY_WHATSAPP') ?: null,

    'email' => env('PROPERTY_EMAIL') ?: null,

    'address' => env('PROPERTY_ADDRESS') ?: null,

    'lat' => env('PROPERTY_LAT') ?: null,

    'lng' => env('PROPERTY_LNG') ?: null,

    'payment_window_hours' => (int) env('PROPERTY_PAYMENT_WINDOW_HOURS', 24),

    'pending_reminder_hours' => (int) env('PROPERTY_PENDING_REMINDER_HOURS', 12),

];

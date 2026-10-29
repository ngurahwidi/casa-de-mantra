<?php

namespace Database\Seeders;

use App\Enums\PropertyType;
use App\Models\PropertyProfile;
use Illuminate\Database\Seeder;

class PropertyProfileSeeder extends Seeder
{
    /**
     * Create the single property profile from PROPERTY_* values in .env.
     *
     * An existing profile is left untouched, so re-running never undoes admin edits.
     */
    public function run(): void
    {
        PropertyProfile::firstOrCreate(
            ['id' => PropertyProfile::ID],
            [
                'type' => PropertyType::from(config('property.type')),
                'name' => config('property.name'),
                'timezone' => config('property.timezone'),
                'currency' => config('property.currency'),
                'checkin_time' => config('property.checkin_time'),
                'checkout_time' => config('property.checkout_time'),
                'whatsapp' => config('property.whatsapp'),
                'email' => config('property.email'),
                'address' => config('property.address'),
                'lat' => config('property.lat'),
                'lng' => config('property.lng'),
                'payment_window_hours' => config('property.payment_window_hours'),
                'pending_reminder_hours' => config('property.pending_reminder_hours'),
            ],
        );

        // DatabaseSeeder runs without model events, so the saved hook does not clear the cache.
        PropertyProfile::forgetCurrent();
    }
}

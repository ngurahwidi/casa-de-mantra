<?php

namespace Database\Factories;

use App\Enums\PropertyType;
use App\Models\PropertyProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyProfile>
 */
class PropertyProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => PropertyProfile::ID,
            'type' => PropertyType::Villa,
            'name' => fake()->company().' Villa',
            'timezone' => 'Asia/Makassar',
            'currency' => 'IDR',
            'checkin_time' => '14:00:00',
            'checkout_time' => '12:00:00',
            'whatsapp' => '+62'.fake()->numerify('8##########'),
            'email' => fake()->unique()->safeEmail(),
            'address' => fake()->address(),
            'lat' => fake()->latitude(-9, -8),
            'lng' => fake()->longitude(115, 116),
            'seo_settings' => [],
            'theme' => [],
            'bank_account_info' => [
                'bank' => 'Bank Example',
                'account_name' => fake()->name(),
                'account_number' => fake()->numerify('##########'),
            ],
            'payment_window_hours' => 24,
            'pending_reminder_hours' => 12,
            'confirm_on_deposit' => false,
        ];
    }
}

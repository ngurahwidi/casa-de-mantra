<?php

namespace Database\Seeders;

use App\Models\Amenity;
use Illuminate\Database\Seeder;

class AmenitySeeder extends Seeder
{
    /**
     * Common villa amenities as [name, heroicon, category].
     *
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    private const AMENITIES = [
        ['Private swimming pool', 'heroicon-o-sun', 'outdoor'],
        ['Garden', 'heroicon-o-sun', 'outdoor'],
        ['Outdoor dining area', 'heroicon-o-cake', 'outdoor'],
        ['Barbecue grill', 'heroicon-o-fire', 'outdoor'],
        ['Free Wi-Fi', 'heroicon-o-wifi', 'general'],
        ['Air conditioning', 'heroicon-o-bolt', 'general'],
        ['Free parking', 'heroicon-o-truck', 'general'],
        ['Washing machine', 'heroicon-o-sparkles', 'general'],
        ['Fully equipped kitchen', 'heroicon-o-home', 'kitchen'],
        ['Refrigerator', 'heroicon-o-archive-box', 'kitchen'],
        ['Coffee and tea maker', 'heroicon-o-beaker', 'kitchen'],
        ['Bed linen and towels', 'heroicon-o-home', 'bedroom'],
        ['Hot water', 'heroicon-o-fire', 'bathroom'],
        ['Toiletries', 'heroicon-o-sparkles', 'bathroom'],
        ['Hair dryer', 'heroicon-o-bolt', 'bathroom'],
        ['Smart TV', 'heroicon-o-tv', 'entertainment'],
        ['Daily housekeeping', 'heroicon-o-sparkles', 'services'],
        ['Airport transfer (on request)', 'heroicon-o-truck', 'services'],
        ['In-room safe', 'heroicon-o-lock-closed', 'safety'],
        ['24-hour security', 'heroicon-o-shield-check', 'safety'],
        ['First aid kit', 'heroicon-o-lifebuoy', 'safety'],
    ];

    /**
     * Create the common amenity list; existing amenities (matched by name) are left untouched.
     */
    public function run(): void
    {
        foreach (self::AMENITIES as [$name, $icon, $category]) {
            Amenity::firstOrCreate(['name' => $name], ['icon' => $icon, 'category' => $category]);
        }
    }
}

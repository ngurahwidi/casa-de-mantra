<?php

namespace Tests\Feature\Seeders;

use App\Enums\MealPlan;
use App\Enums\NotificationChannel;
use App\Enums\PropertyType;
use App\Enums\UnitStatus;
use App\Enums\UserRole;
use App\Models\Amenity;
use App\Models\CancellationPolicy;
use App\Models\NotificationTemplate;
use App\Models\PropertyProfile;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\RoomType;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\AmenitySeeder;
use Database\Seeders\CancellationPolicySeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\PropertyProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BaseDataSeedersTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPLATE_CODES = [
        'reservation_received',
        'reservation_awaiting_payment',
        'reservation_confirmed',
        'reservation_rejected',
        'reservation_expired',
        'reservation_cancelled',
        'admin_new_request',
        'pre_arrival_reminder',
    ];

    public function test_property_profile_is_seeded_from_config(): void
    {
        config([
            'property.name' => 'Seeded Villa',
            'property.whatsapp' => '+6281234567890',
            'property.lat' => '-8.6500000',
        ]);

        $this->seed(PropertyProfileSeeder::class);

        $profile = PropertyProfile::current();
        $this->assertSame('Seeded Villa', $profile->name);
        $this->assertSame(PropertyType::Villa, $profile->type);
        $this->assertSame('Asia/Makassar', $profile->timezone);
        $this->assertSame('IDR', $profile->currency);
        $this->assertSame('14:00:00', $profile->checkin_time);
        $this->assertSame('12:00:00', $profile->checkout_time);
        $this->assertSame('+6281234567890', $profile->whatsapp);
        $this->assertSame('-8.6500000', $profile->lat);
        $this->assertNull($profile->lng);
        $this->assertSame(24, $profile->payment_window_hours);
    }

    public function test_property_profile_seeder_keeps_admin_edits(): void
    {
        $this->seed(PropertyProfileSeeder::class);
        PropertyProfile::current()->update(['name' => 'Renamed By Admin']);

        config(['property.name' => 'Something Else']);
        $this->seed(PropertyProfileSeeder::class);

        $this->assertSame(1, PropertyProfile::count());
        $this->assertSame('Renamed By Admin', PropertyProfile::current()->name);
    }

    public function test_non_refundable_policy_is_seeded_once(): void
    {
        $this->seed(CancellationPolicySeeder::class);
        $this->seed(CancellationPolicySeeder::class);

        $policy = CancellationPolicy::sole();
        $this->assertSame(CancellationPolicy::NON_REFUNDABLE, $policy->name);
        $this->assertSame([['refund_percent' => 0]], $policy->rules);
        $this->assertStringContainsString('non-refundable', $policy->description);
    }

    public function test_amenities_are_seeded_once(): void
    {
        $this->seed(AmenitySeeder::class);
        $count = Amenity::count();
        $this->seed(AmenitySeeder::class);

        $this->assertGreaterThanOrEqual(10, $count);
        $this->assertSame($count, Amenity::count());
        $this->assertTrue(Amenity::where('name', 'Private swimming pool')->exists());
        $this->assertTrue(Amenity::where('name', 'Free Wi-Fi')->exists());
        $this->assertSame(0, Amenity::whereNull('icon')->orWhereNull('category')->count());
    }

    public function test_all_notification_templates_are_seeded_as_active_email(): void
    {
        $this->seed(NotificationTemplateSeeder::class);

        $templates = NotificationTemplate::all();
        $this->assertEqualsCanonicalizing(self::TEMPLATE_CODES, $templates->pluck('code')->all());

        foreach ($templates as $template) {
            $this->assertSame(NotificationChannel::Email, $template->channel);
            $this->assertTrue($template->is_active);
            $this->assertNotEmpty($template->subject);
            $this->assertStringContainsString('{{ reservation_code }}', $template->subject.$template->body);
            // The stay summary is interpolated without leaking heredoc indentation.
            $this->assertDoesNotMatchRegularExpression('/^ +\S/m', $template->body);
        }
    }

    public function test_notification_template_seeder_keeps_admin_edits(): void
    {
        $this->seed(NotificationTemplateSeeder::class);
        NotificationTemplate::where('code', 'reservation_confirmed')->update(['subject' => 'Edited']);

        $this->seed(NotificationTemplateSeeder::class);

        $this->assertSame(count(self::TEMPLATE_CODES), NotificationTemplate::count());
        $this->assertSame('Edited', NotificationTemplate::where('code', 'reservation_confirmed')->value('subject'));
    }

    public function test_demo_seeder_creates_a_sellable_room_type(): void
    {
        // Still 2026 in UTC, already 2027 in Asia/Makassar (UTC+8).
        Carbon::setTestNow('2026-12-31 20:00:00');
        $this->seed([PropertyProfileSeeder::class, CancellationPolicySeeder::class, AmenitySeeder::class]);

        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        $roomType = RoomType::sole();
        $this->assertTrue($roomType->is_published);
        $this->assertSame(Amenity::count(), $roomType->amenities()->count());

        $this->assertSame(UnitStatus::Active, Unit::sole()->status);

        $ratePlan = RatePlan::sole();
        $this->assertTrue($ratePlan->roomType->is($roomType));
        $this->assertSame(CancellationPolicy::NON_REFUNDABLE, $ratePlan->name);
        $this->assertSame(CancellationPolicy::NON_REFUNDABLE, $ratePlan->cancellationPolicy->name);
        $this->assertSame(MealPlan::RoomOnly, $ratePlan->meal_plan);
        $this->assertTrue($ratePlan->is_active);

        $rule = RateRule::sole();
        $this->assertTrue($rule->ratePlan->is($ratePlan));
        $this->assertSame(RateRule::ALL_DAYS, $rule->days_of_week_mask);
        $this->assertNull($rule->end_date);
        $this->assertEquals(Carbon::parse('2027-01-01'), $rule->start_date);
        $this->assertSame('2500000.00', $rule->price_per_night);
    }

    public function test_demo_seeder_does_nothing_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->seed([PropertyProfileSeeder::class, CancellationPolicySeeder::class, DemoSeeder::class]);

        $this->assertSame(0, RoomType::count());
        $this->assertSame(0, RatePlan::count());
    }

    public function test_database_seeder_seeds_everything(): void
    {
        config(['admin.email' => 'owner@example.com', 'admin.password' => 'secret-pass-123']);

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, PropertyProfile::count());
        $this->assertSame(UserRole::Owner, User::sole()->role);
        $this->assertSame(1, CancellationPolicy::count());
        $this->assertSame(count(self::TEMPLATE_CODES), NotificationTemplate::count());
        $this->assertSame(1, RoomType::count());
        $this->assertSame(1, RateRule::count());
    }
}

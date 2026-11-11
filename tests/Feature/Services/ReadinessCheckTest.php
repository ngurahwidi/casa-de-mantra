<?php

namespace Tests\Feature\Services;

use App\Actions\Pricing\SetBasePrice;
use App\Enums\UnitStatus;
use App\Models\CancellationPolicy;
use App\Models\NotificationTemplate;
use App\Models\PropertyProfile;
use App\Models\RateRule;
use App\Models\RoomType;
use App\Models\Unit;
use App\Services\Readiness\ReadinessCheck;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReadinessCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 6 Nov 2026, 08:00 in Asia/Makassar; the priced window starts there.
        Carbon::setTestNow('2026-11-05 16:00:00');

        Storage::fake('public');

        PropertyProfile::factory()->create(['timezone' => 'Asia/Makassar', 'currency' => 'IDR']);
        CancellationPolicy::factory()->create(['name' => CancellationPolicy::NON_REFUNDABLE]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_complete_profile_passes_and_blank_fields_are_reported_by_name(): void
    {
        $check = app(ReadinessCheck::class);

        $this->assertSame([], $check->missingProfileFields());

        $profile = PropertyProfile::current();
        $profile->update(['whatsapp' => null, 'email' => '', 'bank_account_info' => []]);

        $this->assertSame(['whatsapp', 'email', 'bank_account_info'], $check->missingProfileFields());
    }

    public function test_required_notification_templates_must_exist_and_be_active(): void
    {
        $check = app(ReadinessCheck::class);

        // The seeder creates every template of the booking flow, plus the pre-arrival reminder.
        $this->seed(NotificationTemplateSeeder::class);
        $this->assertSame([], $check->inactiveNotificationTemplates());

        NotificationTemplate::query()->where('code', 'reservation_confirmed')->delete();
        NotificationTemplate::query()->where('code', 'admin_new_request')->update(['is_active' => false]);

        $this->assertSame(
            ['reservation_confirmed', 'admin_new_request'],
            $check->inactiveNotificationTemplates(),
        );
    }

    public function test_a_room_type_is_complete_only_with_capacity_photo_unit_and_prices(): void
    {
        $roomType = RoomType::factory()->create();

        $readiness = app(ReadinessCheck::class)->roomType($roomType);

        $this->assertTrue($readiness->hasCapacity);
        $this->assertFalse($readiness->isComplete());
        $this->assertSame(
            ['add at least one photo', 'add at least one active unit', 'set a base price'],
            $readiness->failures(),
        );
        // Without a base price the whole window is one run of missing nights.
        $this->assertSame(['6 Nov 2026 – 5 Nov 2027 (365 nights)'], array_map(
            fn ($range): string => $range->label(),
            $readiness->unpricedRanges,
        ));

        Unit::factory()->for($roomType)->create(['status' => UnitStatus::Inactive]);
        $this->assertFalse(app(ReadinessCheck::class)->roomType($roomType->fresh())->hasActiveUnit);

        Unit::factory()->for($roomType)->create(['status' => UnitStatus::Active]);
        $this->addPhoto($roomType);
        app(SetBasePrice::class)->handle($roomType, '2500000');

        $readiness = app(ReadinessCheck::class)->roomType($roomType->fresh());

        $this->assertTrue($readiness->hasPhoto);
        $this->assertTrue($readiness->hasActiveUnit);
        $this->assertTrue($readiness->hasBasePrice);
        $this->assertTrue($readiness->hasAllNightsPriced());
        $this->assertTrue($readiness->isComplete());
        $this->assertSame([], $readiness->failures());
    }

    public function test_a_room_type_without_capacity_fails_the_capacity_check(): void
    {
        // The database already refuses a capacity below one adult; the check still guards
        // room types that are not saved yet, as the publish guard may see them.
        $readiness = app(ReadinessCheck::class)->roomType(new RoomType(['max_adults' => 0]));

        $this->assertFalse($readiness->hasCapacity);
        $this->assertContains('set a capacity of at least one adult', $readiness->failures());
    }

    public function test_nights_before_the_base_price_starts_are_grouped_into_one_run(): void
    {
        $roomType = $this->completeRoomType();

        // The base price normally starts today; a start ten days out leaves a ten-night gap.
        $roomType->nonRefundableRatePlan()->sole()->rateRules()->sole()
            ->update(['start_date' => '2026-11-16']);

        $readiness = app(ReadinessCheck::class)->roomType($roomType->fresh());

        $this->assertFalse($readiness->hasAllNightsPriced());
        $this->assertSame(
            ['6 Nov 2026 – 15 Nov 2026 (10 nights)'],
            array_map(fn ($range): string => $range->label(), $readiness->unpricedRanges),
        );
        $this->assertSame(
            ['price every night of the next 365 days; the first gap is 6 Nov 2026 – 15 Nov 2026 (10 nights)'],
            $readiness->failures(),
        );
    }

    public function test_single_nights_without_a_price_become_runs_of_one_night(): void
    {
        $roomType = $this->completeRoomType();

        // A base price that skips Sundays leaves every Sunday unpriced, each its own run.
        $roomType->nonRefundableRatePlan()->sole()->rateRules()->sole()
            ->update(['days_of_week_mask' => RateRule::ALL_DAYS - 64]);

        $readiness = app(ReadinessCheck::class)->roomType($roomType->fresh());

        $this->assertSame('8 Nov 2026 (1 night)', $readiness->unpricedRanges[0]->label());
        $this->assertSame(1, $readiness->unpricedRanges[0]->nights());
        $this->assertGreaterThan(1, count($readiness->unpricedRanges));
    }

    public function test_an_inactive_rate_plan_is_no_base_price(): void
    {
        $roomType = $this->completeRoomType();

        $roomType->nonRefundableRatePlan()->sole()->update(['is_active' => false]);

        $readiness = app(ReadinessCheck::class)->roomType($roomType->fresh());

        $this->assertFalse($readiness->hasBasePrice);
        $this->assertFalse($readiness->hasAllNightsPriced());
        $this->assertContains('set a base price', $readiness->failures());
    }

    public function test_the_villa_is_ready_to_sell_only_when_everything_is_in_place(): void
    {
        $check = app(ReadinessCheck::class);

        // Nothing is set up: the profile is complete but there are no templates and no room types.
        $this->assertFalse($check->report()->ready);
        $this->assertContains('notification templates are missing or inactive', $check->report()->issues);
        $this->assertContains('no complete room type is published', $check->report()->issues);

        // A complete room type that is not published does not open sales.
        $this->completeRoomType();
        $this->assertFalse($check->report()->ready);
        $this->assertContains('no complete room type is published', $check->report()->issues);

        // A published but incomplete room type is its own issue, and does not open sales.
        RoomType::factory()->published()->create();
        $this->assertFalse($check->report()->ready);
        $this->assertContains('One published room type is incomplete', $check->report()->issues);
        $this->assertSame(1, $check->report()->incompletePublishedRoomTypes);

        // Everything in place: templates active and a complete room type published.
        $this->seed(NotificationTemplateSeeder::class);
        $sellable = $this->completeRoomType();
        $sellable->update(['is_published' => true]);

        $this->assertTrue($check->report()->ready);
        // The incomplete published room type is still reported, although the villa is ready.
        $this->assertSame(['One published room type is incomplete'], $check->report()->issues);
    }

    public function test_an_incomplete_profile_blocks_sales(): void
    {
        $this->seed(NotificationTemplateSeeder::class);
        $sellable = $this->completeRoomType();
        $sellable->update(['is_published' => true]);

        $check = app(ReadinessCheck::class);
        $this->assertTrue($check->report()->ready);

        PropertyProfile::current()->update(['email' => null]);
        $this->assertFalse($check->report()->ready);
        $this->assertContains('the property profile is incomplete', $check->report()->issues);
    }

    /**
     * A room type with a photo, an active unit and a base price, unpublished.
     */
    private function completeRoomType(): RoomType
    {
        $roomType = RoomType::factory()->create(['name' => 'Garden Villa']);
        Unit::factory()->for($roomType)->create(['status' => UnitStatus::Active]);
        $this->addPhoto($roomType);
        app(SetBasePrice::class)->handle($roomType, '2500000');

        return $roomType->fresh();
    }

    private function addPhoto(RoomType $roomType): void
    {
        $roomType->addMedia(UploadedFile::fake()->image('pool.jpg'))->toMediaCollection(RoomType::GALLERY);
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Actions\Pricing\SetBasePrice;
use App\Enums\UnitStatus;
use App\Filament\Pages\ReadyToSell;
use App\Filament\Widgets\ReservationStatsOverview;
use App\Models\CancellationPolicy;
use App\Models\PropertyProfile;
use App\Models\RoomType;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ReadyToSellTest extends TestCase
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

        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_readiness_stat_is_on_the_dashboard(): void
    {
        // It shares ReservationStatsOverview, so it sits beside the occupancy stat.
        Livewire::test(ReservationStatsOverview::class)
            ->assertSee('Ready to sell?');
    }

    public function test_the_page_lists_every_failed_check(): void
    {
        PropertyProfile::current()->update(['whatsapp' => null]);
        RoomType::factory()->create(['name' => 'Garden Villa']);

        $this->get(ReadyToSell::getUrl())
            ->assertOk()
            ->assertSee('Not ready to sell')
            ->assertSee('the property profile is incomplete')
            ->assertSee('notification templates are missing or inactive')
            ->assertSee('no complete room type is published')
            ->assertSee('Missing: WhatsApp number')
            ->assertSee('Missing or inactive: Reservation received')
            ->assertSee('Garden Villa')
            ->assertSee('At least one photo')
            // Without a base price the whole year is one run of nights without a price.
            ->assertSee('6 Nov 2026 – 5 Nov 2027 (365 nights)');
    }

    public function test_the_page_is_green_when_everything_is_in_place(): void
    {
        $this->seed(NotificationTemplateSeeder::class);
        $sellable = $this->completeRoomType();
        $sellable->update(['is_published' => true]);

        $this->get(ReadyToSell::getUrl())
            ->assertOk()
            ->assertSee('Ready to sell')
            ->assertDontSee('Not ready to sell')
            ->assertSee('Everything the booking flow needs is in place');
    }

    public function test_the_page_reports_a_published_room_type_that_is_not_sellable(): void
    {
        $this->seed(NotificationTemplateSeeder::class);
        RoomType::factory()->published()->create(['name' => 'Pool Villa']); // no photo, unit or price

        $this->get(ReadyToSell::getUrl())
            ->assertOk()
            ->assertSee('Not ready to sell')
            ->assertSee('One published room type is incomplete');
    }

    public function test_the_widget_says_how_many_issues_need_fixing(): void
    {
        // Complete profile, but no templates and no room types: two issues.
        Livewire::test(ReservationStatsOverview::class)
            ->assertSee('Ready to sell?')
            ->assertSee('2 issues to fix')
            ->assertDontSee('Every check passed');
    }

    public function test_the_widget_is_green_when_the_villa_can_sell(): void
    {
        $this->seed(NotificationTemplateSeeder::class);
        $sellable = $this->completeRoomType();
        $sellable->update(['is_published' => true]);

        Livewire::test(ReservationStatsOverview::class)
            ->assertSee('Every check passed')
            ->assertDontSee('issues to fix');
    }

    /**
     * A room type with a photo, an active unit and a base price, unpublished.
     */
    private function completeRoomType(): RoomType
    {
        $roomType = RoomType::factory()->create(['name' => 'Garden Villa']);
        Unit::factory()->for($roomType)->create(['status' => UnitStatus::Active]);
        $roomType->addMedia(UploadedFile::fake()->image('pool.jpg'))->toMediaCollection(RoomType::GALLERY);
        app(SetBasePrice::class)->handle($roomType, '2500000');

        return $roomType->fresh();
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Enums\IcalChannel;
use App\Enums\IcalDirection;
use App\Filament\Resources\IcalFeeds\Pages\ManageIcalFeeds;
use App\Models\AvailabilityBlock;
use App\Models\IcalFeed;
use App\Models\RoomType;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class IcalFeedResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->owner()->create());
    }

    public function test_import_feed_is_created_with_its_calendar_link(): void
    {
        $roomType = RoomType::factory()->create();

        Livewire::test(ManageIcalFeeds::class)
            ->callAction('create', [
                'room_type_id' => $roomType->id,
                'channel' => IcalChannel::Booking->value,
                'direction' => IcalDirection::Import->value,
                'url' => 'https://admin.booking.com/hotel/ical/123.ics',
            ])
            ->assertHasNoFormErrors();

        $feed = IcalFeed::sole();
        $this->assertTrue($feed->roomType->is($roomType));
        $this->assertSame(IcalDirection::Import, $feed->direction);
        $this->assertSame('https://admin.booking.com/hotel/ical/123.ics', $feed->url);
        $this->assertNull($feed->export_token);
    }

    public function test_import_feed_needs_an_http_or_https_link(): void
    {
        $roomType = RoomType::factory()->create();
        $feed = ['room_type_id' => $roomType->id, 'channel' => IcalChannel::Airbnb->value, 'direction' => IcalDirection::Import->value];

        Livewire::test(ManageIcalFeeds::class)
            ->callAction('create', $feed)
            ->assertHasFormErrors(['url' => 'required']);

        Livewire::test(ManageIcalFeeds::class)
            ->callAction('create', [...$feed, 'url' => 'ftp://example.com/calendar.ics'])
            ->assertHasFormErrors(['url' => 'url']);

        $this->assertSame(0, IcalFeed::count());
    }

    public function test_export_feed_is_created_with_a_token_and_no_link(): void
    {
        $roomType = RoomType::factory()->create();

        Livewire::test(ManageIcalFeeds::class)
            ->callAction('create', [
                'room_type_id' => $roomType->id,
                'channel' => IcalChannel::Expedia->value,
                'direction' => IcalDirection::Export->value,
                'url' => 'https://ignored.example.com/calendar.ics',
            ])
            ->assertHasNoFormErrors();

        $feed = IcalFeed::sole();
        $this->assertSame(IcalDirection::Export, $feed->direction);
        $this->assertNull($feed->url);
        $this->assertNotEmpty($feed->export_token);
    }

    public function test_editing_keeps_the_room_type_and_direction(): void
    {
        $roomType = RoomType::factory()->create();
        $feed = IcalFeed::factory()->for($roomType)->create(['channel' => IcalChannel::Booking]);

        Livewire::test(ManageIcalFeeds::class)
            ->callAction(TestAction::make('edit')->table($feed), [
                'room_type_id' => RoomType::factory()->create()->id,
                'direction' => IcalDirection::Export->value,
                'channel' => IcalChannel::Other->value,
                'url' => 'https://calendar.example.com/new.ics',
            ])
            ->assertHasNoFormErrors();

        $feed->refresh();
        $this->assertTrue($feed->roomType->is($roomType));
        $this->assertSame(IcalDirection::Import, $feed->direction);
        $this->assertSame(IcalChannel::Other, $feed->channel);
        $this->assertSame('https://calendar.example.com/new.ics', $feed->url);
    }

    public function test_list_shows_the_last_sync_status(): void
    {
        $synced = IcalFeed::factory()->create(['last_status' => IcalFeed::STATUS_OK, 'last_synced_at' => now()]);
        $failing = IcalFeed::factory()->create(['last_status' => 'The calendar link returned HTTP 404.', 'last_synced_at' => now()]);
        $new = IcalFeed::factory()->create();

        Livewire::test(ManageIcalFeeds::class)
            ->assertTableColumnFormattedStateSet('last_status', 'OK', $synced)
            ->assertTableColumnFormattedStateSet('last_status', 'The calendar link returned HTTP 404.', $failing)
            ->assertSee('Not synced yet')
            ->filterTable('failing', true)
            ->assertCanSeeTableRecords([$failing])
            ->assertCanNotSeeTableRecords([$synced, $new]);
    }

    public function test_sync_now_reports_why_the_sync_failed(): void
    {
        $feed = IcalFeed::factory()->create();

        Livewire::test(ManageIcalFeeds::class)
            ->callAction(TestAction::make('sync')->table($feed))
            ->assertNotified('Sync failed');

        $this->assertNull($feed->fresh()->last_synced_at);
    }

    public function test_sync_now_is_offered_for_import_feeds_only(): void
    {
        $import = IcalFeed::factory()->create();
        $export = IcalFeed::factory()->export()->create();

        Livewire::test(ManageIcalFeeds::class)
            ->assertActionVisible(TestAction::make('sync')->table($import))
            ->assertActionHidden(TestAction::make('sync')->table($export));
    }

    public function test_deleting_an_import_feed_opens_the_dates_it_blocked(): void
    {
        $feed = IcalFeed::factory()->create();
        $block = AvailabilityBlock::factory()->fromIcal($feed)->create();

        Livewire::test(ManageIcalFeeds::class)
            ->callAction(TestAction::make('delete')->table($feed));

        $this->assertModelMissing($feed);
        $this->assertModelMissing($block);
    }
}

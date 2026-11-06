<?php

namespace Tests\Feature\Actions;

use App\Actions\Ical\SyncIcalFeed;
use App\Exceptions\Ical\IcalSyncException;
use App\Models\IcalFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncIcalFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_an_export_feed(): void
    {
        $feed = IcalFeed::factory()->export()->create();

        $this->expectExceptionObject(IcalSyncException::notAnImportFeed());

        $this->app->make(SyncIcalFeed::class)->handle($feed);
    }

    /**
     * Until the import is built (T8.1), a sync fails without touching the feed.
     */
    public function test_import_feed_is_left_untouched_until_sync_is_set_up(): void
    {
        $feed = IcalFeed::factory()->create(['last_synced_at' => null, 'last_status' => null]);

        try {
            $this->app->make(SyncIcalFeed::class)->handle($feed);
            $this->fail('The feed was synced.');
        } catch (IcalSyncException $exception) {
            $this->assertSame('Syncing OTA calendars is not set up yet.', $exception->getMessage());
        }

        $feed->refresh();
        $this->assertNull($feed->last_synced_at);
        $this->assertNull($feed->last_status);
    }
}

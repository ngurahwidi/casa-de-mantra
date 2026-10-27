<?php

namespace Tests\Feature\Models;

use App\Enums\AvailabilityBlockSource;
use App\Enums\IcalChannel;
use App\Enums\IcalDirection;
use App\Models\AvailabilityBlock;
use App\Models\IcalFeed;
use App\Models\RoomType;
use App\Models\Unit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_keys_are_uuids(): void
    {
        $this->assertTrue(Str::isUuid(IcalFeed::factory()->create()->id));
        $this->assertTrue(Str::isUuid(AvailabilityBlock::factory()->create()->id));
    }

    public function test_ical_feed_casts_and_relations(): void
    {
        $roomType = RoomType::factory()->create();
        $feed = IcalFeed::factory()->for($roomType)->create([
            'channel' => IcalChannel::Expedia,
            'last_synced_at' => '2026-10-26 08:00:00',
            'last_status' => 'ok',
        ])->fresh();

        $this->assertSame(IcalChannel::Expedia, $feed->channel);
        $this->assertSame(IcalDirection::Import, $feed->direction);
        $this->assertInstanceOf(Carbon::class, $feed->last_synced_at);
        $this->assertTrue($feed->roomType->is($roomType));
        $this->assertTrue($roomType->icalFeeds->first()->is($feed));
    }

    public function test_ical_feed_direction_scopes(): void
    {
        $import = IcalFeed::factory()->create();
        $export = IcalFeed::factory()->export()->create();

        $this->assertEquals([$import->id], IcalFeed::imports()->pluck('id')->all());
        $this->assertEquals([$export->id], IcalFeed::exports()->pluck('id')->all());
    }

    public function test_ical_feed_constraints_are_enforced_by_the_database(): void
    {
        $this->assertThrowsQuery(fn () => IcalFeed::factory()->create(['url' => null]));
        $this->assertThrowsQuery(fn () => IcalFeed::factory()->export()->create(['export_token' => null]));

        $feed = IcalFeed::factory()->create();
        $this->assertThrowsQuery(fn () => DB::table('ical_feeds')->where('id', $feed->id)->update(['channel' => 'agoda']));
        $this->assertThrowsQuery(fn () => DB::table('ical_feeds')->where('id', $feed->id)->update(['direction' => 'both']));
    }

    public function test_export_token_is_unique_and_case_sensitive(): void
    {
        IcalFeed::factory()->export()->create(['export_token' => 'AbCdEf']);

        $other = IcalFeed::factory()->export()->create(['export_token' => 'abcdef']);

        $this->assertSame('abcdef', $other->fresh()->export_token);
        $this->assertThrowsQuery(fn () => IcalFeed::factory()->export()->create(['export_token' => 'AbCdEf']));
    }

    public function test_room_type_in_use_by_a_feed_is_deleted_together_with_it(): void
    {
        $feed = IcalFeed::factory()->create();

        $feed->roomType->delete();

        $this->assertSame(0, IcalFeed::count());
    }

    public function test_availability_block_defaults_casts_and_relations(): void
    {
        $unit = Unit::factory()->create();
        $block = AvailabilityBlock::create([
            'unit_id' => $unit->id,
            'start_date' => '2026-12-24',
            'end_date' => '2026-12-27',
        ])->fresh();

        $this->assertSame(AvailabilityBlockSource::Manual, $block->source);
        $this->assertEquals(Carbon::parse('2026-12-24'), $block->start_date);
        $this->assertEquals(Carbon::parse('2026-12-27'), $block->end_date);
        $this->assertNull($block->ical_feed_id);
        $this->assertNull($block->reason);
        $this->assertNull($block->external_uid);
        $this->assertTrue($block->unit->is($unit));
        $this->assertTrue($unit->availabilityBlocks->first()->is($block));
    }

    public function test_ical_blocks_belong_to_a_feed_and_are_deleted_with_it(): void
    {
        $feed = IcalFeed::factory()->create();
        $unit = Unit::factory()->for($feed->roomType)->create();
        AvailabilityBlock::factory()->fromIcal($feed)->for($unit)->count(2)->create();
        $manual = AvailabilityBlock::factory()->for($unit)->create(['reason' => 'Maintenance']);

        $this->assertCount(2, $feed->availabilityBlocks);
        $this->assertSame(AvailabilityBlockSource::Ical, $feed->availabilityBlocks->first()->source);

        $feed->delete();

        $this->assertEquals([$manual->id], AvailabilityBlock::pluck('id')->all());
    }

    public function test_blocks_are_deleted_with_their_unit(): void
    {
        $block = AvailabilityBlock::factory()->create();

        $block->unit->delete();

        $this->assertSame(0, AvailabilityBlock::count());
    }

    public function test_availability_block_constraints_are_enforced_by_the_database(): void
    {
        $this->assertThrowsQuery(fn () => AvailabilityBlock::factory()->create([
            'start_date' => '2026-12-24',
            'end_date' => '2026-12-24',
        ]));
        $this->assertThrowsQuery(fn () => AvailabilityBlock::factory()->create([
            'start_date' => '2026-12-27',
            'end_date' => '2026-12-24',
        ]));

        $block = AvailabilityBlock::factory()->create([
            'start_date' => '2026-12-24',
            'end_date' => '2026-12-25',
        ]);

        $this->assertEquals(Carbon::parse('2026-12-25'), $block->fresh()->end_date);
        $this->assertThrowsQuery(fn () => DB::table('availability_blocks')->where('id', $block->id)->update(['source' => 'channel']));
    }

    public function test_availability_blocks_have_a_composite_index_for_overlap_lookups(): void
    {
        $columns = collect(DB::select('SHOW INDEX FROM availability_blocks'))
            ->groupBy('Key_name')
            ->map(fn ($rows) => $rows->sortBy('Seq_in_index')->pluck('Column_name')->all());

        $this->assertContains(['unit_id', 'start_date', 'end_date'], $columns->values()->all());
    }

    private function assertThrowsQuery(callable $callback): void
    {
        try {
            $callback();
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected the database to reject the statement.');
    }
}

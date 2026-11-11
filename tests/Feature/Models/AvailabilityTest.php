<?php

namespace Tests\Feature\Models;

use App\Enums\AvailabilityBlockSource;
use App\Models\AvailabilityBlock;
use App\Models\Unit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_keys_are_uuids(): void
    {
        $this->assertTrue(Str::isUuid(AvailabilityBlock::factory()->create()->id));
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
        $this->assertNull($block->reason);
        $this->assertTrue($block->unit->is($unit));
        $this->assertTrue($unit->availabilityBlocks->first()->is($block));
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
        $this->assertThrowsQuery(fn () => DB::table('availability_blocks')->where('id', $block->id)->update(['source' => 'ical']));
    }

    public function test_ical_sync_is_gone_from_the_schema(): void
    {
        $this->assertFalse(Schema::hasTable('ical_feeds'));
        $this->assertFalse(Schema::hasColumn('availability_blocks', 'ical_feed_id'));
        $this->assertFalse(Schema::hasColumn('availability_blocks', 'external_uid'));
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

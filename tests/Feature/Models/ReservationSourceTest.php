<?php

namespace Tests\Feature\Models;

use App\Enums\ReservationSourceCategory;
use App\Models\Reservation;
use App\Models\ReservationSource;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReservationSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_provides_the_former_sources_with_their_codes(): void
    {
        $sources = ReservationSource::query()
            ->orderBy('code')
            ->get()
            ->map(fn (ReservationSource $source): array => [$source->code, $source->name, $source->category, $source->is_active])
            ->all();

        $this->assertSame([
            ['booking_com', 'Booking.com', ReservationSourceCategory::Ota, true],
            ['direct_web', 'Website', ReservationSourceCategory::Website, true],
            ['expedia', 'Expedia', ReservationSourceCategory::Ota, true],
            ['manual', 'Phone', ReservationSourceCategory::Direct, true],
        ], $sources);
        $this->assertSame('direct_web', ReservationSource::website()->code);
    }

    public function test_code_is_made_from_the_name_and_kept_when_the_source_is_renamed(): void
    {
        $source = ReservationSource::factory()->create(['name' => 'Agoda Partner Hub']);

        $source->update(['name' => 'Agoda']);

        $this->assertSame('agoda_partner_hub', $source->fresh()->code);
    }

    public function test_code_gets_a_number_when_another_source_has_it(): void
    {
        // "booking_com" belongs to Booking.com.
        $first = ReservationSource::factory()->create(['name' => 'Booking com']);
        $second = ReservationSource::factory()->create(['name' => 'Booking-com']);

        $this->assertSame('booking_com_2', $first->code);
        $this->assertSame('booking_com_3', $second->code);
    }

    public function test_name_is_unique_regardless_of_case(): void
    {
        ReservationSource::factory()->create(['name' => 'Agoda']);

        $this->expectException(QueryException::class);

        ReservationSource::factory()->create(['name' => 'agoda']);
    }

    public function test_category_is_enforced_by_the_database(): void
    {
        $source = ReservationSource::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('reservation_sources')->where('id', $source->id)->update(['category' => 'pms']);
    }

    public function test_source_with_reservations_cannot_be_deleted(): void
    {
        $used = ReservationSource::factory()->create();
        $unused = ReservationSource::factory()->create();
        $reservation = Reservation::factory()->create(['reservation_source_id' => $used]);

        $this->assertFalse($used->canBeDeleted());
        $this->assertTrue($unused->canBeDeleted());
        $this->assertTrue($reservation->source->is($used));

        $this->expectException(QueryException::class);

        $used->delete();
    }

    public function test_website_source_cannot_be_deleted_even_without_reservations(): void
    {
        $this->assertFalse(ReservationSource::website()->canBeDeleted());
    }

    public function test_manual_entry_offers_active_sources_other_than_the_website(): void
    {
        $agoda = ReservationSource::factory()->create(['name' => 'Agoda']);
        $walkIn = ReservationSource::factory()->direct()->create(['name' => 'Walk-in']);
        ReservationSource::factory()->inactive()->create(['name' => 'Old OTA']);

        $offered = ReservationSource::query()->forManualEntry()->orderBy('name')->pluck('name')->all();

        $this->assertSame(['Agoda', 'Booking.com', 'Expedia', 'Phone', 'Walk-in'], $offered);
        $this->assertTrue($agoda->isOta());
        $this->assertFalse($walkIn->isOta());
    }
}

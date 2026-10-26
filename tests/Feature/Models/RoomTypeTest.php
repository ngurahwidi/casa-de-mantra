<?php

namespace Tests\Feature\Models;

use App\Enums\UnitStatus;
use App\Models\Amenity;
use App\Models\RoomType;
use App\Models\Unit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class RoomTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_keys_are_uuids(): void
    {
        $roomType = RoomType::factory()->create();
        $unit = Unit::factory()->for($roomType)->create();
        $amenity = Amenity::factory()->create();

        foreach ([$roomType, $unit, $amenity] as $model) {
            $this->assertTrue(Str::isUuid($model->id));
        }
    }

    public function test_defaults_and_casts(): void
    {
        $roomType = RoomType::create([
            'name' => 'Garden Villa',
            'slug' => 'garden-villa',
            'max_adults' => 2,
        ])->fresh();

        $this->assertFalse($roomType->is_published);
        $this->assertSame(0, $roomType->max_children);
        $this->assertNull($roomType->size_m2);
    }

    public function test_slug_is_unique(): void
    {
        RoomType::factory()->create(['slug' => 'garden-villa']);

        $this->expectException(QueryException::class);

        RoomType::factory()->create(['slug' => 'garden-villa']);
    }

    public function test_max_adults_must_be_at_least_one(): void
    {
        $this->expectException(QueryException::class);

        RoomType::factory()->create(['max_adults' => 0]);
    }

    public function test_unit_status_is_cast_to_enum_and_defaults_to_active(): void
    {
        $roomType = RoomType::factory()->create();

        $unit = Unit::create(['room_type_id' => $roomType->id, 'code' => 'V1'])->fresh();

        $this->assertSame(UnitStatus::Active, $unit->status);
        $this->assertSame(UnitStatus::Inactive, Unit::factory()->inactive()->create()->status);
    }

    public function test_unknown_unit_status_is_rejected_by_the_database(): void
    {
        $unit = Unit::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('units')->where('id', $unit->id)->update(['status' => 'retired']);
    }

    public function test_unit_code_is_unique_per_room_type_only(): void
    {
        $roomType = RoomType::factory()->create();
        Unit::factory()->for($roomType)->create(['code' => 'V1']);

        Unit::factory()->create(['code' => 'V1']);

        $this->expectException(QueryException::class);

        Unit::factory()->for($roomType)->create(['code' => 'V1']);
    }

    public function test_active_scope_excludes_inactive_units(): void
    {
        $roomType = RoomType::factory()->create();
        $active = Unit::factory()->for($roomType)->create();
        Unit::factory()->for($roomType)->inactive()->create();

        $this->assertEquals([$active->id], $roomType->units()->active()->pluck('id')->all());
    }

    public function test_room_type_with_units_cannot_be_deleted(): void
    {
        $roomType = RoomType::factory()->create();
        Unit::factory()->for($roomType)->create();

        $this->expectException(QueryException::class);

        $roomType->delete();
    }

    public function test_amenities_are_attached_through_the_junction_table(): void
    {
        $roomType = RoomType::factory()->create();
        $pool = Amenity::factory()->create(['name' => 'Private pool']);
        $wifi = Amenity::factory()->create(['name' => 'Wi-Fi']);

        $roomType->amenities()->attach([$pool->id, $wifi->id]);

        $this->assertCount(2, $roomType->fresh()->amenities);
        $this->assertTrue($pool->roomTypes->first()->is($roomType));
    }

    public function test_the_same_amenity_cannot_be_attached_twice(): void
    {
        $roomType = RoomType::factory()->create();
        $amenity = Amenity::factory()->create();
        $roomType->amenities()->attach($amenity);

        $this->expectException(QueryException::class);

        $roomType->amenities()->attach($amenity);
    }

    public function test_deleting_an_amenity_detaches_it(): void
    {
        $roomType = RoomType::factory()->create();
        $amenity = Amenity::factory()->create();
        $roomType->amenities()->attach($amenity);

        $amenity->delete();

        $this->assertCount(0, $roomType->fresh()->amenities);
        $this->assertSame(0, DB::table('room_type_amenity')->count());
    }

    public function test_amenity_name_is_unique(): void
    {
        Amenity::factory()->create(['name' => 'Wi-Fi']);

        $this->expectException(QueryException::class);

        Amenity::factory()->create(['name' => 'Wi-Fi']);
    }

    public function test_photos_are_stored_in_the_gallery_with_alt_text_and_order(): void
    {
        Storage::fake('public');
        $roomType = RoomType::factory()->create();

        $second = $this->addPhoto($roomType, 'second.jpg', 'Pool at dusk');
        $first = $this->addPhoto($roomType, 'first.jpg');
        $first->order_column = 0;
        $first->save();

        $gallery = $roomType->fresh()->getMedia(RoomType::GALLERY);

        $this->assertEquals([$first->id, $second->id], $gallery->pluck('id')->all());
        $this->assertSame('Pool at dusk', $second->getCustomProperty('alt'));
        $this->assertTrue($second->model->is($roomType));
        Storage::disk('public')->assertExists($second->getPathRelativeToRoot());
    }

    public function test_deleting_a_room_type_deletes_its_media(): void
    {
        Storage::fake('public');
        $roomType = RoomType::factory()->create();
        $photo = $this->addPhoto($roomType, 'a.jpg');
        $other = $this->addPhoto(RoomType::factory()->create(), 'b.jpg');

        $roomType->delete();

        $this->assertSame(1, Media::count());
        $this->assertTrue(Media::first()->is($other));
        Storage::disk('public')->assertMissing($photo->getPathRelativeToRoot());
    }

    private function addPhoto(RoomType $roomType, string $name, ?string $alt = null): Media
    {
        return $roomType
            ->addMedia(UploadedFile::fake()->image($name))
            ->withCustomProperties(['alt' => $alt])
            ->toMediaCollection(RoomType::GALLERY, 'public');
    }
}

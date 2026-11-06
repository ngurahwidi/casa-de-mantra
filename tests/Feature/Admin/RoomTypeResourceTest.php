<?php

namespace Tests\Feature\Admin;

use App\Enums\UnitStatus;
use App\Filament\Resources\RoomTypes\Pages\CreateRoomType;
use App\Filament\Resources\RoomTypes\Pages\EditRoomType;
use App\Filament\Resources\RoomTypes\Pages\ListRoomTypes;
use App\Filament\Resources\RoomTypes\RelationManagers\AmenitiesRelationManager;
use App\Filament\Resources\RoomTypes\RelationManagers\MediaRelationManager;
use App\Filament\Resources\RoomTypes\RelationManagers\UnitsRelationManager;
use App\Models\Amenity;
use App\Models\RatePlan;
use App\Models\ReservationRoom;
use App\Models\RoomType;
use App\Models\Unit;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class RoomTypeResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->owner()->create());
    }

    public function test_list_shows_room_types(): void
    {
        $roomTypes = RoomType::factory()->count(2)->create();

        Livewire::test(ListRoomTypes::class)->assertCanSeeTableRecords($roomTypes);
    }

    public function test_room_type_is_created_with_a_slug_from_its_name(): void
    {
        Livewire::test(CreateRoomType::class)
            ->fillForm(['name' => 'Garden Pool Villa'])
            ->assertSchemaStateSet(['slug' => 'garden-pool-villa'])
            ->fillForm(['max_adults' => 4, 'max_children' => 2, 'bedrooms' => 2, 'bathrooms' => 2, 'size_m2' => 180])
            ->call('create')
            ->assertHasNoFormErrors();

        $roomType = RoomType::where('slug', 'garden-pool-villa')->firstOrFail();

        $this->assertSame('Garden Pool Villa', $roomType->name);
        $this->assertSame(4, $roomType->max_adults);
        $this->assertFalse($roomType->is_published);
    }

    public function test_slug_must_be_unique_and_url_safe(): void
    {
        RoomType::factory()->create(['slug' => 'garden-villa']);

        Livewire::test(CreateRoomType::class)
            ->fillForm(['name' => 'Garden Villa', 'slug' => 'garden-villa', 'max_adults' => 2])
            ->call('create')
            ->assertHasFormErrors(['slug' => 'unique']);

        Livewire::test(CreateRoomType::class)
            ->fillForm(['name' => 'Garden Villa', 'slug' => 'garden villa!', 'max_adults' => 2])
            ->call('create')
            ->assertHasFormErrors(['slug' => 'alpha_dash']);
    }

    public function test_capacity_needs_at_least_one_adult(): void
    {
        Livewire::test(CreateRoomType::class)
            ->fillForm(['name' => 'Garden Villa', 'max_adults' => 0])
            ->call('create')
            ->assertHasFormErrors(['max_adults' => 'min']);
    }

    public function test_editing_does_not_change_the_slug(): void
    {
        $roomType = RoomType::factory()->create(['name' => 'Garden Villa', 'slug' => 'garden-villa']);

        Livewire::test(EditRoomType::class, ['record' => $roomType->getRouteKey()])
            ->fillForm(['name' => 'Garden Pool Villa'])
            ->assertSchemaStateSet(['slug' => 'garden-villa'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('garden-villa', $roomType->fresh()->slug);
    }

    public function test_room_type_without_dependents_can_be_deleted(): void
    {
        $roomType = RoomType::factory()->create();

        Livewire::test(EditRoomType::class, ['record' => $roomType->getRouteKey()])
            ->callAction('delete');

        $this->assertModelMissing($roomType);
    }

    public function test_delete_is_hidden_for_a_room_type_with_units_or_prices(): void
    {
        $withUnit = RoomType::factory()->create();
        Unit::factory()->for($withUnit)->create();

        $withRatePlan = RoomType::factory()->create();
        RatePlan::factory()->for($withRatePlan)->create();

        foreach ([$withUnit, $withRatePlan] as $roomType) {
            Livewire::test(EditRoomType::class, ['record' => $roomType->getRouteKey()])
                ->assertActionHidden('delete');
        }
    }

    public function test_units_are_managed_from_the_room_type(): void
    {
        $roomType = RoomType::factory()->create();

        $this->unitsManager($roomType)
            ->callAction(TestAction::make('create')->table(), ['code' => 'V1', 'status' => UnitStatus::Active->value])
            ->assertHasNoFormErrors();

        $unit = $roomType->units()->sole();
        $this->assertSame('V1', $unit->code);
        $this->assertSame(UnitStatus::Active, $unit->status);

        $this->unitsManager($roomType)
            ->callAction(TestAction::make('edit')->table($unit), ['status' => UnitStatus::Inactive->value])
            ->assertHasNoFormErrors();

        $this->assertSame(UnitStatus::Inactive, $unit->fresh()->status);
    }

    public function test_unit_code_is_unique_within_the_room_type_only(): void
    {
        $roomType = RoomType::factory()->create();
        Unit::factory()->for($roomType)->create(['code' => 'V1']);
        $other = RoomType::factory()->create();

        $this->unitsManager($roomType)
            ->callAction(TestAction::make('create')->table(), ['code' => 'V1', 'status' => UnitStatus::Active->value])
            ->assertHasFormErrors(['code' => 'unique']);

        $this->unitsManager($other)
            ->callAction(TestAction::make('create')->table(), ['code' => 'V1', 'status' => UnitStatus::Active->value])
            ->assertHasNoFormErrors();

        $this->assertSame(1, $other->units()->count());
    }

    public function test_unit_with_reservations_cannot_be_deleted(): void
    {
        $roomType = RoomType::factory()->create();
        $booked = Unit::factory()->for($roomType)->create(['code' => 'V1']);
        $free = Unit::factory()->for($roomType)->create(['code' => 'V2']);
        ReservationRoom::factory()->create(['room_type_id' => $roomType->id, 'unit_id' => $booked->id]);

        $this->unitsManager($roomType)
            ->assertActionHidden(TestAction::make('delete')->table($booked))
            ->callAction(TestAction::make('delete')->table($free));

        $this->assertModelExists($booked);
        $this->assertModelMissing($free);
    }

    public function test_amenities_are_attached_and_detached(): void
    {
        $roomType = RoomType::factory()->create();
        $pool = Amenity::factory()->create(['name' => 'Private pool']);
        $wifi = Amenity::factory()->create(['name' => 'Wi-Fi']);

        $manager = Livewire::test(AmenitiesRelationManager::class, [
            'ownerRecord' => $roomType,
            'pageClass' => EditRoomType::class,
        ]);

        $manager->callAction(TestAction::make('attach')->table(), ['recordId' => [$pool->id, $wifi->id]])
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing([$pool->id, $wifi->id], $roomType->amenities()->pluck('amenities.id')->all());

        $manager->callAction(TestAction::make('detach')->table($pool));

        $this->assertEquals([$wifi->id], $roomType->amenities()->pluck('amenities.id')->all());
        $this->assertModelExists($pool);
    }

    public function test_photos_are_uploaded_described_and_deleted(): void
    {
        Storage::fake('public');
        $roomType = RoomType::factory()->create();

        $manager = Livewire::test(MediaRelationManager::class, [
            'ownerRecord' => $roomType,
            'pageClass' => EditRoomType::class,
        ]);

        $manager->callAction(TestAction::make('upload')->table(), [
            'photos' => [UploadedFile::fake()->image('pool.jpg'), UploadedFile::fake()->image('bedroom.jpg')],
        ])->assertHasNoFormErrors();

        $photos = $roomType->fresh()->getMedia(RoomType::GALLERY);
        $this->assertSame(['pool', 'bedroom'], $photos->pluck('name')->all());

        /** @var Media $pool */
        $pool = $photos->first();
        $manager->assertCanSeeTableRecords($photos)
            ->callAction(TestAction::make('edit')->table($pool), ['custom_properties.alt' => 'Pool at dusk'])
            ->assertHasNoFormErrors();

        $this->assertSame('Pool at dusk', $pool->fresh()->getCustomProperty('alt'));

        $manager->callAction(TestAction::make('delete')->table($pool));

        $this->assertModelMissing($pool);
        Storage::disk('public')->assertMissing($pool->getPathRelativeToRoot());
    }

    public function test_photo_upload_rejects_non_images(): void
    {
        Storage::fake('public');
        $roomType = RoomType::factory()->create();

        Livewire::test(MediaRelationManager::class, ['ownerRecord' => $roomType, 'pageClass' => EditRoomType::class])
            ->callAction(TestAction::make('upload')->table(), [
                'photos' => [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')],
            ])
            ->assertHasFormErrors(['photos']);

        $this->assertSame(0, Media::count());
    }

    private function unitsManager(RoomType $roomType): mixed
    {
        return Livewire::test(UnitsRelationManager::class, [
            'ownerRecord' => $roomType,
            'pageClass' => EditRoomType::class,
        ]);
    }
}

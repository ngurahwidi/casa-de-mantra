<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Amenities\Pages\ManageAmenities;
use App\Filament\Resources\CancellationPolicies\Pages\ManageCancellationPolicies;
use App\Filament\Resources\IcalFeeds\Pages\ManageIcalFeeds;
use App\Filament\Resources\NotificationTemplates\Pages\ManageNotificationTemplates;
use App\Filament\Resources\PropertyProfiles\PropertyProfileResource;
use App\Filament\Resources\RoomTypes\Pages\ListRoomTypes;
use App\Filament\Resources\RoomTypes\Pages\ViewRoomType;
use App\Filament\Resources\RoomTypes\RelationManagers\AmenitiesRelationManager;
use App\Filament\Resources\RoomTypes\RelationManagers\MediaRelationManager;
use App\Filament\Resources\RoomTypes\RelationManagers\UnitsRelationManager;
use App\Filament\Resources\RoomTypes\RoomTypeResource;
use App\Filament\Resources\TaxFees\Pages\ManageTaxFees;
use App\Filament\Resources\Users\UserResource;
use App\Models\Amenity;
use App\Models\CancellationPolicy;
use App\Models\IcalFeed;
use App\Models\NotificationTemplate;
use App\Models\PropertyProfile;
use App\Models\RoomType;
use App\Models\TaxFee;
use App\Models\Unit;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Staff run the daily work but only look at settings, room types and amenities, and never see
 * admin accounts. The full permission matrix is in the policy tests; these show the panel applies it.
 */
class StaffAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_staff_cannot_open_admin_accounts(): void
    {
        $this->get('/admin/users')->assertForbidden();

        $this->get('/admin')->assertOk();
        $labels = collect(Filament::getNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->map(fn ($item) => $item->getLabel())
            ->all();

        $this->assertNotContains(UserResource::getNavigationLabel(), $labels);
    }

    public function test_staff_read_the_property_profile_but_cannot_edit_it(): void
    {
        PropertyProfile::factory()->create(['name' => 'Casa Demo']);

        $this->assertSame(url('/admin/property-profile/view'), PropertyProfileResource::getNavigationUrl());

        $this->get('/admin/property-profile')->assertForbidden();
        $this->get('/admin/property-profile/view')->assertOk()->assertSee('Casa Demo');
    }

    public function test_staff_see_ical_feeds_without_changing_or_syncing_them(): void
    {
        $feed = IcalFeed::factory()->create(['last_status' => 'The calendar link returned HTTP 404.']);

        Livewire::test(ManageIcalFeeds::class)
            ->assertCanSeeTableRecords([$feed])
            ->assertSee('The calendar link returned HTTP 404.')
            ->assertActionHidden('create')
            ->assertActionHidden(TestAction::make('sync')->table($feed))
            ->assertActionHidden(TestAction::make('edit')->table($feed))
            ->assertActionHidden(TestAction::make('delete')->table($feed));
    }

    public function test_staff_read_templates_and_policies_without_editing_them(): void
    {
        $template = NotificationTemplate::factory()->create();
        $policy = CancellationPolicy::factory()->create();

        Livewire::test(ManageNotificationTemplates::class)
            ->assertActionVisible(TestAction::make('view')->table($template))
            ->assertActionHidden(TestAction::make('edit')->table($template));

        Livewire::test(ManageCancellationPolicies::class)
            ->assertActionVisible(TestAction::make('view')->table($policy))
            ->assertActionHidden(TestAction::make('edit')->table($policy));
    }

    public function test_staff_cannot_add_or_change_taxes_or_amenities(): void
    {
        $fee = TaxFee::factory()->create();
        $amenity = Amenity::factory()->create();

        Livewire::test(ManageTaxFees::class)
            ->assertActionHidden('create')
            ->assertActionHidden(TestAction::make('edit')->table($fee));

        Livewire::test(ManageAmenities::class)
            ->assertActionHidden('create')
            ->assertActionHidden(TestAction::make('delete')->table($amenity));
    }

    public function test_staff_view_room_types_read_only_and_still_manage_prices(): void
    {
        $roomType = RoomType::factory()->create();

        Livewire::test(ListRoomTypes::class)
            ->assertActionHidden('create')
            ->assertActionHidden(TestAction::make('edit')->table($roomType))
            ->assertActionVisible(TestAction::make('view')->table($roomType))
            ->assertActionVisible(TestAction::make('pricing')->table($roomType));

        $this->get(RoomTypeResource::getUrl('edit', ['record' => $roomType]))->assertForbidden();
        $this->get(RoomTypeResource::getUrl('view', ['record' => $roomType]))->assertOk();

        PropertyProfile::factory()->create();
        $this->get(RoomTypeResource::getUrl('pricing', ['record' => $roomType]))->assertOk();
    }

    public function test_room_type_relations_are_read_only_on_the_view_page(): void
    {
        $roomType = RoomType::factory()->create();
        $unit = Unit::factory()->for($roomType)->create();
        $amenity = Amenity::factory()->create();
        $roomType->amenities()->attach($amenity);
        $page = ['ownerRecord' => $roomType, 'pageClass' => ViewRoomType::class];

        Livewire::test(UnitsRelationManager::class, $page)
            ->assertActionHidden(TestAction::make('create')->table())
            ->assertActionHidden(TestAction::make('edit')->table($unit))
            ->assertActionVisible(TestAction::make('calendar')->table($unit));

        Livewire::test(AmenitiesRelationManager::class, $page)
            ->assertActionHidden(TestAction::make('attach')->table())
            ->assertActionHidden(TestAction::make('detach')->table($amenity));

        Livewire::test(MediaRelationManager::class, $page)
            ->assertActionHidden(TestAction::make('upload')->table());
    }
}

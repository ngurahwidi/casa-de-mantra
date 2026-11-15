<?php

namespace Tests\Feature\Admin;

use App\Enums\ReservationSourceCategory;
use App\Filament\Resources\ReservationSources\Pages\ManageReservationSources;
use App\Models\Reservation;
use App\Models\ReservationSource;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Staff maintain the reservation sources; the permission matrix is in ReservationSourcePolicyTest.
 */
class ReservationSourceResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_open_the_sources_from_the_admin_menu(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/reservation-sources')
            ->assertOk()
            ->assertSee('Booking.com')
            ->assertSee('Website');
    }

    public function test_staff_add_a_source(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ManageReservationSources::class)
            ->callAction('create', ['name' => 'WhatsApp', 'category' => ReservationSourceCategory::Direct->value, 'is_active' => true])
            ->assertHasNoFormErrors();

        $source = ReservationSource::query()->where('name', 'WhatsApp')->sole();
        $this->assertSame(ReservationSourceCategory::Direct, $source->category);
        $this->assertSame('whatsapp', $source->code);
        $this->assertTrue($source->is_active);
    }

    public function test_a_source_cannot_be_given_the_website_category(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ManageReservationSources::class)
            ->callAction('create', ['name' => 'Second website', 'category' => ReservationSourceCategory::Website->value])
            ->assertHasFormErrors(['category' => 'in']);

        $this->assertFalse(ReservationSource::query()->where('name', 'Second website')->exists());
    }

    public function test_a_source_name_is_used_once(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ManageReservationSources::class)
            ->callAction('create', ['name' => 'booking.com', 'category' => ReservationSourceCategory::Ota->value])
            ->assertHasFormErrors(['name' => 'unique']);
    }

    public function test_staff_deactivate_and_activate_a_source(): void
    {
        $this->actingAs(User::factory()->create());
        $source = ReservationSource::factory()->create();

        Livewire::test(ManageReservationSources::class)
            ->callAction(TestAction::make('deactivate')->table($source));

        $this->assertFalse($source->fresh()->is_active);

        Livewire::test(ManageReservationSources::class)
            ->assertActionHidden(TestAction::make('deactivate')->table($source))
            ->callAction(TestAction::make('activate')->table($source));

        $this->assertTrue($source->fresh()->is_active);
    }

    public function test_a_source_with_reservations_cannot_be_deleted(): void
    {
        $this->actingAs(User::factory()->create());
        $used = ReservationSource::factory()->create();
        $unused = ReservationSource::factory()->create();
        Reservation::factory()->create(['reservation_source_id' => $used]);

        Livewire::test(ManageReservationSources::class)
            ->assertActionHidden(TestAction::make('delete')->table($used))
            ->assertActionVisible(TestAction::make('deactivate')->table($used))
            ->callAction(TestAction::make('delete')->table($unused));

        $this->assertModelExists($used);
        $this->assertModelMissing($unused);
    }

    public function test_the_website_source_cannot_be_edited_deactivated_or_deleted(): void
    {
        $this->actingAs(User::factory()->owner()->create());
        $website = ReservationSource::website();

        Livewire::test(ManageReservationSources::class)
            ->assertActionHidden(TestAction::make('edit')->table($website))
            ->assertActionHidden(TestAction::make('deactivate')->table($website))
            ->assertActionHidden(TestAction::make('delete')->table($website));
    }
}

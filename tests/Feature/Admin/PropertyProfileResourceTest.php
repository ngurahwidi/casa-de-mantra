<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\PropertyProfiles\Pages\EditPropertyProfile;
use App\Filament\Resources\PropertyProfiles\PropertyProfileResource;
use App\Models\PropertyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PropertyProfileResourceTest extends TestCase
{
    use RefreshDatabase;

    private PropertyProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->profile = PropertyProfile::factory()->create([
            'name' => 'Casa Demo',
            'bank_account_info' => ['bank' => 'Bank Example', 'account_name' => 'Casa Demo', 'account_number' => '123'],
        ]);

        $this->actingAs(User::factory()->owner()->create());
    }

    public function test_navigation_opens_the_single_profile_for_editing_by_the_owner(): void
    {
        $this->assertSame(url('/admin/property-profile'), PropertyProfileResource::getNavigationUrl());

        $this->get('/admin/property-profile')->assertOk()->assertSee('Casa Demo');
    }

    public function test_profile_cannot_be_created_or_deleted(): void
    {
        $this->assertFalse(PropertyProfileResource::hasPage('create'));
        $this->assertFalse(PropertyProfileResource::canCreate());
        $this->assertFalse(PropertyProfileResource::canDelete($this->profile));

        Livewire::test(EditPropertyProfile::class)->assertActionDoesNotExist('delete');
    }

    public function test_form_is_filled_from_the_profile(): void
    {
        Livewire::test(EditPropertyProfile::class)
            ->assertSchemaStateSet([
                'name' => 'Casa Demo',
                'timezone' => 'Asia/Makassar',
                'currency' => 'IDR',
                'bank_account_info.bank' => 'Bank Example',
                'bank_account_info.account_number' => '123',
            ]);
    }

    public function test_saving_updates_the_profile_and_refreshes_the_cache(): void
    {
        $this->assertSame('Casa Demo', PropertyProfile::current()->name);

        Livewire::test(EditPropertyProfile::class)
            ->fillForm([
                'name' => 'Casa de Mantra',
                'currency' => 'usd',
                'checkin_time' => '15:00',
                'confirm_on_deposit' => true,
                'bank_account_info.account_number' => '987654321',
                'bank_account_info.swift_code' => 'BEXAIDJA',
                'seo_settings.meta_title' => 'Casa de Mantra, Bali',
                'theme.primary' => '#0f766e',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $current = PropertyProfile::current();

        $this->assertSame('Casa de Mantra', $current->name);
        $this->assertSame('USD', $current->currency);
        $this->assertSame('15:00:00', $current->checkin_time);
        $this->assertTrue($current->confirm_on_deposit);
        $this->assertSame('Bank Example', $current->bank_account_info['bank']);
        $this->assertSame('987654321', $current->bank_account_info['account_number']);
        $this->assertSame('BEXAIDJA', $current->bank_account_info['swift_code']);
        $this->assertSame('Casa de Mantra, Bali', $current->seo_settings['meta_title']);
        $this->assertSame('#0f766e', $current->theme['primary']);
    }

    public function test_invalid_values_are_rejected(): void
    {
        Livewire::test(EditPropertyProfile::class)
            ->fillForm([
                'name' => '',
                'timezone' => 'Mars/Olympus',
                'currency' => 'RUPIAH',
                'payment_window_hours' => 0,
                'lat' => 91,
            ])
            ->call('save')
            ->assertHasFormErrors([
                'name' => 'required',
                'timezone',
                'currency',
                'payment_window_hours' => 'min',
                'lat' => 'max',
            ]);

        $this->assertSame('Casa Demo', $this->profile->fresh()->name);
    }
}

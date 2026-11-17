<?php

namespace Tests\Feature\Models;

use App\Enums\PropertyType;
use App\Models\PropertyProfile;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PropertyProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_returns_the_single_profile(): void
    {
        $profile = PropertyProfile::factory()->create(['name' => 'Casa de Mantra']);

        $current = PropertyProfile::current();

        $this->assertTrue($profile->is($current));
        $this->assertSame('Casa de Mantra', $current->name);
        $this->assertSame(PropertyType::Villa, $current->type);
        $this->assertSame(24, $current->payment_window_hours);
    }

    public function test_current_is_served_from_cache(): void
    {
        PropertyProfile::factory()->create(['name' => 'Cached Name']);
        PropertyProfile::current();

        DB::table('property_profiles')->update(['name' => 'Changed Behind The Back']);

        $this->assertSame('Cached Name', PropertyProfile::current()->name);
    }

    public function test_saving_the_profile_refreshes_the_cache(): void
    {
        $profile = PropertyProfile::factory()->create(['name' => 'Before']);
        PropertyProfile::current();

        $profile->update(['name' => 'After']);

        $this->assertSame('After', PropertyProfile::current()->name);
    }

    public function test_current_survives_a_store_that_serializes_values(): void
    {
        // The dev/production store; it refuses to unserialize objects (cache.serializable_classes = false).
        config(['cache.default' => 'database']);

        PropertyProfile::factory()->create([
            'name' => 'Serialized Villa',
            'bank_account_info' => ['bank' => 'Bank Example'],
        ]);
        PropertyProfile::current();

        DB::table('property_profiles')->update(['name' => 'Changed Behind The Back']);
        $cached = PropertyProfile::current();

        $this->assertInstanceOf(PropertyProfile::class, $cached);
        $this->assertTrue($cached->exists);
        $this->assertSame('Serialized Villa', $cached->name);
        $this->assertSame(PropertyType::Villa, $cached->type);
        $this->assertSame(['bank' => 'Bank Example'], $cached->bank_account_info);
        $this->assertFalse($cached->isDirty());

        $cached->update(['name' => 'Saved From Cache']);

        $this->assertSame(1, PropertyProfile::count());
        $this->assertSame('Saved From Cache', PropertyProfile::current()->name);
    }

    public function test_deleting_the_profile_clears_the_cache(): void
    {
        $profile = PropertyProfile::factory()->create();
        PropertyProfile::current();

        $profile->delete();

        $this->expectException(ModelNotFoundException::class);

        PropertyProfile::current();
    }

    public function test_current_fails_when_no_profile_exists(): void
    {
        $this->expectException(ModelNotFoundException::class);

        PropertyProfile::current();
    }

    public function test_json_columns_default_to_empty_arrays_and_round_trip(): void
    {
        $profile = PropertyProfile::factory()->create([
            'seo_settings' => null,
            'theme' => ['primary' => '#123456'],
        ]);

        $fresh = $profile->fresh();

        $this->assertNull($fresh->seo_settings);
        $this->assertSame(['primary' => '#123456'], $fresh->theme);

        $new = new PropertyProfile;
        $this->assertSame([], $new->seo_settings);
        $this->assertSame([], $new->bank_account_info);
    }

    public function test_a_second_profile_row_is_rejected(): void
    {
        PropertyProfile::factory()->create();

        $this->expectException(QueryException::class);

        PropertyProfile::factory()->create(['id' => 2]);
    }

    public function test_unknown_type_is_rejected_by_the_database(): void
    {
        $profile = PropertyProfile::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('property_profiles')->where('id', $profile->id)->update(['type' => 'castle']);
    }

    public function test_dark_backgrounds_use_the_dark_logo_when_there_is_one(): void
    {
        Storage::fake('public');
        $profile = PropertyProfile::factory()->create(['logo_path' => 'branding/logo.png', 'logo_dark_path' => 'branding/logo-white.png']);

        $logo = $profile->logoForDarkBackground();

        $this->assertSame('/storage/branding/logo-white.png', $logo->url);
        $this->assertFalse($logo->onLightBadge);
    }

    public function test_dark_backgrounds_put_the_regular_logo_on_a_light_badge_without_a_dark_logo(): void
    {
        Storage::fake('public');
        $profile = PropertyProfile::factory()->create(['logo_path' => 'branding/logo.png']);

        $logo = $profile->logoForDarkBackground();

        $this->assertSame('/storage/branding/logo.png', $logo->url);
        $this->assertTrue($logo->onLightBadge);
    }

    public function test_there_is_no_logo_until_one_is_uploaded(): void
    {
        $profile = PropertyProfile::factory()->create();

        $this->assertNull($profile->logoUrl());
        $this->assertNull($profile->logoForDarkBackground());
    }

    public function test_replacing_or_removing_a_logo_deletes_the_old_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('branding/old.png', 'old');
        Storage::disk('public')->put('branding/old-white.png', 'old');
        Storage::disk('public')->put('branding/new.png', 'new');
        $profile = PropertyProfile::factory()->create(['logo_path' => 'branding/old.png', 'logo_dark_path' => 'branding/old-white.png']);

        $profile->update(['logo_path' => 'branding/new.png', 'logo_dark_path' => null]);

        Storage::disk('public')->assertMissing(['branding/old.png', 'branding/old-white.png']);
        Storage::disk('public')->assertExists('branding/new.png');
    }
}

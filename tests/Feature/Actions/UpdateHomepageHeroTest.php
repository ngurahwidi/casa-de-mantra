<?php

namespace Tests\Feature\Actions;

use App\Actions\Content\UpdateHomepageHero;
use App\Models\PropertyProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UpdateHomepageHeroTest extends TestCase
{
    use RefreshDatabase;

    private UpdateHomepageHero $update;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->update = $this->app->make(UpdateHomepageHero::class);
    }

    public function test_text_is_trimmed_and_blank_text_is_stored_as_empty(): void
    {
        $profile = PropertyProfile::factory()->create(['hero_subtitle' => 'Old subtitle']);

        $this->update->handle($profile, '  Your own corner of Bali  ', '   ', null);

        $profile->refresh();
        $this->assertSame('Your own corner of Bali', $profile->hero_title);
        $this->assertNull($profile->hero_subtitle);
    }

    public function test_uploaded_banner_is_stored_with_its_alt_text(): void
    {
        $profile = PropertyProfile::factory()->create();

        $this->update->handle($profile, null, null, 'Pool at sunset', UploadedFile::fake()->image('sunset.jpg', 2400, 1200));

        $banner = $profile->fresh()->getFirstMedia(PropertyProfile::HERO);
        $this->assertSame('sunset', $banner->name);
        $this->assertSame('Pool at sunset', $banner->getCustomProperty('alt'));
        Storage::disk('public')->assertExists($banner->getPathRelativeToRoot());
    }

    public function test_new_banner_replaces_the_current_one_and_its_file(): void
    {
        $profile = PropertyProfile::factory()->create();
        $this->update->handle($profile, null, null, null, UploadedFile::fake()->image('old.jpg'));
        $old = $profile->fresh()->getFirstMedia(PropertyProfile::HERO);

        $this->update->handle($profile, null, null, null, UploadedFile::fake()->image('new.jpg'));

        $banners = $profile->fresh()->getMedia(PropertyProfile::HERO);
        $this->assertSame(['new'], $banners->pluck('name')->all());
        $this->assertModelMissing($old);
        Storage::disk('public')->assertMissing($old->getPathRelativeToRoot());
    }

    public function test_alt_text_of_the_current_banner_changes_without_a_new_upload(): void
    {
        $profile = PropertyProfile::factory()->create();
        $this->update->handle($profile, null, null, 'Old alt', UploadedFile::fake()->image('pool.jpg'));
        $banner = $profile->fresh()->getFirstMedia(PropertyProfile::HERO);

        $this->update->handle($profile, null, null, 'Infinity pool facing the rice fields', null);

        $this->assertSame('Infinity pool facing the rice fields', $banner->fresh()->getCustomProperty('alt'));
    }
}

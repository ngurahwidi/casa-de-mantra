<?php

namespace Tests\Feature\Services;

use App\Models\PropertyProfile;
use App\Models\RoomType;
use App\Services\Site\HomepageHero;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HomepageHeroTest extends TestCase
{
    use RefreshDatabase;

    private HomepageHero $hero;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->hero = $this->app->make(HomepageHero::class);
    }

    public function test_custom_text_and_banner_are_used_when_set(): void
    {
        $profile = PropertyProfile::factory()->create([
            'hero_title' => 'Your own corner of Bali',
            'hero_subtitle' => 'Wake up to the rice fields.',
        ]);
        $banner = $profile->addMedia(UploadedFile::fake()->image('banner.jpg'))
            ->withCustomProperties(['alt' => 'Pool at sunset'])
            ->toMediaCollection(PropertyProfile::HERO);
        $villa = $this->villaWithPhoto('villa.jpg');

        $hero = $this->hero->resolve($profile, collect([$villa]));

        $this->assertSame('Your own corner of Bali', $hero->title);
        $this->assertSame('Wake up to the rice fields.', $hero->subtitle);
        $this->assertTrue($hero->banner->is($banner));
        $this->assertSame('Pool at sunset', $hero->bannerAlt);
        $this->assertTrue($hero->hasCustomBanner);
    }

    public function test_empty_hero_falls_back_to_built_in_wording_and_the_first_villa_photo(): void
    {
        $profile = PropertyProfile::factory()->create([
            'name' => 'Casa de Mantra',
            'seo_settings' => ['meta_description' => 'Search engine text, not hero text.'],
        ]);
        $villa = $this->villaWithPhoto('villa.jpg');

        $hero = $this->hero->resolve($profile, collect([$villa]));

        $this->assertSame('A private villa, entirely yours.', $hero->title);
        $this->assertStringStartsWith('Slow mornings, long afternoons by the pool', $hero->subtitle);
        $this->assertSame('villa', $hero->banner->name);
        $this->assertSame('Casa de Mantra', $hero->bannerAlt);
        $this->assertFalse($hero->hasCustomTitle);
        $this->assertFalse($hero->hasCustomSubtitle);
        $this->assertFalse($hero->hasCustomBanner);
    }

    public function test_hero_has_no_banner_without_an_upload_or_villa_photos(): void
    {
        $hero = $this->hero->resolve(null, collect());

        $this->assertNull($hero->banner);
        $this->assertSame('A private villa, entirely yours.', $hero->title);
    }

    private function villaWithPhoto(string $fileName): RoomType
    {
        $villa = RoomType::factory()->published()->create();
        $villa->addMedia(UploadedFile::fake()->image($fileName))->toMediaCollection(RoomType::GALLERY);

        return $villa->load('media');
    }
}

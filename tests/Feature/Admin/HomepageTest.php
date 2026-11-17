<?php

namespace Tests\Feature\Admin;

use App\Actions\Content\UpdateHomepageHero;
use App\Filament\Pages\Homepage;
use App\Models\PropertyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The hero is content: staff edit it here although the property profile it lives on is owner-only.
 */
class HomepageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_staff_open_the_page_and_see_the_hero_preview(): void
    {
        PropertyProfile::factory()->create(['hero_title' => 'Your own corner of Bali']);
        $this->actingAs(User::factory()->create());

        $this->get('/admin/homepage')
            ->assertOk()
            ->assertSee('Your own corner of Bali');
    }

    public function test_staff_save_the_hero_text_and_banner(): void
    {
        PropertyProfile::factory()->create();
        $this->actingAs(User::factory()->create());

        Livewire::test(Homepage::class)
            ->callAction('editHero', data: [
                'title' => 'Your own corner of Bali',
                'subtitle' => 'Wake up to the rice fields.',
                'banner_alt' => 'Pool at sunset',
                'banner' => UploadedFile::fake()->image('sunset.jpg'),
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Homepage hero saved');

        $profile = PropertyProfile::query()->sole();
        $this->assertSame('Your own corner of Bali', $profile->hero_title);
        $this->assertSame('Wake up to the rice fields.', $profile->hero_subtitle);
        $this->assertSame('Pool at sunset', $profile->getFirstMedia(PropertyProfile::HERO)->getCustomProperty('alt'));
    }

    public function test_a_title_longer_than_the_hero_can_fit_is_rejected(): void
    {
        PropertyProfile::factory()->create();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(Homepage::class)
            ->callAction('editHero', data: ['title' => str_repeat('a', 81)])
            ->assertHasActionErrors(['title' => 'max']);

        $this->assertNull(PropertyProfile::query()->sole()->hero_title);
    }

    public function test_removing_the_banner_deletes_it(): void
    {
        $profile = PropertyProfile::factory()->create();
        app(UpdateHomepageHero::class)->handle($profile, null, null, null, UploadedFile::fake()->image('banner.jpg'));
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(Homepage::class)
            ->assertActionVisible('removeBanner')
            ->callAction('removeBanner')
            ->assertNotified('Banner removed')
            ->assertActionHidden('removeBanner');

        $this->assertFalse($profile->fresh()->hasMedia(PropertyProfile::HERO));
    }
}

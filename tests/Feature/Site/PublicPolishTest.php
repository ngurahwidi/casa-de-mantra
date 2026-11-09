<?php

namespace Tests\Feature\Site;

use App\Models\Page;
use App\Models\PropertyProfile;
use App\Models\RoomType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class PublicPolishTest extends TestCase
{
    use RefreshDatabase;

    private const FAILING_PATH = '/_test/failing-page';

    private RoomType $villa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        PropertyProfile::factory()->create([
            'name' => 'Casa de Mantra',
            'timezone' => 'Asia/Makassar',
            'whatsapp' => '+62 812-3456-7890',
            'theme' => ['primary' => '#123456'],
        ]);

        $this->villa = RoomType::factory()->published()->create(['slug' => 'villa-aruna']);
    }

    public function test_uploaded_photos_get_a_webp_rendition_at_every_width(): void
    {
        Storage::fake('public');

        $media = $this->upload(2400, 1600);

        foreach (RoomType::PHOTO_WIDTHS as $width) {
            $conversion = RoomType::photoConversion($width);

            $this->assertTrue($media->fresh()->hasGeneratedConversion($conversion));

            [$actualWidth, , $type] = getimagesize($media->getPath($conversion));

            $this->assertSame($width, $actualWidth);
            $this->assertSame(IMAGETYPE_WEBP, $type);
        }
    }

    public function test_renditions_never_upscale_a_small_original(): void
    {
        Storage::fake('public');

        $media = $this->upload(600, 400);

        [$actualWidth] = getimagesize($media->getPath(RoomType::photoConversion(1920)));

        $this->assertSame(600, $actualWidth);
    }

    public function test_photos_are_served_as_responsive_webp_with_the_original_as_fallback(): void
    {
        $this->photo('pool.jpg', [
            RoomType::photoConversion(480) => true,
            RoomType::photoConversion(800) => true,
        ]);

        $this->get(route('gallery'))
            ->assertOk()
            ->assertSee('type="image/webp"', false)
            ->assertSee('pool-webp-480.webp 480w', false)
            ->assertSee('pool-webp-800.webp 800w', false)
            ->assertDontSee('pool-webp-1280.webp', false)
            ->assertSee('/pool.jpg"', false)
            ->assertSee('loading="lazy"', false);
    }

    public function test_photos_use_the_original_while_renditions_are_still_queued(): void
    {
        $this->photo('pool.jpg', []);

        $this->get(route('gallery'))
            ->assertOk()
            ->assertDontSee('<source', false)
            ->assertSee('/pool.jpg"', false);
    }

    public function test_the_main_villa_photo_is_loaded_eagerly(): void
    {
        $this->photo('pool.jpg', []);

        $this->get(route('villas.show', $this->villa->slug))
            ->assertOk()
            ->assertSee('fetchpriority="high"', false);
    }

    public function test_page_queries_do_not_grow_with_the_number_of_photos(): void
    {
        PropertyProfile::current();

        $this->photo('first.jpg', []);
        $queriesWithOnePhoto = $this->countGalleryQueries();

        foreach (range(1, 5) as $number) {
            $this->photo("extra-{$number}.jpg", []);
        }

        $this->assertSame($queriesWithOnePhoto, $this->countGalleryQueries());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function pagesWithSectionHeadingTitle(): array
    {
        return [
            'villa index' => ['/villa'],
            'gallery' => ['/gallery'],
            'location' => ['/location'],
            'contact' => ['/contact'],
        ];
    }

    #[DataProvider('pagesWithSectionHeadingTitle')]
    public function test_page_title_is_the_single_top_level_heading(string $url): void
    {
        $content = $this->get($url)->assertOk()->getContent();

        $this->assertSame(1, substr_count($content, '<h1'));
    }

    public function test_invalid_form_fields_are_linked_to_their_error_message(): void
    {
        $this->from(route('contact'))
            ->followingRedirects()
            ->post(route('contact.store'), ['name' => '', 'email' => 'not-an-email', 'message' => 'Hello'])
            ->assertSee('aria-invalid="true" aria-describedby="email-error"', false)
            ->assertSee('id="email-error"', false)
            ->assertDontSee('aria-describedby="message-error"', false);
    }

    public function test_dates_use_the_readable_format_in_the_property_timezone(): void
    {
        Carbon::setTestNow('2026-10-24 20:00:00');

        $page = Page::factory()->create(['slug' => 'privacy-policy']);

        $this->get(route('policies.show', $page->slug))
            ->assertOk()
            ->assertSee('Last updated 25 Oct 2026');
    }

    public function test_missing_pages_show_the_branded_not_found_page(): void
    {
        $this->get('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertSee("We can't find that page.", false)
            ->assertSee('Casa de Mantra')
            ->assertSee('<meta name="robots" content="noindex,follow">', false)
            ->assertSee(route('villas.index'), false);
    }

    public function test_server_errors_show_the_branded_page_without_leaking_details(): void
    {
        $this->registerFailingRoute();

        $this->get(self::FAILING_PATH)
            ->assertInternalServerError()
            ->assertSee('Something went wrong on our side.')
            ->assertSee('Casa de Mantra')
            ->assertSee('#123456', false)
            ->assertSee('https://wa.me/6281234567890', false)
            ->assertDontSee('secret failure detail');
    }

    public function test_server_error_page_still_renders_when_the_profile_cannot_be_loaded(): void
    {
        $this->registerFailingRoute();

        Cache::shouldReceive('rememberForever')->andThrow(new RuntimeException('database unavailable'));

        $this->get(self::FAILING_PATH)
            ->assertInternalServerError()
            ->assertSee('Something went wrong on our side.')
            ->assertSee((string) config('app.name'))
            ->assertDontSee('https://wa.me/', false);
    }

    private function registerFailingRoute(): void
    {
        config(['app.debug' => false]);

        Route::get(self::FAILING_PATH, fn () => throw new RuntimeException('secret failure detail'));
    }

    private function upload(int $width, int $height): Media
    {
        return $this->villa
            ->addMedia(UploadedFile::fake()->image('pool.jpg', $width, $height))
            ->toMediaCollection(RoomType::GALLERY, 'public');
    }

    /**
     * @param  array<string, bool>  $generatedConversions
     */
    private function photo(string $fileName, array $generatedConversions): void
    {
        $this->villa->media()->create([
            'collection_name' => RoomType::GALLERY,
            'name' => pathinfo($fileName, PATHINFO_FILENAME),
            'file_name' => $fileName,
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'conversions_disk' => 'public',
            'size' => 1024,
            'manipulations' => [],
            'custom_properties' => ['alt' => 'The pool'],
            'generated_conversions' => $generatedConversions,
            'responsive_images' => [],
            'order_column' => 1,
        ]);
    }

    private function countGalleryQueries(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get(route('gallery'))->assertOk();

        return count(DB::getQueryLog());
    }
}

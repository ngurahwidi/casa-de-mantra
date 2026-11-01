<?php

namespace Tests\Feature\Actions;

use App\Actions\RoomType\AddGalleryPhotos;
use App\Models\RoomType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AddGalleryPhotosTest extends TestCase
{
    use RefreshDatabase;

    private AddGalleryPhotos $add;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->add = $this->app->make(AddGalleryPhotos::class);
    }

    public function test_photos_are_added_to_the_gallery_in_upload_order(): void
    {
        $roomType = RoomType::factory()->create();

        $added = $this->add->handle($roomType, [
            UploadedFile::fake()->image('pool.jpg'),
            UploadedFile::fake()->image('bedroom.png'),
        ]);

        $gallery = $roomType->fresh()->getMedia(RoomType::GALLERY);

        $this->assertCount(2, $added);
        $this->assertEquals($added->pluck('id')->all(), $gallery->pluck('id')->all());
        $this->assertSame(['pool', 'bedroom'], $gallery->pluck('name')->all());
        $this->assertNull($gallery->first()->getCustomProperty('alt'));

        foreach ($gallery as $photo) {
            Storage::disk('public')->assertExists($photo->getPathRelativeToRoot());
        }
    }

    public function test_later_uploads_go_after_existing_photos(): void
    {
        $roomType = RoomType::factory()->create();
        $first = $this->add->handle($roomType, [UploadedFile::fake()->image('a.jpg')])->sole();

        // A photo moved to the end by hand leaves a gap in the order.
        $first->order_column = 7;
        $first->save();

        $second = $this->add->handle($roomType, [UploadedFile::fake()->image('b.jpg')])->sole();

        $this->assertSame(8, $second->order_column);
        $this->assertSame(['a', 'b'], $roomType->fresh()->getMedia(RoomType::GALLERY)->pluck('name')->all());
    }

    public function test_order_is_per_room_type(): void
    {
        $other = RoomType::factory()->create();
        $this->add->handle($other, [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')]);

        $photo = $this->add->handle(RoomType::factory()->create(), [UploadedFile::fake()->image('c.jpg')])->sole();

        $this->assertSame(1, $photo->order_column);
    }
}

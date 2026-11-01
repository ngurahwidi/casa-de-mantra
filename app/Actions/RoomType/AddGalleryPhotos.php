<?php

namespace App\Actions\RoomType;

use App\Models\RoomType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Adds uploaded photos to the end of a room type's gallery, in upload order.
 *
 * Alt text starts empty and is filled in per photo afterwards. There is no transaction: files
 * cannot be rolled back, so a failure part-way keeps the photos added so far, each complete.
 */
class AddGalleryPhotos
{
    /**
     * @param  iterable<UploadedFile>  $photos
     * @return Collection<int, Media>
     */
    public function handle(RoomType $roomType, iterable $photos): Collection
    {
        $next = (int) $roomType->media()->where('collection_name', RoomType::GALLERY)->max('order_column') + 1;

        return collect($photos)->values()->map(function (UploadedFile $photo, int $index) use ($roomType, $next): Media {
            $media = $roomType
                ->addMedia($photo)
                ->usingName(pathinfo($photo->getClientOriginalName(), PATHINFO_FILENAME))
                ->withCustomProperties(['alt' => null])
                ->toMediaCollection(RoomType::GALLERY);

            $media->order_column = $next + $index;
            $media->save();

            return $media;
        });
    }
}

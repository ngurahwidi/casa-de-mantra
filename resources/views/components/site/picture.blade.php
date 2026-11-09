@props([
    'media',
    'alt' => '',
    'sizes' => '100vw',
    'priority' => false,
])

@php($srcset = \App\Models\RoomType::photoSrcset($media))

<picture>
    @if ($srcset !== '')
        <source type="image/webp" srcset="{{ $srcset }}" sizes="{{ $sizes }}">
    @endif
    <img
        src="{{ $media->getUrl() }}"
        alt="{{ $alt }}"
        @if ($priority) loading="eager" fetchpriority="high" @else loading="lazy" @endif
        decoding="async"
        {{ $attributes }}
    >
</picture>

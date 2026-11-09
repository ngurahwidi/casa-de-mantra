@props([
    'media' => null,
    'alt' => '',
    'sizes' => '100vw',
    'priority' => false,
    'ratio' => 'aspect-[4/3]',
])

<figure {{ $attributes->merge(['class' => 'relative overflow-hidden bg-[#DDE3D2] '.$ratio]) }}>
    @if ($media)
        <x-site.picture
            :media="$media"
            :alt="$alt"
            :sizes="$sizes"
            :priority="$priority"
            class="h-full w-full object-cover transition duration-700 ease-out group-hover:scale-[1.03]"
        />
    @else
        <div class="flex h-full w-full items-center justify-center bg-gradient-to-br from-[#DCE3D1] via-[#EAE5D8] to-[#BFC9AE]">
            <svg class="h-16 w-16 text-[#6E7C5E]/40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 21c0-6 3-9 8-10-1 6-3.5 9-8 10Zm0 0c0-4-2-7-6-8 .8 4 2.6 6.5 6 8Zm0-4V7m0 0c1.5-2 3-3 5-3.5M12 7c-1.5-2-3-3-5-3.5" />
            </svg>
        </div>
    @endif
</figure>

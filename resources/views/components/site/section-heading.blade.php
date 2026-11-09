@props([
    'eyebrow' => null,
    'title',
    'description' => null,
    'align' => 'left',
    'as' => 'h2',
])

<div @class(['max-w-2xl', 'mx-auto text-center' => $align === 'center'])>
    @if ($eyebrow)
        <p class="eyebrow">{{ $eyebrow }}</p>
    @endif
    <{{ $as }} class="mt-3 text-balance font-serif text-3xl leading-[1.08] sm:text-4xl lg:text-[2.75rem]">{{ $title }}</{{ $as }}>
    @if ($description)
        <p class="mt-4 text-pretty text-sm leading-7 text-[var(--color-muted)] sm:text-base">{{ $description }}</p>
    @endif
</div>

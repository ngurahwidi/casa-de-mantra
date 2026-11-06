@props(['villa', 'price' => null])

@php
    $photo = $villa->getFirstMedia(\App\Models\RoomType::GALLERY);
    $alt = $photo?->getCustomProperty('alt') ?: $villa->name;
    $currency = $profile?->currency;
    $url = route('villas.show', $villa->slug);
@endphp

<article class="group overflow-hidden rounded-2xl border border-[var(--color-line)] bg-[#FCFAF4] shadow-sm transition duration-300 hover:-translate-y-1 hover:shadow-xl hover:shadow-black/5">
    <a href="{{ $url }}" class="relative block">
        <x-site.photo :src="$photo?->getUrl()" :alt="$alt" ratio="aspect-[4/3]" />
        <span class="absolute left-4 top-4 rounded-full bg-[var(--color-paper)]/95 px-3 py-1.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-[var(--color-primary)] shadow-sm">
            Private villa
        </span>
    </a>

    <div class="p-5 sm:p-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h3 class="font-serif text-2xl leading-tight">
                    <a href="{{ $url }}" class="transition hover:text-[var(--color-primary)]">{{ $villa->name }}</a>
                </h3>
                <p class="mt-2 text-xs leading-5 text-[var(--color-muted)]">
                    {{ $villa->max_adults }} guests
                    <span class="mx-1 text-[var(--color-line)]">·</span>
                    {{ $villa->bedrooms }} {{ \Illuminate\Support\Str::plural('bedroom', $villa->bedrooms) }}
                    <span class="mx-1 text-[var(--color-line)]">·</span>
                    {{ $villa->bathrooms }} {{ \Illuminate\Support\Str::plural('bathroom', $villa->bathrooms) }}
                    @if ($villa->size_m2)
                        <span class="mx-1 text-[var(--color-line)]">·</span>
                        {{ $villa->size_m2 }} m²
                    @endif
                </p>
            </div>
        </div>

        <div class="mt-5 flex items-end justify-between gap-4 border-t border-[var(--color-line)] pt-5">
            <div>
                <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">From</p>
                <p class="mt-1 font-serif text-xl leading-none">
                    @if ($price && $currency)
                        {{ \App\Support\Money::format($price, $currency) }}
                        <span class="font-sans text-[11px] font-normal text-[var(--color-muted)]">/ night</span>
                    @else
                        Rates on request
                    @endif
                </p>
            </div>

            <a href="{{ $url }}" class="inline-flex shrink-0 items-center gap-2 rounded-full border border-[var(--color-line)] px-4 py-2.5 text-[11px] font-semibold uppercase tracking-[0.14em] text-[var(--color-primary)] transition hover:border-[var(--color-primary)] hover:bg-[var(--color-primary)] hover:text-[var(--color-paper)]">
                View villa
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                </svg>
            </a>
        </div>
    </div>
</article>

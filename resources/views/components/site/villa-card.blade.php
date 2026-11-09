@props(['villa', 'profile' => null, 'price' => null, 'stay' => null, 'stayQuery' => null])

@php
    $photo = $villa->getFirstMedia(\App\Models\RoomType::GALLERY);
    $alt = $photo?->getCustomProperty('alt') ?: $villa->name;
    $currency = $profile?->currency;

    $linkQuery = $stayQuery?->villaQuery($villa);

    if ($stay !== null && ! $stay->isAvailable && $stay->alternative !== null && $stayQuery !== null) {
        [$adults, $children] = $stayQuery->partyFor($villa);
        $linkQuery = [
            'check_in' => $stay->alternative->checkIn,
            'check_out' => $stay->alternative->checkOut,
            'adults' => $adults,
            'children' => $children,
        ];
    }

    $url = route('villas.show', $villa->slug).($linkQuery ? '?'.http_build_query($linkQuery) : '');
    $formatDate = fn (string $date) => \Carbon\CarbonImmutable::parse($date)->format('j M Y');
@endphp

<article class="group flex flex-col overflow-hidden rounded-2xl border border-[var(--color-line)] bg-[#FCFAF4] shadow-sm transition duration-300 hover:-translate-y-1 hover:shadow-xl hover:shadow-black/5">
    <a href="{{ $url }}" class="relative block">
        <x-site.photo :media="$photo" :alt="$alt" ratio="aspect-[4/3]" sizes="(min-width: 1024px) 350px, (min-width: 768px) 50vw, 100vw" />
        @if ($stay !== null)
            <span @class([
                'absolute right-4 top-4 rounded-full px-3 py-1.5 text-[10px] font-semibold uppercase tracking-[0.16em] shadow-sm',
                'bg-[#E7E9DC] text-[var(--color-primary)]' => $stay->isAvailable,
                'bg-[#F8EDEA] text-[#7A3B30]' => ! $stay->isAvailable,
            ])>
                {{ $stay->isAvailable ? 'Available' : 'Not available' }}
            </span>
        @endif
    </a>

    <div class="flex flex-1 flex-col p-5 sm:p-6">
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

        <div class="mt-auto pt-5">
            <div class="flex items-end justify-between gap-4 border-t border-[var(--color-line)] pt-5">
                <div class="min-w-0">
                    @if ($stay !== null && $stay->isAvailable)
                        <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">
                            Total · {{ $stay->quote->nightCount() }} {{ \Illuminate\Support\Str::plural('night', $stay->quote->nightCount()) }}
                        </p>
                        <p class="mt-1 font-serif text-xl leading-none">
                            {{ \App\Support\Money::format($stay->quote->total, $currency) }}
                        </p>
                        <p class="mt-1.5 truncate text-[11px] text-[var(--color-muted)]">
                            {{ $formatDate($stay->quote->checkIn) }} – {{ $formatDate($stay->quote->checkOut) }}
                            · {{ \App\Support\Money::format((float) $stay->quote->total / max(1, $stay->quote->nightCount()), $currency) }} avg / night
                        </p>
                    @elseif ($stay !== null)
                        <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Your dates</p>
                        <p class="mt-1 font-serif text-lg leading-tight text-[#7A3B30]">Not available</p>
                        @if ($stay->alternative !== null)
                            <p class="mt-1.5 text-[11px] leading-5 text-[var(--color-muted)]">
                                Nearest stay: {{ $formatDate($stay->alternative->checkIn) }} – {{ $formatDate($stay->alternative->checkOut) }}
                                · {{ \App\Support\Money::format($stay->alternative->total, $currency) }}
                            </p>
                        @elseif ($stay->message)
                            <p class="mt-1.5 text-[11px] leading-5 text-[var(--color-muted)]">{{ $stay->message }}</p>
                        @endif
                    @else
                        <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">From</p>
                        <p class="mt-1 font-serif text-xl leading-none">
                            @if ($price && $currency)
                                {{ \App\Support\Money::format($price, $currency) }}
                                <span class="font-sans text-[11px] font-normal text-[var(--color-muted)]">/ night</span>
                            @else
                                Rates on request
                            @endif
                        </p>
                    @endif
                </div>

                <a href="{{ $url }}" class="inline-flex shrink-0 items-center gap-2 rounded-full border border-[var(--color-line)] px-4 py-2.5 text-[11px] font-semibold uppercase tracking-[0.14em] text-[var(--color-primary)] transition hover:border-[var(--color-primary)] hover:bg-[var(--color-primary)] hover:text-[var(--color-paper)]">
                    View villa
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                    </svg>
                </a>
            </div>
        </div>
    </div>
</article>

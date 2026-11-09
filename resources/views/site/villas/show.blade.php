@extends('layouts.public')

@section('content')
    @php
        $photos = $villa->getMedia(\App\Models\RoomType::GALLERY);
        $hero = $photos->first();
        $currency = $profile?->currency;
        $amenityGroups = $villa->amenities->groupBy(fn ($amenity) => $amenity->category ?: 'general');
    @endphp

    <section class="border-b border-[var(--color-line)] bg-[var(--color-paper-deep)]">
        <div class="site-container py-8 sm:py-10">
            <nav class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]" aria-label="Breadcrumb">
                <a href="{{ route('home') }}" class="transition hover:text-[var(--color-primary)]">Home</a>
                <span class="mx-2 text-[var(--color-line)]" aria-hidden="true">/</span>
                <a href="{{ route('villas.index') }}" class="transition hover:text-[var(--color-primary)]">Villas</a>
                <span class="mx-2 text-[var(--color-line)]" aria-hidden="true">/</span>
                <span class="text-[var(--color-primary)]" aria-current="page">{{ $villa->name }}</span>
            </nav>

            <div class="mt-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="eyebrow">Private villa</p>
                    <h1 class="mt-3 font-serif text-4xl leading-[1.02] sm:text-5xl lg:text-6xl">{{ $villa->name }}</h1>
                </div>
                <p class="max-w-sm text-sm leading-6 text-[var(--color-muted)] sm:text-right">
                    {{ $villa->max_adults }} guests
                    <span class="mx-1 text-[var(--color-line)]">·</span>
                    {{ $villa->bedrooms }} {{ \Illuminate\Support\Str::plural('bedroom', $villa->bedrooms) }}
                    <span class="mx-1 text-[var(--color-line)]">·</span>
                    {{ $villa->bathrooms }} {{ \Illuminate\Support\Str::plural('bathroom', $villa->bathrooms) }}
                    @if ($villa->size_m2)
                        <br class="hidden sm:block">
                        {{ $villa->size_m2 }} m² of indoor and outdoor living
                    @endif
                </p>
            </div>
        </div>
    </section>

    <section class="py-6 sm:py-10">
        <div class="site-container">
            <div class="grid gap-3 lg:grid-cols-[1.55fr_1fr]">
                <x-site.photo
                    :media="$hero"
                    :alt="$hero?->getCustomProperty('alt') ?: $villa->name"
                    sizes="(min-width: 1024px) 660px, 100vw"
                    ratio="aspect-[4/3] sm:aspect-[16/10] lg:aspect-[4/3]"
                    priority
                    class="rounded-2xl"
                />

                <div class="grid grid-cols-2 gap-3">
                    @foreach ($photos->skip(1)->take(2) as $photo)
                        <x-site.photo
                            :media="$photo"
                            :alt="$photo->getCustomProperty('alt') ?: $villa->name"
                            sizes="(min-width: 1024px) 210px, 50vw"
                            ratio="aspect-square"
                            class="rounded-2xl"
                        />
                    @endforeach

                    <div class="col-span-2 flex items-center justify-between gap-4 rounded-2xl border border-[var(--color-line)] bg-[#FCFAF4] p-5">
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">The gallery</p>
                            <p class="mt-1 font-serif text-xl">{{ $photos->count() }} {{ \Illuminate\Support\Str::plural('photo', $photos->count()) }}</p>
                        </div>
                        <a href="{{ route('gallery') }}" class="inline-flex items-center gap-2 rounded-full border border-[var(--color-line)] px-4 py-2.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-[var(--color-primary)] transition hover:border-[var(--color-primary)]">
                            View all
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="py-10 sm:py-16">
        <div class="site-container grid gap-12 lg:grid-cols-[1.5fr_0.9fr] lg:items-start">
            <div>
                <div class="max-w-2xl">
                    <p class="eyebrow">The villa</p>
                    <h2 class="mt-3 text-balance font-serif text-3xl leading-[1.1] sm:text-4xl">A place to slow down.</h2>
                    <div class="prose-site mt-5">
                        {!! nl2br(e($villa->description ?: 'A comfortable private villa with space to gather, rest, and make the most of your time away.')) !!}
                    </div>
                </div>

                @if ($villa->amenities->isNotEmpty())
                    <div class="mt-12 border-t border-[var(--color-line)] pt-10">
                        <p class="eyebrow">What is here</p>
                        <h2 class="mt-3 font-serif text-3xl">Everything you need.</h2>

                        <div class="mt-8 grid gap-8 sm:grid-cols-2">
                            @foreach ($amenityGroups as $category => $amenities)
                                <div>
                                    <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">
                                        {{ \Illuminate\Support\Str::headline($category) }}
                                    </p>
                                    <ul class="mt-3 space-y-2.5">
                                        @foreach ($amenities as $amenity)
                                            <li class="flex items-start gap-3 text-sm leading-6">
                                                <svg class="mt-1 h-4 w-4 shrink-0 text-[var(--color-primary)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 12.5l4.2 4.2L19 7" />
                                                </svg>
                                                <span>{{ $amenity->name }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="mt-12 border-t border-[var(--color-line)] pt-10">
                    <p class="eyebrow">Good to know</p>
                    <h2 class="mt-3 font-serif text-3xl">Before you arrive.</h2>

                    <div class="mt-8 grid gap-6 sm:grid-cols-3">
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Check-in</p>
                            <p class="mt-2 font-serif text-2xl">From {{ \Carbon\Carbon::parse($profile?->checkin_time ?? '14:00')->format('H:i') }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Check-out</p>
                            <p class="mt-2 font-serif text-2xl">By {{ \Carbon\Carbon::parse($profile?->checkout_time ?? '12:00')->format('H:i') }}</p>
                        </div>
                        @if ($ratePlan?->min_stay)
                            <div>
                                <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Minimum stay</p>
                                <p class="mt-2 font-serif text-2xl">{{ $ratePlan->min_stay }} {{ \Illuminate\Support\Str::plural('night', $ratePlan->min_stay) }}</p>
                            </div>
                        @endif
                    </div>

                    @if ($policy?->description)
                        <div class="mt-8 rounded-2xl border border-[var(--color-line)] bg-[var(--color-paper-deep)] p-6">
                            <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">{{ $policy->name }} policy</p>
                            <p class="mt-3 text-sm leading-7 text-[var(--color-muted)]">{{ $policy->description }}</p>
                        </div>
                    @endif
                </div>
            </div>

            <aside class="lg:sticky lg:top-24">
                <div class="rounded-2xl border border-[var(--color-line)] bg-[#FCFAF4] p-6 shadow-sm sm:p-7">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Rates from</p>
                    <p class="mt-2 font-serif text-4xl leading-none">
                        @if ($price && $currency)
                            {{ \App\Support\Money::format($price, $currency) }}
                            <span class="font-sans text-xs font-normal text-[var(--color-muted)]">/ night</span>
                        @else
                            On request
                        @endif
                    </p>

                    <livewire:site.availability-search :villa="$villa" />
                </div>
            </aside>
        </div>
    </section>

    @if ($others->isNotEmpty())
        <section class="border-t border-[var(--color-line)] bg-[var(--color-paper-deep)] py-16 sm:py-20">
            <div class="site-container">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <x-site.section-heading eyebrow="Keep exploring" title="Other villas." />
                    <a href="{{ route('villas.index') }}" class="inline-flex shrink-0 items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.16em] text-[var(--color-primary)]">
                        All villas
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M12 5l7 7-7 7" />
                        </svg>
                    </a>
                </div>

                <div class="mt-8 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                    @foreach ($others as $other)
                        <x-site.villa-card :villa="$other" :profile="$profile" :price="$otherPrices[$other->id] ?? null" />
                    @endforeach
                </div>
            </div>
        </section>
    @endif
@endsection

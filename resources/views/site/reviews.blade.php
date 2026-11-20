@extends('layouts.public')

@section('content')
    <section class="border-b border-[var(--color-line)] bg-[var(--color-paper-deep)]">
        <div class="site-container py-14 sm:py-20">
            <nav class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]" aria-label="Breadcrumb">
                <a href="{{ route('home') }}" class="transition hover:text-[var(--color-primary)]">Home</a>
                <span class="mx-2 text-[var(--color-line)]" aria-hidden="true">/</span>
                <span class="text-[var(--color-primary)]" aria-current="page">Reviews</span>
            </nav>

            <div class="mt-8 max-w-3xl">
                <p class="eyebrow">Guest words</p>
                <h1 class="mt-4 text-balance font-serif text-4xl leading-[1.02] sm:text-5xl lg:text-6xl">Every review, every stay.</h1>
                <p class="mt-5 text-sm leading-7 text-[var(--color-muted)]">The notes people leave behind, from a few quiet stars to a full story.</p>
            </div>
        </div>
    </section>

    <section class="py-16 sm:py-20">
        <div class="site-container">
            @if ($reviews->isEmpty())
                <div class="rounded-2xl border border-[var(--color-line)] bg-[#FCFAF4] p-8 text-center">
                    <p class="font-serif text-2xl">Reviews are on their way.</p>
                    <p class="mt-2 text-sm text-[var(--color-muted)]">Stay tuned for the first guest words.</p>
                </div>
            @else
                <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                    @foreach ($reviews as $review)
                        <figure class="flex flex-col rounded-2xl border border-[var(--color-line)] bg-[#FCFAF4] p-6">
                            <div class="flex items-center gap-1 text-[var(--color-rating)]" aria-label="{{ $review->rating }} out of 5 stars">
                                @for ($i = 1; $i <= 5; $i++)
                                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="{{ $i <= $review->rating ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="1.2" aria-hidden="true">
                                        <path d="M10 1.8l2.5 5.2 5.7.8-4.1 4 1 5.7-5.1-2.7-5.1 2.7 1-5.7-4.1-4 5.7-.8L10 1.8z" />
                                    </svg>
                                @endfor
                            </div>
                            <blockquote class="mt-4 flex-1">
                                <p class="text-sm leading-7 text-[var(--color-ink)]/90">“{{ $review->displayComment() }}”</p>
                            </blockquote>
                            <figcaption class="mt-5 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-t border-[var(--color-line)] pt-4">
                                <span class="text-[11px] font-semibold uppercase tracking-[0.16em] text-[var(--color-ink)]">{{ $review->guest_name }}</span>
                                @if ($review->source)
                                    <span class="text-[10px] uppercase tracking-[0.14em] text-[var(--color-muted)]">via {{ $review->source }}</span>
                                @endif
                            </figcaption>
                        </figure>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    @if ($villas->isNotEmpty())
        <section class="bg-[var(--color-paper-deep)] py-16 sm:py-20">
            <div class="site-container">
                <x-site.section-heading
                    eyebrow="Stay with us"
                    title="The villas our guests remember."
                    description="Each villa has its own character — and plenty of room to settle in."
                />

                <div class="mt-10 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                    @foreach ($villas as $villa)
                        <x-site.villa-card
                            :villa="$villa"
                            :profile="$profile"
                            :price="$prices[$villa->id] ?? null"
                        />
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($amenities->isNotEmpty())
        <section class="py-16 sm:py-20">
            <div class="site-container grid gap-10 lg:grid-cols-[0.7fr_1.3fr]">
                <x-site.section-heading
                    eyebrow="The essentials"
                    title="Everything already here."
                    description="The practical things that make unpacking and settling in easier."
                />

                <ul class="grid gap-x-8 gap-y-4 border-t border-[var(--color-line)] pt-8 text-sm sm:grid-cols-2">
                    @foreach ($amenities as $amenity)
                        <li class="flex items-start gap-3 leading-6">
                            <svg class="mt-1 h-4 w-4 shrink-0 text-[var(--color-primary)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 12.5l4.2 4.2L19 7" />
                            </svg>
                            <span>{{ $amenity->name }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    <x-site.cta :whatsapp-url="$whatsappUrl" />
@endsection

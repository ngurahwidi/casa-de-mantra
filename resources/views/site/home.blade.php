@extends('layouts.public')

@section('content')
    <section class="relative isolate z-10 min-h-[34rem] bg-[var(--color-primary)] text-white sm:min-h-[40rem]">
        @if ($hero->banner)
            <x-site.picture
                :media="$hero->banner"
                :alt="$hero->bannerAlt"
                sizes="100vw"
                priority
                class="absolute inset-0 -z-20 h-full w-full object-cover"
            />
        @else
            <div class="absolute inset-0 -z-20 bg-[radial-gradient(circle_at_20%_20%,rgba(255,255,255,0.16),transparent_45%),radial-gradient(circle_at_80%_10%,rgba(255,255,255,0.08),transparent_40%)]"></div>
        @endif
        <div class="absolute inset-0 -z-10 bg-gradient-to-t from-black/80 via-black/35 to-black/10"></div>

        <div class="site-container relative flex min-h-[34rem] flex-col justify-end pb-14 pt-24 sm:min-h-[40rem] sm:pb-20">
            <h1 class="mt-5 max-w-3xl text-balance font-serif text-[2.85rem] leading-[0.98] sm:text-6xl lg:text-7xl">
                {{ $hero->title }}
            </h1>
            <p class="mt-5 max-w-xl text-pretty text-sm leading-7 text-white/80 sm:text-base">
                {{ $hero->subtitle }}
            </p>

            <div class="mt-8 w-full">
                @if ($profile)
                    <livewire:site.home-search :max-guests="$maxGuests" />

                    <p class="mt-3 flex items-center gap-2 text-[11px] leading-5 text-white/80">
                        <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" />
                            <path stroke-linecap="round" d="M12 11v5M12 8h.01" />
                        </svg>
                        Rates are per night and confirmed before booking. All stays are non-refundable.
                    </p>
                @endif
            </div>
        </div>
    </section>

    <section id="villas" class="scroll-mt-24 py-16 sm:py-24">
        <div class="site-container">
            <div class="flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between">
                <x-site.section-heading
                    eyebrow="The villas"
                    title="Choose your place to stay."
                    description="Each villa has its own character, its own garden, and enough room to settle in properly."
                />
                <a href="{{ route('villas.index') }}" class="inline-flex shrink-0 items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.16em] text-[var(--color-primary)] transition hover:gap-3">
                    All villas
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M12 5l7 7-7 7" />
                    </svg>
                </a>
            </div>

            @if ($stayQuery !== null)
                @php
                    $availableCount = collect($stays)->filter(fn ($stay) => $stay->isAvailable)->count();
                    $policies = collect($stays)
                        ->filter(fn ($stay) => $stay->isAvailable && $stay->policy !== null)
                        ->map(fn ($stay) => $stay->policy->name)
                        ->unique()
                        ->values();
                    $formatStayDate = fn (string $date) => \Carbon\CarbonImmutable::parse($date)->format('j M Y');
                @endphp

                <div class="mt-8 flex flex-col gap-3 rounded-2xl border border-[var(--color-line)] bg-[#FCFAF4] px-5 py-4 text-sm sm:flex-row sm:items-center sm:justify-between">
                    <p class="leading-6">
                        <span class="font-semibold">{{ $availableCount }} of {{ $villas->count() }} {{ \Illuminate\Support\Str::plural('villa', $villas->count()) }} available</span>
                        <span class="text-[var(--color-muted)]">
                            for {{ $formatStayDate($stayQuery->checkIn->toDateString()) }} – {{ $formatStayDate($stayQuery->checkOut->toDateString()) }}
                            · {{ $stayQuery->guests }} {{ \Illuminate\Support\Str::plural('guest', $stayQuery->guests) }}
                            @if ($policies->isNotEmpty())
                                · {{ $policies->implode(' / ') }}
                            @endif
                        </span>
                    </p>
                    <a href="{{ route('home') }}#villas" class="inline-flex shrink-0 items-center gap-2 text-[10px] font-semibold uppercase tracking-[0.16em] text-[var(--color-primary)] transition hover:gap-3">
                        Clear dates
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
                        </svg>
                    </a>
                </div>

                @if ($availableCount === 0)
                    <p class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-2 text-xs leading-6 text-[var(--color-muted)]">
                        Nothing fits these dates exactly.
                        @if ($whatsappUrl)
                            <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer" class="font-semibold uppercase tracking-[0.14em] text-[var(--color-primary)] underline underline-offset-4">
                                Ask us about other dates
                            </a>
                        @endif
                    </p>
                @endif
            @endif

            @if ($villas->isNotEmpty())
                <div class="mt-10 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                    @foreach ($villas as $villa)
                        <x-site.villa-card
                            :villa="$villa"
                            :profile="$profile"
                            :price="$prices[$villa->id] ?? null"
                            :stay="$stays[$villa->id] ?? null"
                            :stay-query="$stayQuery"
                        />
                    @endforeach
                </div>
                <p class="mt-5 max-w-2xl text-xs leading-5 text-[var(--color-muted)]">
                    @if ($stayQuery !== null)
                        Totals are for the nights selected above. Availability is checked again when we confirm your request.
                    @else
                        Rates are per night and subject to availability. All stays are non-refundable; the full policy is confirmed before you book.
                    @endif
                </p>
            @else
                <div class="mt-10 rounded-2xl border border-[var(--color-line)] bg-[#FCFAF4] p-8">
                    <p class="font-serif text-2xl">Villas are being prepared.</p>
                    <p class="mt-2 text-sm text-[var(--color-muted)]">Get in touch and we will share what is available for your dates.</p>
                    <a href="{{ route('contact') }}" class="btn-primary mt-6">Contact us</a>
                </div>
            @endif
        </div>
    </section>

    <section class="bg-[#E7E9DC] py-16 sm:py-24">
        <div class="site-container">
            <x-site.section-heading
                eyebrow="Why book direct"
                title="A simpler way to stay."
                description="No platforms in between. Just a conversation, a clear price, and a villa ready for you."
            />

            <div class="mt-10 grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['title' => 'Best available rate', 'body' => 'Book straight with the villa and skip platform fees.'],
                    ['title' => 'Personal service', 'body' => 'Ask about transfers, meals, or a late check-out. We will help.'],
                    ['title' => 'Clear policies', 'body' => 'Non-refundable stays are explained before you commit.'],
                    ['title' => 'Local knowledge', 'body' => 'Recommendations for beaches, tables, and quiet corners.'],
                ] as $item)
                    <div class="border-t border-[var(--color-primary)]/20 pt-5">
                        <p class="font-serif text-xl leading-tight">{{ $item['title'] }}</p>
                        <p class="mt-3 text-sm leading-6 text-[var(--color-muted)]">{{ $item['body'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    @if ($photos->isNotEmpty())
        <section class="py-16 sm:py-24">
            <div class="site-container">
                <div class="flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between">
                    <x-site.section-heading eyebrow="A look around" title="Life at the villa." />
                    <a href="{{ route('gallery') }}" class="inline-flex shrink-0 items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.16em] text-[var(--color-primary)] transition hover:gap-3">
                        Full gallery
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M12 5l7 7-7 7" />
                        </svg>
                    </a>
                </div>

                <div class="mt-10 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
                    @foreach ($photos as $index => $photo)
                        <x-site.photo
                            :media="$photo['media']"
                            :alt="$photo['alt']"
                            :sizes="$index === 0 ? '(min-width: 1024px) 540px, 100vw' : '(min-width: 1024px) 270px, 50vw'"
                            :ratio="$index === 0 ? 'aspect-[4/5] sm:aspect-auto sm:h-full' : 'aspect-square'"
                            class="{{ $index === 0 ? 'col-span-2 row-span-2 group' : 'group' }}"
                        />
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($reviews->isNotEmpty())
        <section class="bg-[var(--color-paper-deep)] py-16 sm:py-24" data-review-slider>
            <div class="site-container">
                <div class="flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between">
                    <x-site.section-heading
                        eyebrow="Guest words"
                        title="What guests remember."
                        description="Notes from people who have stayed with us."
                    />

                    <div class="hidden shrink-0 items-center gap-2 sm:flex" data-review-controls>
                        <button
                            type="button"
                            data-review-scroll="prev"
                            aria-label="Previous guest reviews"
                            class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-[var(--color-line)] bg-[#FCFAF4] text-[var(--color-primary)] transition hover:border-[var(--color-primary)] hover:bg-[var(--color-primary)] hover:text-[var(--color-paper)] disabled:pointer-events-none disabled:opacity-40"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 5l-7 7 7 7" />
                            </svg>
                        </button>
                        <button
                            type="button"
                            data-review-scroll="next"
                            aria-label="Next guest reviews"
                            class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-[var(--color-line)] bg-[#FCFAF4] text-[var(--color-primary)] transition hover:border-[var(--color-primary)] hover:bg-[var(--color-primary)] hover:text-[var(--color-paper)] disabled:pointer-events-none disabled:opacity-40"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div
                    class="mt-8 flex snap-x snap-mandatory gap-6 overflow-x-auto overscroll-x-contain pb-2 [scrollbar-width:none] sm:mt-10 [&::-webkit-scrollbar]:hidden"
                    data-review-rail
                    role="region"
                    aria-label="Guest reviews"
                    tabindex="0"
                >
                    @foreach ($reviews as $review)
                        <article
                            class="flex w-[85%] shrink-0 flex-col rounded-2xl border border-[var(--color-line)] bg-[#FCFAF4] p-6 shadow-sm sm:w-[calc((100%_-_3rem)/2.25)] sm:p-7 lg:w-[calc((100%_-_4.5rem)/3.25)] {{ $loop->last ? 'snap-end' : 'snap-start' }}"
                            data-review-card
                        >
                            <div class="flex items-center gap-1 text-[var(--color-rating)]" aria-label="{{ $review->rating }} out of 5 stars">
                                @for ($i = 1; $i <= 5; $i++)
                                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="{{ $i <= $review->rating ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="1.2" aria-hidden="true">
                                        <path d="M10 1.8l2.5 5.2 5.7.8-4.1 4 1 5.7-5.1-2.7-5.1 2.7 1-5.7-4.1-4 5.7-.8L10 1.8z" />
                                    </svg>
                                @endfor
                            </div>

                            <blockquote class="mt-5 flex-1">
                                <p class="line-clamp-6 text-sm leading-7 text-[var(--color-ink)]/90">“{{ $review->comment }}”</p>
                            </blockquote>

                            <div class="mt-6 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-t border-[var(--color-line)] pt-4">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-[var(--color-ink)]">{{ $review->guest_name }}</p>
                                @if ($review->source)
                                    <p class="text-[10px] uppercase tracking-[0.14em] text-[var(--color-muted)]">via {{ $review->source }}</p>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>

                <p class="mt-3 text-[10px] uppercase tracking-[0.16em] text-[var(--color-muted)] sm:hidden" data-review-hint>
                    Swipe to read more
                </p>
            </div>
        </section>
    @endif

    <section class="py-16 sm:py-24">
        <div class="site-container grid gap-10 lg:grid-cols-[1fr_1.1fr] lg:items-center">
            <div>
                <x-site.section-heading
                    eyebrow="Find us"
                    title="Close enough to everything. Far enough to unwind."
                    :description="$profile?->address"
                />

                <dl class="mt-8 grid gap-5 sm:grid-cols-2">
                    <div>
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Check-in</dt>
                        <dd class="mt-1 font-serif text-2xl">From {{ \Carbon\Carbon::parse($profile?->checkin_time ?? '14:00')->format('H:i') }}</dd>
                    </div>
                    <div>
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Check-out</dt>
                        <dd class="mt-1 font-serif text-2xl">By {{ \Carbon\Carbon::parse($profile?->checkout_time ?? '12:00')->format('H:i') }}</dd>
                    </div>
                </dl>

                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="{{ route('location') }}" class="btn-outline">Location & directions</a>
                    @if ($whatsappUrl)
                        <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer" class="btn-primary">Ask on WhatsApp</a>
                    @endif
                </div>
            </div>

            <div class="overflow-hidden rounded-2xl border border-[var(--color-line)] bg-[#E7E9DC]">
                @if ($profile?->lat && $profile?->lng)
                    <iframe
                        title="Map showing {{ $profile->name }}"
                        src="https://www.google.com/maps?q={{ $profile->lat }},{{ $profile->lng }}&z=14&output=embed"
                        class="h-80 w-full border-0 sm:h-96"
                        loading="lazy"
                        referrerpolicy="no-referrer-when-downgrade"
                        allowfullscreen
                    ></iframe>
                @else
                    <div class="flex h-80 flex-col items-center justify-center p-8 text-center sm:h-96">
                        <svg class="h-12 w-12 text-[var(--color-primary)]/40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 21s7-5.6 7-11a7 7 0 10-14 0c0 5.4 7 11 7 11Z" />
                            <circle cx="12" cy="10" r="2.5" />
                        </svg>
                        <p class="mt-4 font-serif text-2xl">Directions shared on request</p>
                        <a href="{{ route('contact') }}" class="mt-4 text-xs font-semibold uppercase tracking-[0.16em] text-[var(--color-primary)] underline underline-offset-4">Ask us for the address</a>
                    </div>
                @endif
            </div>
        </div>
    </section>

    @if ($faqs->isNotEmpty())
        <section class="border-y border-[var(--color-line)] bg-[var(--color-paper-deep)] py-16 sm:py-24">
            <div class="site-container grid gap-10 lg:grid-cols-[0.8fr_1.2fr]">
                <div>
                    <x-site.section-heading eyebrow="Good to know" title="A few answers before you arrive." />
                    <a href="{{ route('faq') }}" class="mt-6 inline-flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.16em] text-[var(--color-primary)]">
                        All questions
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M12 5l7 7-7 7" />
                        </svg>
                    </a>
                </div>

                <div class="divide-y divide-[var(--color-line)] border-y border-[var(--color-line)]">
                    @foreach ($faqs as $faq)
                        <details class="group py-5">
                            <summary class="flex cursor-pointer list-none items-start justify-between gap-6 text-left font-medium [&::-webkit-details-marker]:hidden">
                                <span>{{ $faq->question }}</span>
                                <svg class="mt-1 h-4 w-4 shrink-0 text-[var(--color-muted)] transition group-open:rotate-45" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                                    <path stroke-linecap="round" d="M12 5v14M5 12h14" />
                                </svg>
                            </summary>
                            <p class="mt-3 max-w-xl text-sm leading-7 text-[var(--color-muted)]">{{ $faq->answer }}</p>
                        </details>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <section class="py-16 sm:py-24">
        <div class="site-container">
            <div class="overflow-hidden rounded-3xl bg-[var(--color-primary)] px-6 py-14 text-center text-[var(--color-paper)] sm:px-12 sm:py-20">
                <p class="eyebrow !text-white/60">Your stay starts here</p>
                <h2 class="mx-auto mt-4 max-w-2xl text-balance font-serif text-4xl leading-[1.05] sm:text-5xl">
                    The villa you are looking for might already be waiting.
                </h2>
                <p class="mx-auto mt-4 max-w-xl text-sm leading-7 text-white/70">
                    Tell us your dates and who is coming. We will reply with availability, the total price, and the next step.
                </p>
                <div class="mt-8 flex flex-wrap justify-center gap-3">
                    <a href="{{ route('contact') }}" class="inline-flex items-center justify-center rounded-full bg-[var(--color-paper)] px-6 py-3.5 text-[11px] font-semibold uppercase tracking-[0.16em] text-[var(--color-primary)] transition hover:opacity-90">
                        Send an enquiry
                    </a>
                    @if ($whatsappUrl)
                        <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer" class="btn-light">WhatsApp us</a>
                    @endif
                </div>
            </div>
        </div>
    </section>
@endsection

@extends('layouts.public')

@section('content')
    <section class="border-b border-[var(--color-line)] bg-[var(--color-paper-deep)]">
        <div class="site-container py-14 sm:py-20">
            <nav class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]" aria-label="Breadcrumb">
                <a href="{{ route('home') }}" class="transition hover:text-[var(--color-primary)]">Home</a>
                <span class="mx-2 text-[var(--color-line)]" aria-hidden="true">/</span>
                <span class="text-[var(--color-primary)]" aria-current="page">Location</span>
            </nav>

            <div class="mt-8 grid gap-6 lg:grid-cols-[1.2fr_0.8fr] lg:items-end">
                <x-site.section-heading
                    as="h1"
                    eyebrow="Find us"
                    title="A quiet corner, close to it all."
                    description="Use the map for the exact location. We will send detailed arrival notes once your stay is confirmed."
                />
                <div class="text-sm leading-7 text-[var(--color-muted)] lg:pb-2">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Address</p>
                    <p class="mt-2">{{ $profile?->address ?: 'Shared with confirmed guests.' }}</p>
                </div>
            </div>
        </div>
    </section>

    <section class="py-10 sm:py-14">
        <div class="site-container">
            <div class="overflow-hidden rounded-2xl border border-[var(--color-line)] bg-[#E7E9DC]">
                @if ($profile?->lat && $profile?->lng)
                    <iframe
                        title="Map showing {{ $profile->name }}"
                        src="https://www.google.com/maps?q={{ $profile->lat }},{{ $profile->lng }}&z=15&output=embed"
                        class="h-[22rem] w-full border-0 sm:h-[30rem]"
                        loading="lazy"
                        referrerpolicy="no-referrer-when-downgrade"
                        allowfullscreen
                    ></iframe>
                @else
                    <div class="flex h-[22rem] flex-col items-center justify-center p-8 text-center sm:h-[30rem]">
                        <svg class="h-14 w-14 text-[var(--color-primary)]/40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 21s7-5.6 7-11a7 7 0 10-14 0c0 5.4 7 11 7 11Z" />
                            <circle cx="12" cy="10" r="2.5" />
                        </svg>
                        <p class="mt-4 font-serif text-2xl">Directions shared on request</p>
                    </div>
                @endif
            </div>
        </div>
    </section>

    <section class="py-10 sm:py-16">
        <div class="site-container grid gap-12 lg:grid-cols-[1fr_1fr]">
            <div>
                <p class="eyebrow">Arrival</p>
                <h2 class="mt-3 font-serif text-3xl sm:text-4xl">Getting here.</h2>

                <div class="prose-site mt-5">
                    @if ($page?->body)
                        {!! $page->body !!}
                    @else
                        <p>We are happy to arrange an airport transfer or a private driver for your arrival. Just let us know your flight details before you travel.</p>
                        <p>Parking is available on site. If you are driving yourself, we will share the final directions and a pin for the entrance once your booking is confirmed.</p>
                    @endif
                </div>

                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="{{ route('contact') }}" class="btn-primary">Arrange a transfer</a>
                    @if ($whatsappUrl)
                        <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer" class="btn-outline">Ask on WhatsApp</a>
                    @endif
                </div>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div class="rounded-2xl border border-[var(--color-line)] bg-[#FCFAF4] p-6">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Check-in</p>
                    <p class="mt-2 font-serif text-3xl">From {{ \Carbon\Carbon::parse($profile?->checkin_time ?? '14:00')->format('H:i') }}</p>
                    <p class="mt-2 text-xs leading-5 text-[var(--color-muted)]">Tell us your arrival time so someone can welcome you.</p>
                </div>

                <div class="rounded-2xl border border-[var(--color-line)] bg-[#FCFAF4] p-6">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Check-out</p>
                    <p class="mt-2 font-serif text-3xl">By {{ \Carbon\Carbon::parse($profile?->checkout_time ?? '12:00')->format('H:i') }}</p>
                    <p class="mt-2 text-xs leading-5 text-[var(--color-muted)]">Late check-out can sometimes be arranged. Just ask.</p>
                </div>

                <div class="rounded-2xl bg-[var(--color-primary)] p-6 text-[var(--color-paper)] sm:col-span-2">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-white/60">Contact</p>
                    <div class="mt-3 space-y-2 text-sm">
                        @if ($profile?->email)
                            <p><a href="mailto:{{ $profile->email }}" class="transition hover:text-white">{{ $profile->email }}</a></p>
                        @endif
                        @if ($profile?->whatsapp)
                            <p><a href="tel:{{ $profile->whatsapp }}" class="transition hover:text-white">{{ $profile->whatsapp }}</a></p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

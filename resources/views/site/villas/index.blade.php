@extends('layouts.public')

@section('content')
    <section class="border-b border-[var(--color-line)] bg-[var(--color-paper-deep)]">
        <div class="site-container py-14 sm:py-20">
            <nav class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]" aria-label="Breadcrumb">
                <a href="{{ route('home') }}" class="transition hover:text-[var(--color-primary)]">Home</a>
                <span class="mx-2 text-[var(--color-line)]">/</span>
                <span class="text-[var(--color-primary)]">Villas</span>
            </nav>

            <div class="mt-8 grid gap-6 lg:grid-cols-[1.2fr_0.8fr] lg:items-end">
                <x-site.section-heading
                    eyebrow="Our villas"
                    title="Room to settle in."
                    description="Choose the villa that fits your group. Each one is looked after by the same small team, so the welcome is always personal."
                />
                <p class="text-sm leading-7 text-[var(--color-muted)] lg:pb-2">
                    Rates shown are the lowest nightly rate currently available. Send us your dates for an exact total and availability.
                </p>
            </div>
        </div>
    </section>

    <section class="py-14 sm:py-20">
        <div class="site-container">
            @if ($villas->isNotEmpty())
                <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                    @foreach ($villas as $villa)
                        <x-site.villa-card :villa="$villa" :price="$prices[$villa->id] ?? null" />
                    @endforeach
                </div>
            @else
                <div class="rounded-2xl border border-[var(--color-line)] bg-[#FCFAF4] p-8 sm:p-12">
                    <p class="font-serif text-3xl">Our villas are being prepared.</p>
                    <p class="mt-3 max-w-xl text-sm leading-7 text-[var(--color-muted)]">
                        We are updating the details for this season. Send us a message and we will tell you what is available.
                    </p>
                    <a href="{{ route('contact') }}" class="btn-primary mt-6">Contact us</a>
                </div>
            @endif

            <div class="mt-14 flex flex-col items-start justify-between gap-6 rounded-2xl bg-[var(--color-primary)] px-6 py-8 text-[var(--color-paper)] sm:flex-row sm:items-center sm:px-10">
                <div>
                    <p class="font-serif text-2xl sm:text-3xl">Not sure which one to pick?</p>
                    <p class="mt-2 text-sm text-white/70">Tell us how many people are coming and what matters most.</p>
                </div>
                <a href="{{ route('contact') }}" class="inline-flex shrink-0 items-center justify-center rounded-full bg-[var(--color-paper)] px-6 py-3.5 text-[11px] font-semibold uppercase tracking-[0.16em] text-[var(--color-primary)] transition hover:opacity-90">
                    Ask us
                </a>
            </div>
        </div>
    </section>
@endsection

@extends('layouts.public')

@section('content')
    @php
        $formatDate = fn (string $date) => \Carbon\CarbonImmutable::parse($date)->format('j M Y');
        $nights = $room?->nights->count() ?? 0;
        $guestSummary = $reservation->adults.' '.\Illuminate\Support\Str::plural('adult', $reservation->adults)
            .($reservation->children > 0 ? ', '.$reservation->children.' '.\Illuminate\Support\Str::plural('child', $reservation->children) : '');
    @endphp

    <section class="border-b border-[var(--color-line)] bg-[var(--color-paper-deep)]">
        <div class="site-container py-12 sm:py-16">
            <p class="eyebrow">Request received</p>
            <h1 class="mt-3 max-w-2xl text-balance font-serif text-4xl leading-[1.05] sm:text-5xl">Thank you — your request is on its way.</h1>

            <div class="mt-6 max-w-2xl rounded-2xl border border-[#9CB58B] bg-[#EDF3E6] px-5 py-4 text-sm leading-6 text-[#35502A]" role="status">
                This is a booking request, <strong>not a confirmed booking</strong> yet. We will check availability and reply
                to the email address you gave us, usually within {{ $responseHours }} hours.
            </div>
        </div>
    </section>

    <section class="py-10 sm:py-16">
        <div class="site-container grid gap-12 lg:grid-cols-[1.2fr_0.9fr] lg:items-start">
            <div class="space-y-6">
                <div class="rounded-2xl border border-[var(--color-line)] bg-[#FCFAF4] p-6 sm:p-7">
                    <p class="eyebrow">Your reference</p>
                    <p class="mt-3 font-mono text-2xl tracking-[0.14em] text-[var(--color-primary)]">{{ $reservation->code }}</p>
                    <p class="mt-3 text-xs leading-5 text-[var(--color-muted)]">
                        Keep this code. You will need it when we contact you about your stay.
                    </p>
                    <a href="{{ route('booking.status', ['code' => $reservation->code]) }}" class="btn-outline mt-5">
                        Check booking status
                    </a>
                </div>

                <div class="rounded-2xl border border-[var(--color-line)] bg-[#FCFAF4] p-6 sm:p-7">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="eyebrow">Your stay</p>
                            <p class="mt-2 font-serif text-2xl leading-tight">{{ $room?->roomType?->name ?? 'Your villa' }}</p>
                        </div>
                        <span class="shrink-0 rounded-full bg-[var(--color-paper-deep)] px-3 py-1.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-[var(--color-primary)]">
                            {{ $reservation->status->label() }}
                        </span>
                    </div>

                    <div class="mt-5 grid grid-cols-2 gap-4 border-y border-[var(--color-line)] py-4 text-sm">
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Check-in</p>
                            <p class="mt-1 font-medium">{{ $formatDate($reservation->check_in->toDateString()) }}</p>
                            <p class="mt-0.5 text-xs text-[var(--color-muted)]">From {{ \Carbon\Carbon::parse($profile?->checkin_time ?? '14:00')->format('H:i') }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Check-out</p>
                            <p class="mt-1 font-medium">{{ $formatDate($reservation->check_out->toDateString()) }}</p>
                            <p class="mt-0.5 text-xs text-[var(--color-muted)]">By {{ \Carbon\Carbon::parse($profile?->checkout_time ?? '12:00')->format('H:i') }}</p>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm">
                        <p class="text-[var(--color-muted)]">{{ $guestSummary }} · {{ $nights }} {{ \Illuminate\Support\Str::plural('night', $nights) }}</p>
                        <p class="font-semibold">{{ \App\Support\Money::format($reservation->total, $reservation->currency) }}</p>
                    </div>
                </div>

                <div class="rounded-2xl border border-[var(--color-line)] p-6 sm:p-7">
                    <p class="eyebrow">What happens next</p>
                    <ol class="mt-5 space-y-4">
                        <li class="flex gap-4">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[var(--color-paper-deep)] text-xs font-semibold text-[var(--color-primary)]">1</span>
                            <p class="text-sm leading-6 text-[var(--color-muted)]">We check that your dates are still available.</p>
                        </li>
                        <li class="flex gap-4">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[var(--color-paper-deep)] text-xs font-semibold text-[var(--color-primary)]">2</span>
                            <p class="text-sm leading-6 text-[var(--color-muted)]">If they are, we send you the total and payment instructions by email.</p>
                        </li>
                        <li class="flex gap-4">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[var(--color-paper-deep)] text-xs font-semibold text-[var(--color-primary)]">3</span>
                            <p class="text-sm leading-6 text-[var(--color-muted)]">Your booking is confirmed only after we record your payment. The stay is non-refundable.</p>
                        </li>
                    </ol>
                </div>
            </div>

            <aside class="space-y-5 lg:sticky lg:top-24">
                <div class="rounded-2xl bg-[var(--color-primary)] p-6 text-[var(--color-paper)]">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-white/60">Need to change something?</p>
                    <p class="mt-4 text-sm leading-6 text-white/80">
                        Reply to our email or send us a message on WhatsApp. Please include your reference code.
                    </p>
                    <div class="mt-5 flex flex-col gap-3">
                        @if ($whatsappUrl)
                            <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer" class="btn-light">Message us on WhatsApp</a>
                        @endif
                        <a href="{{ route('home') }}" class="btn-light">Back to home</a>
                    </div>
                </div>

                <div class="rounded-2xl border border-[var(--color-line)] bg-[#FCFAF4] p-6">
                    <p class="eyebrow">Good to know</p>
                    <ul class="mt-4 space-y-3 text-sm leading-6 text-[var(--color-muted)]">
                        <li>No payment has been taken.</li>
                        <li>Your dates are not held until we confirm availability.</li>
                        <li>We reply to the email address you provided.</li>
                    </ul>
                </div>
            </aside>
        </div>
    </section>
@endsection

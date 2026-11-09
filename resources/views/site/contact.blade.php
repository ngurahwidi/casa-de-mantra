@extends('layouts.public')

@section('content')
    <section class="border-b border-[var(--color-line)] bg-[var(--color-paper-deep)]">
        <div class="site-container py-14 sm:py-20">
            <nav class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]" aria-label="Breadcrumb">
                <a href="{{ route('home') }}" class="transition hover:text-[var(--color-primary)]">Home</a>
                <span class="mx-2 text-[var(--color-line)]" aria-hidden="true">/</span>
                <span class="text-[var(--color-primary)]" aria-current="page">Contact</span>
            </nav>

            <div class="mt-8 grid gap-6 lg:grid-cols-[1.2fr_0.8fr] lg:items-end">
                <x-site.section-heading
                    as="h1"
                    eyebrow="Say hello"
                    title="Tell us about your stay."
                    description="Share your dates, the size of your group, and anything you would like to know. We usually reply within one working day."
                />
                @if ($whatsappUrl)
                    <div class="lg:pb-2 lg:text-right">
                        <p class="text-sm text-[var(--color-muted)]">Prefer a quick chat?</p>
                        <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer" class="btn-primary mt-3">Message us on WhatsApp</a>
                    </div>
                @endif
            </div>
        </div>
    </section>

    <section class="py-14 sm:py-20">
        <div class="site-container grid gap-12 lg:grid-cols-[1.2fr_0.8fr] lg:items-start">
            <div>
                @if (session('status'))
                    <div class="mb-6 rounded-2xl border border-[#9CB58B] bg-[#EDF3E6] px-5 py-4 text-sm leading-6 text-[#35502A]" role="status">
                        {{ session('status') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="mb-6 rounded-2xl border border-[#D9A7A0] bg-[#FBEDEA] px-5 py-4 text-sm leading-6 text-[#7A2F25]" role="alert">
                        Please check the highlighted fields and try again.
                    </div>
                @endif

                <form method="POST" action="{{ route('contact.store') }}" class="space-y-5">
                    @csrf

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="name" class="block text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Your name</label>
                            <input
                                id="name"
                                name="name"
                                @error('name') aria-invalid="true" aria-describedby="name-error" @enderror
                                type="text"
                                value="{{ old('name') }}"
                                autocomplete="name"
                                required
                                @class([
                                    'mt-2 w-full rounded-xl border bg-[#FCFAF4] px-4 py-3 text-base sm:text-sm outline-none transition placeholder:text-[var(--color-muted)] focus:border-[var(--color-primary)]',
                                    'border-[#C98B82]' => $errors->has('name'),
                                    'border-[var(--color-line)]' => ! $errors->has('name'),
                                ])
                                placeholder="Your full name"
                            >
                            @error('name')
                                <p id="name-error" class="mt-1.5 text-xs text-[#7A2F25]">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="email" class="block text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Email address</label>
                            <input
                                id="email"
                                name="email"
                                @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
                                type="email"
                                value="{{ old('email') }}"
                                autocomplete="email"
                                required
                                @class([
                                    'mt-2 w-full rounded-xl border bg-[#FCFAF4] px-4 py-3 text-base sm:text-sm outline-none transition placeholder:text-[var(--color-muted)] focus:border-[var(--color-primary)]',
                                    'border-[#C98B82]' => $errors->has('email'),
                                    'border-[var(--color-line)]' => ! $errors->has('email'),
                                ])
                                placeholder="you@example.com"
                            >
                            @error('email')
                                <p id="email-error" class="mt-1.5 text-xs text-[#7A2F25]">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="phone" class="block text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Phone or WhatsApp <span class="normal-case tracking-normal text-[var(--color-muted)]">(optional)</span></label>
                            <input
                                id="phone"
                                name="phone"
                                @error('phone') aria-invalid="true" aria-describedby="phone-error" @enderror
                                type="text"
                                value="{{ old('phone') }}"
                                autocomplete="tel"
                                @class([
                                    'mt-2 w-full rounded-xl border bg-[#FCFAF4] px-4 py-3 text-base sm:text-sm outline-none transition placeholder:text-[var(--color-muted)] focus:border-[var(--color-primary)]',
                                    'border-[#C98B82]' => $errors->has('phone'),
                                    'border-[var(--color-line)]' => ! $errors->has('phone'),
                                ])
                                placeholder="+62 ..."
                            >
                            @error('phone')
                                <p id="phone-error" class="mt-1.5 text-xs text-[#7A2F25]">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="villa" class="block text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Villa <span class="normal-case tracking-normal text-[var(--color-muted)]">(optional)</span></label>
                            <input
                                id="villa"
                                name="villa"
                                @error('villa') aria-invalid="true" aria-describedby="villa-error" @enderror
                                type="text"
                                value="{{ old('villa', request('villa')) }}"
                                @class([
                                    'mt-2 w-full rounded-xl border bg-[#FCFAF4] px-4 py-3 text-base sm:text-sm outline-none transition placeholder:text-[var(--color-muted)] focus:border-[var(--color-primary)]',
                                    'border-[#C98B82]' => $errors->has('villa'),
                                    'border-[var(--color-line)]' => ! $errors->has('villa'),
                                ])
                                placeholder="Which villa are you interested in?"
                            >
                            @error('villa')
                                <p id="villa-error" class="mt-1.5 text-xs text-[#7A2F25]">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div>
                        <label for="message" class="block text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Your message</label>
                        <textarea
                            id="message"
                            name="message"
                            @error('message') aria-invalid="true" aria-describedby="message-error" @enderror
                            rows="6"
                            required
                            @class([
                                'mt-2 w-full resize-y rounded-xl border bg-[#FCFAF4] px-4 py-3 text-base sm:text-sm leading-6 outline-none transition placeholder:text-[var(--color-muted)] focus:border-[var(--color-primary)]',
                                'border-[#C98B82]' => $errors->has('message'),
                                'border-[var(--color-line)]' => ! $errors->has('message'),
                            ])
                            placeholder="Dates, number of guests, and anything else we should know..."
                        >{{ old('message') }}</textarea>
                        @error('message')
                            <p id="message-error" class="mt-1.5 text-xs text-[#7A2F25]">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex flex-col gap-4 border-t border-[var(--color-line)] pt-5 sm:flex-row sm:items-center sm:justify-between">
                        <p class="max-w-sm text-[11px] leading-5 text-[var(--color-muted)]">
                            By sending this form you agree that we may reply to the email address you provide.
                        </p>
                        <button type="submit" class="btn-primary shrink-0">Send enquiry</button>
                    </div>
                </form>
            </div>

            <aside class="space-y-5">
                <div class="rounded-2xl border border-[var(--color-line)] bg-[#FCFAF4] p-6">
                    <p class="eyebrow">Reach us directly</p>
                    <div class="mt-5 space-y-4 text-sm">
                        @if ($profile?->email)
                            <div>
                                <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Email</p>
                                <a href="mailto:{{ $profile->email }}" class="mt-1 block font-medium underline decoration-[var(--color-line)] underline-offset-4 transition hover:text-[var(--color-primary)]">{{ $profile->email }}</a>
                            </div>
                        @endif
                        @if ($profile?->whatsapp)
                            <div>
                                <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Phone / WhatsApp</p>
                                <a href="tel:{{ $profile->whatsapp }}" class="mt-1 block font-medium underline decoration-[var(--color-line)] underline-offset-4 transition hover:text-[var(--color-primary)]">{{ $profile->whatsapp }}</a>
                            </div>
                        @endif
                        @if ($profile?->address)
                            <div>
                                <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Address</p>
                                <p class="mt-1 leading-6">{{ $profile->address }}</p>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="rounded-2xl bg-[var(--color-primary)] p-6 text-[var(--color-paper)]">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-white/60">Good to know</p>
                    <ul class="mt-4 space-y-3 text-sm leading-6 text-white/80">
                        <li>We reply within one working day.</li>
                        <li>Check-in from {{ \Carbon\Carbon::parse($profile?->checkin_time ?? '14:00')->format('H:i') }}, check-out by {{ \Carbon\Carbon::parse($profile?->checkout_time ?? '12:00')->format('H:i') }}.</li>
                        <li>No payment is taken until availability is confirmed.</li>
                    </ul>
                </div>
            </aside>
        </div>
    </section>
@endsection

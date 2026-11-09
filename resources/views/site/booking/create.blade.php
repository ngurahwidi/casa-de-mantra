@extends('layouts.public')

@section('content')
    @php
        $currency = $profile?->currency;
        $formatDate = fn (string $date) => \Carbon\CarbonImmutable::parse($date)->format('j M Y');
        $nightsLabel = fn (int $nights) => $nights.' '.\Illuminate\Support\Str::plural('night', $nights);
    @endphp

    <section class="border-b border-[var(--color-line)] bg-[var(--color-paper-deep)]">
        <div class="site-container py-8 sm:py-10">
            <nav class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]" aria-label="Breadcrumb">
                <a href="{{ route('home') }}" class="transition hover:text-[var(--color-primary)]">Home</a>
                <span class="mx-2 text-[var(--color-line)]" aria-hidden="true">/</span>
                <a href="{{ route('villas.index') }}" class="transition hover:text-[var(--color-primary)]">Villas</a>
                <span class="mx-2 text-[var(--color-line)]" aria-hidden="true">/</span>
                <a href="{{ route('villas.show', $villa->slug) }}" class="transition hover:text-[var(--color-primary)]">{{ $villa->name }}</a>
                <span class="mx-2 text-[var(--color-line)]" aria-hidden="true">/</span>
                <span class="text-[var(--color-primary)]" aria-current="page">Request to book</span>
            </nav>

            <div class="mt-6 max-w-2xl">
                <p class="eyebrow">Direct booking</p>
                <h1 class="mt-3 font-serif text-4xl leading-[1.02] sm:text-5xl">Request to book</h1>
                <p class="mt-4 text-sm leading-7 text-[var(--color-muted)]">
                    Send your details and we will check availability for {{ $villa->name }}. No payment is taken online,
                    and your dates are not held until we confirm them with you.
                </p>
            </div>
        </div>
    </section>

    <section class="py-10 sm:py-16">
        <div @class([
            'site-container grid gap-12 lg:items-start',
            'lg:grid-cols-[1.3fr_0.9fr]' => $result !== null && $result->isAvailable,
        ])>
            <div>
                @if ($errors->has('stay') && ($result === null || $result->isAvailable))
                    <div class="mb-6 rounded-2xl border border-[#D9A7A0] bg-[#FBEDEA] px-5 py-4 text-sm leading-6 text-[#7A2F25]" role="alert">
                        {{ $errors->first('stay') }}
                    </div>
                @elseif ($errors->any() && ! $errors->has('stay'))
                    <div class="mb-6 rounded-2xl border border-[#D9A7A0] bg-[#FBEDEA] px-5 py-4 text-sm leading-6 text-[#7A2F25]" role="alert">
                        Please check the highlighted fields and try again.
                    </div>
                @endif

                @if ($result === null)
                    <div class="rounded-2xl border border-[var(--color-line)] bg-[#FCFAF4] p-6 sm:p-8">
                        <p class="eyebrow">Start with your dates</p>
                        <p class="mt-3 text-sm leading-7 text-[var(--color-muted)]">
                            Choose your check-in and check-out dates on the villa page to see the price and send a request.
                        </p>
                        <a href="{{ route('villas.show', $villa->slug) }}" class="btn-primary mt-6">Back to {{ $villa->name }}</a>
                    </div>
                @elseif (! $result->isAvailable)
                    <div class="rounded-2xl border border-[#E4C9C3] bg-[#F8EDEA] p-6 sm:p-8">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[#7A3B30]">Dates unavailable</p>
                        <p class="mt-3 text-sm leading-7 text-[#7A3B30]">{{ $result->message }}</p>

                        @if ($result->alternative)
                            <p class="mt-5 text-sm leading-7 text-[var(--color-muted)]">
                                The nearest available stay is
                                <strong class="text-[var(--color-ink)]">{{ $formatDate($result->alternative->checkIn) }} – {{ $formatDate($result->alternative->checkOut) }}</strong>
                                for {{ \App\Support\Money::format($result->alternative->total, $currency) }}.
                            </p>
                        @endif

                        <div class="mt-6 flex flex-col gap-3 sm:flex-row">
                            @if ($result->alternative)
                                <a href="{{ route('villas.show', [
                                    'slug' => $villa->slug,
                                    'check_in' => $result->alternative->checkIn,
                                    'check_out' => $result->alternative->checkOut,
                                    'adults' => $adults,
                                    'children' => $children,
                                ]) }}" class="btn-primary">
                                    Check these dates
                                </a>
                            @else
                                <a href="{{ route('villas.show', $villa->slug) }}" class="btn-primary">Choose other dates</a>
                            @endif

                            @if ($whatsappUrl)
                                <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer" class="btn-outline">Ask on WhatsApp</a>
                            @endif
                        </div>
                    </div>
                @else
                    @php
                        $quote = $result->quote;
                        $policy = $result->policy;
                        $priceGroups = collect($quote->nights)->groupBy('price');
                        $guestSummary = $adults.' '.\Illuminate\Support\Str::plural('adult', $adults)
                            .($children > 0 ? ', '.$children.' '.\Illuminate\Support\Str::plural('child', $children) : '');
                    @endphp

                    <div class="rounded-2xl border border-[var(--color-line)] bg-[#FCFAF4] p-6 sm:p-7">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="eyebrow">1 · Your stay</p>
                                <p class="mt-2 font-serif text-2xl leading-tight">{{ $villa->name }}</p>
                            </div>
                            <a href="{{ route('villas.show', [
                                'slug' => $villa->slug,
                                'check_in' => $quote->checkIn,
                                'check_out' => $quote->checkOut,
                                'adults' => $adults,
                                'children' => $children,
                            ]) }}" class="shrink-0 text-[10px] font-semibold uppercase tracking-[0.14em] text-[var(--color-primary)] underline-offset-4 hover:underline">
                                Change
                            </a>
                        </div>

                        <div class="mt-5 grid grid-cols-2 gap-4 border-y border-[var(--color-line)] py-4 text-sm">
                            <div>
                                <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Check-in</p>
                                <p class="mt-1 font-medium">{{ $formatDate($quote->checkIn) }}</p>
                                <p class="mt-0.5 text-xs text-[var(--color-muted)]">From {{ \Carbon\Carbon::parse($profile?->checkin_time ?? '14:00')->format('H:i') }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Check-out</p>
                                <p class="mt-1 font-medium">{{ $formatDate($quote->checkOut) }}</p>
                                <p class="mt-0.5 text-xs text-[var(--color-muted)]">By {{ \Carbon\Carbon::parse($profile?->checkout_time ?? '12:00')->format('H:i') }}</p>
                            </div>
                        </div>

                        <p class="mt-4 text-sm text-[var(--color-muted)]">{{ $guestSummary }} · {{ $nightsLabel($quote->nightCount()) }}</p>
                    </div>

                    <form method="POST" action="{{ route('booking.store', ['villa' => $villa->slug]) }}" class="mt-8 space-y-8">
                        @csrf

                        <input type="hidden" name="check_in" value="{{ $quote->checkIn }}">
                        <input type="hidden" name="check_out" value="{{ $quote->checkOut }}">
                        <input type="hidden" name="adults" value="{{ $adults }}">
                        <input type="hidden" name="children" value="{{ $children }}">

                        <div class="hidden" aria-hidden="true">
                            <label for="website">Website</label>
                            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                        </div>

                        <fieldset>
                            <legend class="eyebrow">2 · Your details</legend>

                            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                                <div>
                                    <label for="full_name" class="block text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Full name</label>
                                    <input
                                        id="full_name"
                                        name="full_name"
                                        @error('full_name') aria-invalid="true" aria-describedby="full_name-error" @enderror
                                        type="text"
                                        value="{{ old('full_name') }}"
                                        autocomplete="name"
                                        required
                                        @class([
                                            'mt-2 w-full rounded-xl border bg-[#FCFAF4] px-4 py-3 text-base sm:text-sm outline-none transition placeholder:text-[var(--color-muted)] focus:border-[var(--color-primary)]',
                                            'border-[#C98B82]' => $errors->has('full_name'),
                                            'border-[var(--color-line)]' => ! $errors->has('full_name'),
                                        ])
                                        placeholder="Your full name"
                                    >
                                    @error('full_name')
                                        <p id="full_name-error" class="mt-1.5 text-xs text-[#7A2F25]">{{ $message }}</p>
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

                            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                                <div>
                                    <label for="phone" class="block text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Phone or WhatsApp</label>
                                    <input
                                        id="phone"
                                        name="phone"
                                        @error('phone') aria-invalid="true" aria-describedby="phone-error" @enderror
                                        type="text"
                                        value="{{ old('phone') }}"
                                        autocomplete="tel"
                                        required
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
                                    <label for="country" class="block text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Country <span class="normal-case tracking-normal text-[var(--color-muted)]">(optional)</span></label>
                                    <input
                                        id="country"
                                        name="country"
                                        @error('country') aria-invalid="true" aria-describedby="country-error" @enderror
                                        type="text"
                                        value="{{ old('country') }}"
                                        autocomplete="country-name"
                                        @class([
                                            'mt-2 w-full rounded-xl border bg-[#FCFAF4] px-4 py-3 text-base sm:text-sm outline-none transition placeholder:text-[var(--color-muted)] focus:border-[var(--color-primary)]',
                                            'border-[#C98B82]' => $errors->has('country'),
                                            'border-[var(--color-line)]' => ! $errors->has('country'),
                                        ])
                                        placeholder="Where are you travelling from?"
                                    >
                                    @error('country')
                                        <p id="country-error" class="mt-1.5 text-xs text-[#7A2F25]">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <div class="mt-5">
                                <label for="special_requests" class="block text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Special requests <span class="normal-case tracking-normal text-[var(--color-muted)]">(optional)</span></label>
                                <textarea
                                    id="special_requests"
                                    name="special_requests"
                                    @error('special_requests') aria-invalid="true" aria-describedby="special_requests-error" @enderror
                                    rows="4"
                                    @class([
                                        'mt-2 w-full resize-y rounded-xl border bg-[#FCFAF4] px-4 py-3 text-base sm:text-sm leading-6 outline-none transition placeholder:text-[var(--color-muted)] focus:border-[var(--color-primary)]',
                                        'border-[#C98B82]' => $errors->has('special_requests'),
                                        'border-[var(--color-line)]' => ! $errors->has('special_requests'),
                                    ])
                                    placeholder="Arrival time, dietary needs, or anything else we should know..."
                                >{{ old('special_requests') }}</textarea>
                                @error('special_requests')
                                    <p id="special_requests-error" class="mt-1.5 text-xs text-[#7A2F25]">{{ $message }}</p>
                                @enderror
                            </div>
                        </fieldset>

                        <fieldset class="border-t border-[var(--color-line)] pt-7">
                            <legend class="eyebrow">3 · Before you send</legend>

                            <label for="policy_accepted" class="mt-5 flex cursor-pointer items-start gap-3 rounded-xl border border-[var(--color-line)] bg-[var(--color-paper-deep)] p-4">
                                <input
                                    id="policy_accepted"
                                    name="policy_accepted"
                                    @error('policy_accepted') aria-invalid="true" aria-describedby="policy_accepted-error" @enderror
                                    type="checkbox"
                                    value="1"
                                    @checked(old('policy_accepted'))
                                    required
                                    class="mt-0.5 h-4 w-4 shrink-0 accent-[var(--color-primary)]"
                                >
                                <span class="text-sm leading-6">
                                    I understand that this is a <strong>non-refundable</strong> stay and that my dates are not confirmed until the villa replies.
                                    @if ($policy?->description)
                                        <span class="mt-1 block text-xs leading-5 text-[var(--color-muted)]">{{ $policy->description }}</span>
                                    @endif
                                </span>
                            </label>
                            @error('policy_accepted')
                                <p id="policy_accepted-error" class="mt-1.5 text-xs text-[#7A2F25]">{{ $message }}</p>
                            @enderror

                            @if (config('services.turnstile.site_key'))
                                <div class="mt-5">
                                    <div class="cf-turnstile" data-sitekey="{{ config('services.turnstile.site_key') }}" data-theme="light"></div>
                                    @error('cf-turnstile-response')
                                        <p class="mt-1.5 text-xs text-[#7A2F25]">{{ $message }}</p>
                                    @enderror
                                </div>
                            @endif

                            <div class="mt-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                <p class="max-w-sm text-[11px] leading-5 text-[var(--color-muted)]">
                                    No payment is taken online. We will reply to the email address you provide.
                                </p>
                                <button type="submit" class="btn-primary shrink-0">Send request</button>
                            </div>
                        </fieldset>
                    </form>
                @endif
            </div>

            <aside class="space-y-5 lg:sticky lg:top-24">
                @if ($result !== null && $result->isAvailable)
                    @php
                        $quote = $result->quote;
                        $policy = $result->policy;
                        $priceGroups = collect($quote->nights)->groupBy('price');
                    @endphp

                    <div class="rounded-2xl border border-[var(--color-line)] bg-[#FCFAF4] p-6 shadow-sm">
                        <p class="eyebrow">Your price</p>

                        <div class="mt-5 space-y-3 border-b border-[var(--color-line)] pb-4 text-sm">
                            @foreach ($priceGroups as $price => $nights)
                                <div class="flex items-center justify-between gap-4">
                                    <span class="text-[var(--color-muted)]">{{ \App\Support\Money::format($price, $currency) }} × {{ $nightsLabel($nights->count()) }}</span>
                                    <span>{{ \App\Support\Money::format((float) $price * $nights->count(), $currency) }}</span>
                                </div>
                            @endforeach

                            @if ((float) $quote->taxTotal > 0)
                                <div class="flex items-center justify-between gap-4">
                                    <span class="text-[var(--color-muted)]">Taxes and fees</span>
                                    <span>{{ \App\Support\Money::format($quote->taxTotal, $currency) }}</span>
                                </div>
                            @endif

                            <div class="flex items-center justify-between gap-4 border-t border-[var(--color-line)] pt-3 font-semibold">
                                <span>Total · {{ $nightsLabel($quote->nightCount()) }}</span>
                                <span>{{ \App\Support\Money::format($quote->total, $currency) }}</span>
                            </div>
                        </div>

                        <p class="mt-4 text-[11px] leading-5 text-[var(--color-muted)]">
                            The rate is per night in {{ $currency }}. Nothing is charged now.
                        </p>
                    </div>

                    @if ($policy)
                        <div class="rounded-2xl border border-[var(--color-line)] bg-[var(--color-paper-deep)] p-6">
                            <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">{{ $policy->name }}</p>
                            @if ($policy->description)
                                <p class="mt-3 text-xs leading-6 text-[var(--color-muted)]">{{ $policy->description }}</p>
                            @endif
                        </div>
                    @endif

                    <div class="rounded-2xl bg-[var(--color-primary)] p-6 text-[var(--color-paper)]">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-white/60">Good to know</p>
                        <ul class="mt-4 space-y-3 text-sm leading-6 text-white/80">
                            <li>No payment is taken online.</li>
                            <li>Your dates are not held until we confirm availability.</li>
                            <li>We usually reply within {{ $profile?->pending_reminder_hours ?? 12 }} hours.</li>
                        </ul>
                    </div>
                @endif
            </aside>
        </div>
    </section>

    @push('scripts')
        @if (config('services.turnstile.site_key'))
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
        @endif
    @endpush
@endsection

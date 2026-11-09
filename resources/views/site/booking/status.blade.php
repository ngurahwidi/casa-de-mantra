@extends('layouts.public')

@section('content')
    @php
        $timezone = $profile?->timezone ?? 'UTC';
        $formatDate = fn (string $date) => \Carbon\CarbonImmutable::parse($date)->format('j M Y');
        $currency = $reservation?->currency ?? $profile?->currency ?? 'USD';
        $nights = $room?->nights->count() ?? 0;
        $deadline = $reservation?->expires_at?->copy()->timezone($timezone);
        $deadlinePassed = $deadline?->isPast() ?? false;
        $bank = $profile?->bank_account_info ?? [];
        $hasBankDetails = filled($bank['bank'] ?? null) || filled($bank['account_number'] ?? null);
        $status = $reservation?->status;
        $responseHours = $profile?->pending_reminder_hours ?? 12;

        $badgeTone = match ($status) {
            \App\Enums\ReservationStatus::Confirmed, \App\Enums\ReservationStatus::Completed => 'bg-[#EDF3E6] text-[#35502A]',
            \App\Enums\ReservationStatus::AwaitingPayment => 'bg-[#FBF0D8] text-[#7A5A1E]',
            \App\Enums\ReservationStatus::Rejected, \App\Enums\ReservationStatus::Cancelled, \App\Enums\ReservationStatus::Expired => 'bg-[#FBEDEA] text-[#7A2F25]',
            default => 'bg-[var(--color-paper-deep)] text-[var(--color-primary)]',
        };

        $steps = [
            ['label' => 'Request received', 'caption' => 'We have your stay details.'],
            ['label' => 'Awaiting payment', 'caption' => 'We send the amount and transfer instructions.'],
            ['label' => 'Confirmed', 'caption' => 'Your dates are held once we verify the payment.'],
            ['label' => 'Stay completed', 'caption' => 'We hope you enjoyed your stay.'],
        ];
        $currentStep = match ($status) {
            \App\Enums\ReservationStatus::Pending => 0,
            \App\Enums\ReservationStatus::AwaitingPayment => 1,
            \App\Enums\ReservationStatus::Confirmed => 2,
            \App\Enums\ReservationStatus::Completed => 3,
            default => null,
        };

        $guestSummary = $reservation === null
            ? ''
            : $reservation->adults.' '.\Illuminate\Support\Str::plural('adult', $reservation->adults)
                .($reservation->children > 0 ? ', '.$reservation->children.' '.\Illuminate\Support\Str::plural('child', $reservation->children) : '');
    @endphp

    <section class="border-b border-[var(--color-line)] bg-[var(--color-paper-deep)]">
        <div class="site-container py-12 sm:py-16">
            <p class="eyebrow">Manage your booking</p>
            <h1 class="mt-3 font-serif text-4xl leading-[1.05] sm:text-5xl">Booking status</h1>
            <p class="mt-4 max-w-2xl text-sm leading-7 text-[var(--color-muted)]">
                Enter the reference code from your request and the email address you used. You can come back here at any
                time to see what happens next.
            </p>
        </div>
    </section>

    <section class="py-10 sm:py-16">
        <div class="site-container">
            @if (session('status'))
                <div class="mb-8 max-w-3xl rounded-2xl border border-[#9CB58B] bg-[#EDF3E6] px-5 py-4 text-sm leading-6 text-[#35502A]" role="status">
                    {{ session('status') }}
                </div>
            @endif

            @if ($reservation === null)
                <div class="max-w-xl rounded-2xl border border-[var(--color-line)] bg-[#FCFAF4] p-6 sm:p-8">
                    <p class="eyebrow">Find your booking</p>

                    @if ($errors->has('lookup'))
                        <div class="mt-4 rounded-xl border border-[#D9A7A0] bg-[#FBEDEA] px-4 py-3 text-sm leading-6 text-[#7A2F25]" role="alert">
                            {{ $errors->first('lookup') }}
                        </div>
                    @endif

                    <form method="POST" action="{{ route('booking.status.lookup') }}" class="mt-6 space-y-5">
                        @csrf

                        <div>
                            <label for="code" class="block text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Reference code</label>
                            <input
                                id="code"
                                name="code"
                                type="text"
                                value="{{ old('code', $lookupCode) }}"
                                autocomplete="off"
                                autocapitalize="characters"
                                spellcheck="false"
                                maxlength="32"
                                required
                                @class([
                                    'mt-2 w-full rounded-xl border bg-[var(--color-paper)] px-4 py-3 font-mono text-sm uppercase tracking-[0.12em] outline-none transition placeholder:font-sans placeholder:normal-case placeholder:tracking-normal placeholder:text-[var(--color-muted)]/60 focus:border-[var(--color-primary)]',
                                    'border-[#C98B82]' => $errors->has('code'),
                                    'border-[var(--color-line)]' => ! $errors->has('code'),
                                ])
                                placeholder="RSV-XXXXXXXX"
                            >
                            @error('code')
                                <p class="mt-1.5 text-xs text-[#7A2F25]">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="email" class="block text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Email address</label>
                            <input
                                id="email"
                                name="email"
                                type="email"
                                value="{{ old('email') }}"
                                autocomplete="email"
                                required
                                @class([
                                    'mt-2 w-full rounded-xl border bg-[var(--color-paper)] px-4 py-3 text-sm outline-none transition placeholder:text-[var(--color-muted)]/60 focus:border-[var(--color-primary)]',
                                    'border-[#C98B82]' => $errors->has('email'),
                                    'border-[var(--color-line)]' => ! $errors->has('email'),
                                ])
                                placeholder="you@example.com"
                            >
                            @error('email')
                                <p class="mt-1.5 text-xs text-[#7A2F25]">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex flex-col gap-3 pt-1 sm:flex-row sm:items-center sm:justify-between">
                            <p class="max-w-xs text-[11px] leading-5 text-[var(--color-muted)]">
                                We only show a booking when both the code and email match.
                            </p>
                            <button type="submit" class="btn-primary shrink-0">Check status</button>
                        </div>
                    </form>
                </div>
            @else
                <div class="flex flex-col gap-5 border-b border-[var(--color-line)] pb-6 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="eyebrow">Reference</p>
                        <p class="mt-2 font-mono text-2xl tracking-[0.14em] text-[var(--color-primary)]">{{ $reservation->code }}</p>
                    </div>

                    <form method="POST" action="{{ route('booking.status.forget') }}">
                        @csrf
                        <button type="submit" class="text-[10px] font-semibold uppercase tracking-[0.16em] text-[var(--color-primary)] underline-offset-4 hover:underline">
                            Check another booking
                        </button>
                    </form>
                </div>

                <div class="mt-8 grid gap-10 lg:grid-cols-[1.25fr_0.85fr] lg:items-start">
                    <div class="space-y-6">
                        <div class="rounded-2xl border border-[var(--color-line)] bg-[#FCFAF4] p-6 sm:p-7">
                            <div class="flex flex-wrap items-center gap-3">
                                <span class="rounded-full px-3 py-1.5 text-[10px] font-semibold uppercase tracking-[0.14em] {{ $badgeTone }}">
                                    {{ $reservation->status->label() }}
                                </span>
                                <span class="text-xs text-[var(--color-muted)]">Requested {{ $reservation->created_at?->copy()->timezone($timezone)->format('j M Y') }}</span>
                            </div>

                            @if ($status === \App\Enums\ReservationStatus::Pending)
                                <h2 class="mt-5 font-serif text-3xl leading-tight">We are checking your dates</h2>
                                <p class="mt-3 text-sm leading-7 text-[var(--color-muted)]">
                                    Your request has reached us and our team is checking availability. We reply to
                                    <strong class="text-[var(--color-ink)]">{{ $reservation->guest_email }}</strong>
                                    within about {{ $responseHours }} hours. No payment is due yet.
                                </p>
                            @elseif ($status === \App\Enums\ReservationStatus::AwaitingPayment)
                                <h2 class="mt-5 font-serif text-3xl leading-tight">Your dates are being held</h2>
                                <p class="mt-3 text-sm leading-7 text-[var(--color-muted)]">
                                    We have set your dates aside. Send the transfer using the instructions below, then upload
                                    your receipt. Your booking is confirmed once we verify the payment.
                                </p>
                                @if ($deadlinePassed && ! $hasProofAwaitingVerification)
                                    <div class="mt-5 rounded-xl border border-[#E4C9C3] bg-[#F8EDEA] px-4 py-3 text-sm leading-6 text-[#7A3B30]" role="alert">
                                        The payment window has closed. If you have already paid, upload your proof and message us —
                                        we will check whether the dates are still available.
                                    </div>
                                @endif
                            @elseif ($status === \App\Enums\ReservationStatus::Confirmed)
                                <h2 class="mt-5 font-serif text-3xl leading-tight">Your booking is confirmed</h2>
                                <p class="mt-3 text-sm leading-7 text-[var(--color-muted)]">
                                    We look forward to welcoming you. Your dates are held and your payment has been recorded.
                                    Check-in is from {{ \Carbon\Carbon::parse($profile?->checkin_time ?? '14:00')->format('H:i') }};
                                    check-out is by {{ \Carbon\Carbon::parse($profile?->checkout_time ?? '12:00')->format('H:i') }}.
                                </p>
                                @if ($profile?->address)
                                    <p class="mt-3 text-sm leading-7 text-[var(--color-muted)]">
                                        Address: <strong class="text-[var(--color-ink)]">{{ $profile->address }}</strong>
                                    </p>
                                @endif
                                @if ($balance > 0)
                                    <p class="mt-4 rounded-xl border border-[var(--color-line)] bg-[var(--color-paper-deep)] px-4 py-3 text-sm leading-6">
                                        A balance of <strong>{{ \App\Support\Money::format($balance, $currency) }}</strong> is still outstanding.
                                        Message us to arrange the final transfer before arrival.
                                    </p>
                                @endif
                            @elseif ($status === \App\Enums\ReservationStatus::Completed)
                                <h2 class="mt-5 font-serif text-3xl leading-tight">Thank you for staying with us</h2>
                                <p class="mt-3 text-sm leading-7 text-[var(--color-muted)]">
                                    We hope you enjoyed your stay. If you would like to come back, send us a new request and we
                                    will take care of the rest.
                                </p>
                            @elseif ($status === \App\Enums\ReservationStatus::Rejected)
                                <h2 class="mt-5 font-serif text-3xl leading-tight">We could not confirm this request</h2>
                                <p class="mt-3 text-sm leading-7 text-[var(--color-muted)]">
                                    We were not able to confirm these dates. You are welcome to choose other dates or message us
                                    and we will suggest the closest available stay.
                                </p>
                                @if ($reservation->reject_reason)
                                    <p class="mt-3 rounded-xl border border-[var(--color-line)] bg-[var(--color-paper-deep)] px-4 py-3 text-sm leading-6">
                                        {{ $reservation->reject_reason }}
                                    </p>
                                @endif
                            @elseif ($status === \App\Enums\ReservationStatus::Cancelled)
                                <h2 class="mt-5 font-serif text-3xl leading-tight">This booking was cancelled</h2>
                                <p class="mt-3 text-sm leading-7 text-[var(--color-muted)]">
                                    This stay was cancelled. The rate is non-refundable, so no refund is issued automatically.
                                    If you think this is a mistake, message us and we will look into it.
                                </p>
                            @elseif ($status === \App\Enums\ReservationStatus::Expired)
                                <h2 class="mt-5 font-serif text-3xl leading-tight">The payment window has passed</h2>
                                <p class="mt-3 text-sm leading-7 text-[var(--color-muted)]">
                                    We did not receive the payment in time, so this request expired and the dates were
                                    released. You can send a new request for these or other dates.
                                </p>
                            @endif

                            <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                                @if (in_array($status, [\App\Enums\ReservationStatus::Rejected, \App\Enums\ReservationStatus::Cancelled, \App\Enums\ReservationStatus::Expired], true))
                                    @if ($room?->roomType?->is_published)
                                        <a href="{{ route('villas.show', $room->roomType->slug) }}" class="btn-primary">View this villa</a>
                                    @else
                                        <a href="{{ route('villas.index') }}" class="btn-primary">View villas</a>
                                    @endif
                                @elseif ($status === \App\Enums\ReservationStatus::Completed)
                                    <a href="{{ route('villas.index') }}" class="btn-primary">Book another stay</a>
                                @endif

                                @if ($whatsappUrl)
                                    <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer" class="btn-outline">Ask on WhatsApp</a>
                                @endif
                            </div>
                        </div>

                        @if ($status === \App\Enums\ReservationStatus::AwaitingPayment && $balance > 0)
                            <div class="rounded-2xl border border-[var(--color-line)] bg-[#FCFAF4] p-6 sm:p-7">
                                <p class="eyebrow">Transfer instructions</p>
                                <p class="mt-3 text-sm leading-7 text-[var(--color-muted)]">
                                    Amount due <strong class="text-[var(--color-ink)]">{{ \App\Support\Money::format($balance, $currency) }}</strong>
                                    @if ($deadline)
                                        — please pay by <strong class="text-[var(--color-ink)]">{{ $deadline->format('j M Y, H:i T') }}</strong>.
                                    @else
                                        .
                                    @endif
                                </p>

                                @if ($hasBankDetails)
                                    <dl class="mt-5 grid gap-4 border-y border-[var(--color-line)] py-4 text-sm sm:grid-cols-2">
                                        @if (filled($bank['bank'] ?? null))
                                            <div>
                                                <dt class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Bank</dt>
                                                <dd class="mt-1">{{ $bank['bank'] }}</dd>
                                            </div>
                                        @endif
                                        @if (filled($bank['account_name'] ?? null))
                                            <div>
                                                <dt class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Account name</dt>
                                                <dd class="mt-1">{{ $bank['account_name'] }}</dd>
                                            </div>
                                        @endif
                                        @if (filled($bank['account_number'] ?? null))
                                            <div>
                                                <dt class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Account number</dt>
                                                <dd class="mt-1 font-mono text-base tracking-[0.08em]">{{ $bank['account_number'] }}</dd>
                                            </div>
                                        @endif
                                        @if (filled($bank['swift_code'] ?? null))
                                            <div>
                                                <dt class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">SWIFT / BIC</dt>
                                                <dd class="mt-1">{{ $bank['swift_code'] }}</dd>
                                            </div>
                                        @endif
                                    </dl>
                                @else
                                    <p class="mt-4 rounded-xl border border-[var(--color-line)] bg-[var(--color-paper-deep)] px-4 py-3 text-sm leading-6 text-[var(--color-muted)]">
                                        Message us and we will send the bank details for your transfer.
                                    </p>
                                @endif

                                <p class="mt-4 text-xs leading-6 text-[var(--color-muted)]">
                                    Use <strong class="font-mono text-[var(--color-ink)]">{{ $reservation->code }}</strong> as the
                                    transfer reference, so we can match your payment quickly.
                                </p>
                            </div>
                        @endif

                        @if ($showProofUpload && $hasProofAwaitingVerification)
                            <div class="rounded-2xl border border-[#9CB58B] bg-[#EDF3E6] px-5 py-4 text-sm leading-6 text-[#35502A]" role="status">
                                We have your transfer proof and are verifying it against our bank statement. Your dates stay
                                held while we check. Come back to this page to see when your booking is confirmed.
                            </div>
                        @elseif ($showProofUpload && $lastProofWasRejected)
                            <div class="rounded-2xl border border-[#E4C9C3] bg-[#F8EDEA] px-5 py-4 text-sm leading-6 text-[#7A3B30]" role="alert">
                                We could not verify your last transfer proof. Please upload a clearer copy of the receipt, or
                                message us if you think this is a mistake.
                            </div>
                        @endif

                        @if ($showProofUpload)
                            <div class="rounded-2xl border border-[var(--color-line)] bg-[#FCFAF4] p-6 sm:p-7">
                                <p class="eyebrow">{{ $hasProofAwaitingVerification ? 'Upload another proof' : 'Upload transfer proof' }}</p>
                                <p class="mt-3 text-sm leading-7 text-[var(--color-muted)]">
                                    @if ($hasProofAwaitingVerification)
                                        Only needed if the last photo was unclear or you made another transfer.
                                    @else
                                        Already transferred? Send us a photo or PDF of the receipt. We keep it privately and verify
                                        it before confirming your booking.
                                    @endif
                                </p>

                                <form method="POST" action="{{ route('booking.status.proof') }}" enctype="multipart/form-data" class="mt-5 space-y-4">
                                    @csrf

                                    <div>
                                        <label for="proof" class="block text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Transfer receipt</label>
                                        <input
                                            id="proof"
                                            name="proof"
                                            type="file"
                                            accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf"
                                            required
                                            class="mt-2 block w-full cursor-pointer rounded-xl border border-[var(--color-line)] bg-[var(--color-paper)] px-4 py-3 text-sm text-[var(--color-muted)] file:mr-4 file:rounded-full file:border-0 file:bg-[var(--color-primary)] file:px-5 file:py-2 file:text-[10px] file:font-semibold file:uppercase file:tracking-[0.14em] file:text-[var(--color-paper)] hover:file:opacity-90"
                                        >
                                        <p class="mt-2 text-[11px] leading-5 text-[var(--color-muted)]">JPG, PNG, WebP or PDF, up to 5 MB.</p>
                                        @error('proof')
                                            <p class="mt-1.5 text-xs text-[#7A2F25]">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <button type="submit" class="btn-primary">Upload proof</button>
                                </form>
                            </div>
                        @elseif ($status === \App\Enums\ReservationStatus::AwaitingPayment && $balance <= 0)
                            <div class="rounded-2xl border border-[#9CB58B] bg-[#EDF3E6] px-5 py-4 text-sm leading-6 text-[#35502A]" role="status">
                                We have received your payment and are finalising your booking. You will hear from us shortly.
                            </div>
                        @endif

                        @if ($currentStep !== null)
                            <div class="rounded-2xl border border-[var(--color-line)] p-6 sm:p-7">
                                <p class="eyebrow">What happens next</p>
                                <ol class="mt-5 space-y-4">
                                    @foreach ($steps as $index => $step)
                                        <li class="flex gap-4">
                                            <span @class([
                                                'flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                                                'bg-[var(--color-primary)] text-[var(--color-paper)]' => $index <= $currentStep,
                                                'bg-[var(--color-paper-deep)] text-[var(--color-muted)]' => $index > $currentStep,
                                            ])>{{ $index + 1 }}</span>
                                            <div>
                                                <p @class([
                                                    'text-sm font-medium',
                                                    'text-[var(--color-ink)]' => $index <= $currentStep,
                                                    'text-[var(--color-muted)]' => $index > $currentStep,
                                                ])>{{ $step['label'] }}</p>
                                                <p class="mt-0.5 text-xs leading-5 text-[var(--color-muted)]">{{ $step['caption'] }}</p>
                                            </div>
                                        </li>
                                    @endforeach
                                </ol>
                            </div>
                        @endif
                    </div>

                    <aside class="space-y-5 lg:sticky lg:top-24">
                        <div class="rounded-2xl border border-[var(--color-line)] bg-[#FCFAF4] p-6">
                            <p class="eyebrow">Your stay</p>
                            <p class="mt-2 font-serif text-2xl leading-tight">{{ $room?->roomType?->name ?? 'Villa stay' }}</p>

                            <dl class="mt-5 space-y-3 border-y border-[var(--color-line)] py-4 text-sm">
                                <div class="flex items-start justify-between gap-4">
                                    <dt class="text-[var(--color-muted)]">Check-in</dt>
                                    <dd class="text-right font-medium">{{ $formatDate($reservation->check_in->toDateString()) }}</dd>
                                </div>
                                <div class="flex items-start justify-between gap-4">
                                    <dt class="text-[var(--color-muted)]">Check-out</dt>
                                    <dd class="text-right font-medium">{{ $formatDate($reservation->check_out->toDateString()) }}</dd>
                                </div>
                                <div class="flex items-start justify-between gap-4">
                                    <dt class="text-[var(--color-muted)]">Guests</dt>
                                    <dd class="text-right">{{ $guestSummary }}</dd>
                                </div>
                                <div class="flex items-start justify-between gap-4">
                                    <dt class="text-[var(--color-muted)]">Nights</dt>
                                    <dd class="text-right">{{ $nights }}</dd>
                                </div>
                            </dl>

                            <dl class="mt-4 space-y-3 text-sm">
                                <div class="flex items-start justify-between gap-4">
                                    <dt class="text-[var(--color-muted)]">{{ $status === \App\Enums\ReservationStatus::Pending ? 'Estimated total' : 'Total' }}</dt>
                                    <dd class="text-right font-medium">{{ \App\Support\Money::format($reservation->total, $currency) }}</dd>
                                </div>
                                @if ($paid > 0)
                                    <div class="flex items-start justify-between gap-4">
                                        <dt class="text-[var(--color-muted)]">Paid so far</dt>
                                        <dd class="text-right">{{ \App\Support\Money::format($paid, $currency) }}</dd>
                                    </div>
                                @endif
                                @if ($balance > 0 && $status !== \App\Enums\ReservationStatus::Pending)
                                    <div class="flex items-start justify-between gap-4 border-t border-[var(--color-line)] pt-3 font-semibold">
                                        <dt>Balance due</dt>
                                        <dd class="text-right">{{ \App\Support\Money::format($balance, $currency) }}</dd>
                                    </div>
                                @endif
                            </dl>

                            <div class="mt-5 border-t border-[var(--color-line)] pt-4 text-xs leading-5 text-[var(--color-muted)]">
                                <p>Payment status: <strong class="text-[var(--color-ink)]">{{ $reservation->payment_status->label() }}</strong></p>
                                @if ($status === \App\Enums\ReservationStatus::AwaitingPayment && $deadline)
                                    <p class="mt-1">Pay by {{ $deadline->format('j M Y, H:i T') }}</p>
                                @endif
                                @if (isset($reservation->policy_snapshot['name']))
                                    <p class="mt-1">Rate: {{ $reservation->policy_snapshot['name'] }}</p>
                                @endif
                            </div>
                        </div>

                        <div class="rounded-2xl bg-[var(--color-primary)] p-6 text-[var(--color-paper)]">
                            <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-white/60">Need help?</p>
                            <p class="mt-4 text-sm leading-6 text-white/80">
                                Send us a message with your reference code and we will help with dates, payment or arrival.
                            </p>

                            @if ($profile?->email)
                                <p class="mt-4 text-sm text-white/80">
                                    <a href="mailto:{{ $profile->email }}" class="underline underline-offset-4">{{ $profile->email }}</a>
                                </p>
                            @endif

                            @if ($whatsappUrl)
                                <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer" class="btn-light mt-5">Message us on WhatsApp</a>
                            @endif
                        </div>
                    </aside>
                </div>
            @endif
        </div>
    </section>
@endsection

@php
    $calendar = $this->calendar;
    $result = $this->result;
    $currency = $this->currency;
    $formatDate = fn (string $date) => \Carbon\CarbonImmutable::parse($date)->format('j M Y');
    $nightsLabel = fn (int $nights) => $nights.' '.\Illuminate\Support\Str::plural('night', $nights);
@endphp

<div class="mt-6 space-y-5" wire:loading.class="opacity-70" wire:target="selectDate, clearDates, useAlternative, previousMonth, nextMonth, adults, children">
    {{-- Selected dates --}}
    <div class="grid grid-cols-2 overflow-hidden rounded-xl border border-[var(--color-line)] text-sm">
        <div class="border-r border-[var(--color-line)] px-4 py-3">
            <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Check-in</p>
            <p class="mt-1 font-medium">{{ $checkIn !== '' ? $formatDate($checkIn) : 'Add date' }}</p>
        </div>
        <div class="px-4 py-3">
            <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Check-out</p>
            <p class="mt-1 font-medium">{{ $checkOut !== '' ? $formatDate($checkOut) : 'Add date' }}</p>
        </div>
    </div>

    {{-- Calendar --}}
    <div>
        <div class="flex items-center justify-between">
            <button
                type="button"
                wire:click="previousMonth"
                @disabled(! $this->canGoBack)
                class="flex h-9 w-9 items-center justify-center rounded-full border border-[var(--color-line)] text-[var(--color-primary)] transition hover:border-[var(--color-primary)] disabled:cursor-not-allowed disabled:opacity-30"
                aria-label="Previous month"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
            </button>
            <p class="font-serif text-lg" aria-live="polite">{{ $calendar['label'] }}</p>
            <button
                type="button"
                wire:click="nextMonth"
                @disabled(! $this->canGoForward)
                class="flex h-9 w-9 items-center justify-center rounded-full border border-[var(--color-line)] text-[var(--color-primary)] transition hover:border-[var(--color-primary)] disabled:cursor-not-allowed disabled:opacity-30"
                aria-label="Next month"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                </svg>
            </button>
        </div>

        <div class="mt-4 grid grid-cols-7 gap-y-1 text-center text-[10px] font-semibold uppercase tracking-[0.12em] text-[var(--color-muted)]" aria-hidden="true">
            @foreach (['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'] as $weekday)
                <span class="py-1">{{ $weekday }}</span>
            @endforeach
        </div>

        <div class="mt-1 grid grid-cols-7 gap-y-1 text-sm" role="group" aria-label="{{ $calendar['label'] }}">
            @for ($blank = 0; $blank < $calendar['leadingBlanks']; $blank++)
                <span aria-hidden="true"></span>
            @endfor

            @foreach ($calendar['days'] as $day)
                @php
                    $stateLabel = match ($day['state']) {
                        'check-in' => 'check-in',
                        'check-out' => 'check-out',
                        'in-range' => 'selected',
                        'past' => 'past date',
                        'unavailable' => 'not available',
                        default => 'available',
                    };
                @endphp
                <button
                    type="button"
                    wire:key="day-{{ $day['date'] }}"
                    wire:click="selectDate('{{ $day['date'] }}')"
                    @disabled(! $day['selectable'])
                    data-date="{{ $day['date'] }}" data-state="{{ $day['state'] }}"
                    aria-label="{{ $formatDate($day['date']) }}, {{ $stateLabel }}"
                    @class([
                        'mx-auto flex h-10 w-full max-w-10 items-center justify-center rounded-full transition',
                        'bg-[var(--color-primary)] font-semibold text-[var(--color-paper)]' => in_array($day['state'], ['check-in', 'check-out'], true),
                        'rounded-none bg-[var(--color-accent)]/30 text-[var(--color-ink)]' => $day['state'] === 'in-range',
                        'hover:border hover:border-[var(--color-primary)]' => $day['state'] === 'available',
                        'cursor-not-allowed text-[var(--color-muted)]/50' => $day['state'] === 'past',
                        'cursor-not-allowed text-[var(--color-muted)]/60 line-through decoration-[var(--color-muted)]/60' => $day['state'] === 'unavailable',
                    ])
                >
                    {{ $day['day'] }}
                </button>
            @endforeach
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-[11px] text-[var(--color-muted)]">
            <span class="inline-flex items-center gap-2">
                <span class="line-through">12</span> Not available
            </span>
            @if ($checkIn !== '')
                <button type="button" wire:click="clearDates" class="font-semibold uppercase tracking-[0.14em] text-[var(--color-primary)] underline-offset-4 hover:underline">
                    Clear dates
                </button>
            @else
                <span>Select your check-in date</span>
            @endif
        </div>
    </div>

    {{-- Guests --}}
    <div class="grid grid-cols-2 gap-3">
        <label class="block">
            <span class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Adults</span>
            <select wire:model.live="adults" class="mt-2 w-full rounded-xl border border-[var(--color-line)] bg-transparent px-3 py-2.5 text-sm focus:border-[var(--color-primary)] focus:outline-none">
                @for ($count = 1; $count <= $villa->max_adults; $count++)
                    <option value="{{ $count }}">{{ $count }}</option>
                @endfor
            </select>
        </label>
        <label class="block">
            <span class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Children</span>
            <select wire:model.live="children" @disabled($villa->max_children === 0) class="mt-2 w-full rounded-xl border border-[var(--color-line)] bg-transparent px-3 py-2.5 text-sm focus:border-[var(--color-primary)] focus:outline-none disabled:opacity-50">
                @for ($count = 0; $count <= $villa->max_children; $count++)
                    <option value="{{ $count }}">{{ $count }}</option>
                @endfor
            </select>
        </label>
    </div>

    {{-- Result --}}
    <div aria-live="polite">
        @if ($result === null)
            <p class="rounded-xl bg-[var(--color-paper-deep)] px-4 py-3 text-sm leading-6 text-[var(--color-muted)]">
                {{ $checkIn === '' ? 'Choose your dates to see the price for your stay.' : 'Now choose your check-out date.' }}
            </p>
        @elseif ($result->isAvailable)
            @php
                $quote = $result->quote;
                $priceGroups = collect($quote->nights)->groupBy('price');
            @endphp
            <div class="space-y-3 border-y border-[var(--color-line)] py-5 text-sm">
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
                <p class="text-[11px] text-[var(--color-muted)]">{{ $this->guestSummary }}</p>
            </div>

            @if ($result->policy)
                <div class="rounded-xl border border-[var(--color-line)] bg-[var(--color-paper-deep)] p-4">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">{{ $result->policy->name }}</p>
                    @if ($result->policy->description)
                        <p class="mt-2 text-xs leading-6 text-[var(--color-muted)]">{{ $result->policy->description }}</p>
                    @endif
                </div>
            @endif

            <a href="{{ route('booking.create', [
                'villa' => $villa->slug,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'adults' => $adults,
                'children' => $children,
            ]) }}" class="btn-primary mt-5 w-full">
                Request to book
            </a>
        @else
            <div class="rounded-xl border border-[#E4C9C3] bg-[#F8EDEA] px-4 py-3 text-sm leading-6 text-[#7A3B30]" role="status">
                {{ $result->message }}
            </div>

            @if ($result->alternative)
                @php $alternative = $result->alternative; @endphp
                <div class="mt-4 rounded-xl border border-[var(--color-line)] p-4">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Nearest available dates</p>
                    <p class="mt-2 font-medium">{{ $formatDate($alternative->checkIn) }} – {{ $formatDate($alternative->checkOut) }}</p>
                    <p class="mt-1 text-xs text-[var(--color-muted)]">
                        {{ $nightsLabel($alternative->nightCount()) }} · {{ \App\Support\Money::format($alternative->total, $currency) }} total
                    </p>
                    <button type="button" wire:click="useAlternative" class="btn-outline mt-4 w-full">
                        Use these dates
                    </button>
                </div>
            @endif
        @endif
    </div>

    @if ($this->whatsappUrl && ($result === null || ! $result->isAvailable))
        <a href="{{ $this->whatsappUrl }}" target="_blank" rel="noopener noreferrer" class="btn-outline w-full">
            {{ $result === null ? 'Ask on WhatsApp' : 'Ask about other dates on WhatsApp' }}
        </a>
    @endif

    <p class="text-center text-[11px] leading-5 text-[var(--color-muted)]">
        No payment is taken online. We confirm availability with you first.
    </p>
</div>

@php
    $pluralGuests = $guests === 1 ? 'guest' : 'guests';
    $calendar = $this->calendar;
@endphp

<div
    class="relative rounded-2xl bg-[var(--color-paper)] p-1.5 text-[var(--color-ink)] shadow-2xl shadow-black/20 sm:p-2"
    x-data="{ open: false, field: null }"
    x-on:click.outside="open = false"
    x-on:keydown.escape.window="open = false"
    x-on:home-search-complete.window="open = false"
>
    <div class="grid overflow-hidden rounded-xl border border-[var(--color-line)]/60 sm:grid-cols-3 lg:grid-cols-[1fr_1fr_1fr_auto] lg:divide-x lg:divide-[var(--color-line)]">
        {{-- Check-in --}}
        <button
            type="button"
            x-on:click="open = true; field = 'in'"
            x-bind:class="open && field === 'in' ? 'bg-[var(--color-paper-deep)]/60' : ''"
            x-bind:aria-expanded="open"
            aria-haspopup="dialog"
            aria-controls="home-search-calendar"
            class="relative flex cursor-pointer flex-col px-4 py-3 text-left transition focus-visible:bg-[var(--color-paper-deep)]/60 sm:border-b sm:border-[var(--color-line)] sm:px-5 lg:border-b-0"
        >
            <span class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Check-in</span>
            <span class="mt-1.5 flex items-center gap-2 text-sm font-medium {{ $checkInLabel ? '' : 'text-[var(--color-muted)]' }}">
                <svg class="h-4 w-4 shrink-0 text-[var(--color-primary)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <rect x="3.5" y="5" width="17" height="15.5" rx="2.5" />
                    <path stroke-linecap="round" d="M8 3.5v3M16 3.5v3M3.5 9.5h17" />
                </svg>
                <span class="truncate">{{ $checkInLabel ?? 'Add date' }}</span>
            </span>
        </button>

        {{-- Check-out --}}
        <button
            type="button"
            x-on:click="open = true; field = 'out'"
            x-bind:class="open && field === 'out' ? 'bg-[var(--color-paper-deep)]/60' : ''"
            x-bind:aria-expanded="open"
            aria-haspopup="dialog"
            aria-controls="home-search-calendar"
            class="relative flex cursor-pointer flex-col border-t border-[var(--color-line)] px-4 py-3 text-left transition focus-visible:bg-[var(--color-paper-deep)]/60 sm:border-t-0 sm:border-b sm:border-[var(--color-line)] sm:px-5 lg:border-b-0"
        >
            <span class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Check-out</span>
            <span class="mt-1.5 flex items-center gap-2 text-sm font-medium {{ $checkOutLabel ? '' : 'text-[var(--color-muted)]' }}">
                <svg class="h-4 w-4 shrink-0 text-[var(--color-primary)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <rect x="3.5" y="5" width="17" height="15.5" rx="2.5" />
                    <path stroke-linecap="round" d="M8 3.5v3M16 3.5v3M3.5 9.5h17" />
                </svg>
                <span class="truncate">{{ $checkOutLabel ?? 'Add date' }}</span>
            </span>
        </button>

        {{-- Guests --}}
        <label class="relative flex cursor-pointer flex-col border-t border-[var(--color-line)] px-4 py-3 focus-within:bg-[var(--color-paper-deep)]/60 sm:border-t-0 sm:border-b sm:border-[var(--color-line)] sm:px-5 lg:border-b-0">
            <span class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Guests</span>
            <span class="mt-1.5 flex items-center gap-2 text-sm font-medium">
                <svg class="h-4 w-4 shrink-0 text-[var(--color-primary)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <circle cx="12" cy="8" r="3.2" />
                    <path stroke-linecap="round" d="M5.5 20c.6-3.5 3.1-5.5 6.5-5.5s5.9 2 6.5 5.5" />
                </svg>
                <span class="truncate">{{ $guests }} {{ $pluralGuests }}</span>
            </span>
            <svg class="pointer-events-none absolute right-4 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--color-muted)] sm:right-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6" />
            </svg>
            <select
                wire:model.live="guests"
                class="absolute inset-0 h-full w-full cursor-pointer appearance-none opacity-0"
                aria-label="Number of guests"
            >
                @for ($count = 1; $count <= $maxGuests; $count++)
                    <option value="{{ $count }}">{{ $count }}</option>
                @endfor
            </select>
        </label>

        {{-- Search --}}
        <div class="border-t border-[var(--color-line)] p-1.5 sm:col-span-3 sm:border-t-0 lg:col-span-1">
            <button
                type="button"
                wire:click="search"
                wire:loading.attr="disabled"
                wire:loading.class="opacity-70"
                wire:target="search"
                class="inline-flex h-full min-h-14 w-full items-center justify-center gap-2 rounded-lg bg-[var(--color-primary)] px-6 py-3.5 text-[11px] font-semibold uppercase tracking-[0.16em] text-[var(--color-paper)] transition hover:opacity-90 focus-visible:outline-offset-4 disabled:cursor-wait lg:rounded-l-none"
            >
                Check availability
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M12 5l7 7-7 7" />
                </svg>
            </button>
        </div>
    </div>

    {{-- Calendar --}}
    <div
        id="home-search-calendar"
        x-cloak
        x-show="open"
        x-transition.opacity.duration.150ms
        role="dialog"
        aria-label="Choose your dates"
        class="absolute left-0 top-full z-50 mt-3 w-[21rem] max-w-[calc(100vw-2rem)] rounded-2xl border border-[var(--color-line)] bg-[var(--color-paper)] p-4 shadow-2xl shadow-black/25"
    >
        <div class="flex items-center justify-between gap-2">
            <button
                type="button"
                wire:click="previousMonth"
                @disabled(! $this->canGoBack)
                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-[var(--color-line)] text-[var(--color-primary)] transition hover:border-[var(--color-primary)] disabled:cursor-not-allowed disabled:opacity-30"
                aria-label="Previous month"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
            </button>

            <p class="font-serif text-lg leading-none" aria-live="polite">{{ $calendar['label'] }}</p>

            <button
                type="button"
                wire:click="nextMonth"
                @disabled(! $this->canGoForward)
                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-[var(--color-line)] text-[var(--color-primary)] transition hover:border-[var(--color-primary)] disabled:cursor-not-allowed disabled:opacity-30"
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
                    x-on:click="$wire.call('selectDate', '{{ $day['date'] }}', field)"
                    @disabled(! $day['selectable'])
                    data-date="{{ $day['date'] }}" data-state="{{ $day['state'] }}"
                    aria-label="{{ \Carbon\CarbonImmutable::parse($day['date'])->format('j M Y') }}, {{ $stateLabel }}"
                    @class([
                        'mx-auto flex h-9 w-full max-w-9 items-center justify-center rounded-full text-[13px] transition',
                        'bg-[var(--color-primary)] font-semibold text-[var(--color-paper)]' => in_array($day['state'], ['check-in', 'check-out'], true),
                        'rounded-none bg-[var(--color-accent)]/30 text-[var(--color-ink)]' => $day['state'] === 'in-range',
                        'hover:bg-[var(--color-paper-deep)] hover:ring-1 hover:ring-inset hover:ring-[var(--color-primary)]' => $day['state'] === 'available',
                        'cursor-not-allowed text-[var(--color-muted)]/40' => $day['state'] === 'past',
                        'cursor-not-allowed text-[var(--color-muted)]/60 line-through decoration-[var(--color-muted)]/50' => $day['state'] === 'unavailable',
                    ])
                >
                    {{ $day['day'] }}
                </button>
            @endforeach
        </div>

        <div class="mt-3 flex flex-wrap items-center justify-between gap-x-3 gap-y-2 border-t border-[var(--color-line)] pt-3 text-[10px] uppercase tracking-[0.14em] text-[var(--color-muted)]">
            <span class="inline-flex items-center gap-2">
                <span class="line-through decoration-[var(--color-muted)]/60">12</span> Not available
            </span>

            @if ($checkIn !== '')
                <button type="button" wire:click="clearDates" class="font-semibold text-[var(--color-primary)] underline-offset-4 hover:underline">
                    Clear dates
                </button>
            @else
                <span>Select check-in</span>
            @endif
        </div>

        @if ($errors->has('checkOut'))
            <p class="mt-2 text-[11px] leading-5 text-[#7A3B30]" role="alert">
                {{ $errors->first('checkOut') }}
            </p>
        @elseif ($checkIn !== '' && $checkOut === '')
            <p class="mt-2 text-[10px] uppercase tracking-[0.14em] text-[var(--color-primary)]" aria-live="polite">
                Now choose your check-out
            </p>
        @endif
    </div>

    @if ($errors->any())
        <p class="px-4 pb-2 pt-3 text-xs font-medium text-[#7A3B30]" role="alert">{{ $errors->first() }}</p>
    @endif
</div>

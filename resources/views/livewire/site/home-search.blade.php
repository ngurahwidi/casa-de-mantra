@php
    $pluralGuests = $guests === 1 ? 'guest' : 'guests';
@endphp

<div class="rounded-2xl bg-[var(--color-paper)] p-1.5 shadow-2xl shadow-black/20 sm:p-2">
    <div class="grid overflow-hidden rounded-xl border border-[var(--color-line)]/60 sm:grid-cols-3 lg:grid-cols-[1fr_1fr_1fr_auto] lg:divide-x lg:divide-[var(--color-line)]">
        {{-- Check-in --}}
        <label class="relative flex cursor-pointer flex-col px-4 py-3 focus-within:bg-[var(--color-paper-deep)]/60 sm:border-b sm:border-[var(--color-line)] sm:px-5 lg:border-b-0">
            <span class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Check-in</span>
            <span class="mt-1.5 flex items-center gap-2 text-sm font-medium {{ $checkInLabel ? '' : 'text-[var(--color-muted)]' }}">
                <svg class="h-4 w-4 shrink-0 text-[var(--color-primary)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <rect x="3.5" y="5" width="17" height="15.5" rx="2.5" />
                    <path stroke-linecap="round" d="M8 3.5v3M16 3.5v3M3.5 9.5h17" />
                </svg>
                <span class="truncate">{{ $checkInLabel ?? 'Add date' }}</span>
            </span>
            <input
                type="date"
                wire:model.live="checkIn"
                value="{{ $checkIn }}"
                min="{{ $today }}"
                max="{{ $lastCheckIn }}"
                x-on:click="if (typeof $el.showPicker === 'function') { try { $el.showPicker() } catch (e) {} }"
                class="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                aria-label="Check-in date"
            >
        </label>

        {{-- Check-out --}}
        <label class="relative flex cursor-pointer flex-col border-t border-[var(--color-line)] px-4 py-3 focus-within:bg-[var(--color-paper-deep)]/60 sm:border-t-0 sm:border-b sm:border-[var(--color-line)] sm:px-5 lg:border-b-0">
            <span class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Check-out</span>
            <span class="mt-1.5 flex items-center gap-2 text-sm font-medium {{ $checkOutLabel ? '' : 'text-[var(--color-muted)]' }}">
                <svg class="h-4 w-4 shrink-0 text-[var(--color-primary)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <rect x="3.5" y="5" width="17" height="15.5" rx="2.5" />
                    <path stroke-linecap="round" d="M8 3.5v3M16 3.5v3M3.5 9.5h17" />
                </svg>
                <span class="truncate">{{ $checkOutLabel ?? 'Add date' }}</span>
            </span>
            <input
                type="date"
                wire:model.live="checkOut"
                value="{{ $checkOut }}"
                min="{{ $checkIn !== '' ? $checkIn : $today }}"
                max="{{ $lastCheckOut }}"
                x-on:click="if (typeof $el.showPicker === 'function') { try { $el.showPicker() } catch (e) {} }"
                class="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                aria-label="Check-out date"
            >
        </label>

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

    @if ($errors->any())
        <p class="px-4 pb-2 pt-3 text-xs font-medium text-[#7A3B30]" role="alert">{{ $errors->first() }}</p>
    @endif
</div>

{{--
    Availability calendar of the Calendar page (AvailabilityCalendar::availabilityCalendar()).
    The admin panel ships precompiled CSS, so the grid is styled here with Filament's colour variables.
--}}
<div class="availability-calendar">
    <style>
        .availability-calendar { --ac-border: var(--gray-200); }
        .dark .availability-calendar { --ac-border: var(--gray-700); }
        .availability-calendar__units { margin-bottom: 1rem; }
        .availability-calendar__header { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; margin-bottom: 0.75rem; }
        .availability-calendar__title { font-weight: 600; font-size: 1rem; }
        .availability-calendar__summary { font-size: 0.875rem; color: var(--gray-500); }
        .availability-calendar__grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 0.25rem; }
        .availability-calendar__weekday { font-size: 0.75rem; font-weight: 500; text-align: center; color: var(--gray-500); padding-bottom: 0.25rem; }
        .availability-calendar__day { min-height: 5rem; padding: 0.375rem; border-radius: 0.5rem; border: 1px solid var(--ac-border); display: flex; flex-direction: column; gap: 0.125rem; overflow: hidden; }
        .availability-calendar__top { display: flex; justify-content: space-between; gap: 0.25rem; font-size: 0.75rem; color: var(--gray-500); }
        .availability-calendar__price { font-variant-numeric: tabular-nums; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .availability-calendar__price--missing { color: var(--danger-600); }
        .dark .availability-calendar__price--missing { color: var(--danger-400); }
        .availability-calendar__entry { font-size: 0.75rem; font-weight: 600; line-height: 1.2; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
        .availability-calendar__day--success { background: color-mix(in oklab, var(--success-500) 16%, transparent); border-color: color-mix(in oklab, var(--success-500) 45%, transparent); }
        .availability-calendar__day--warning { background: color-mix(in oklab, var(--warning-500) 18%, transparent); border-color: color-mix(in oklab, var(--warning-500) 50%, transparent); }
        .availability-calendar__day--danger { background: color-mix(in oklab, var(--danger-500) 14%, transparent); border-color: color-mix(in oklab, var(--danger-500) 45%, transparent); }
        .availability-calendar__day--gray { background: color-mix(in oklab, var(--gray-500) 16%, transparent); border-color: color-mix(in oklab, var(--gray-500) 40%, transparent); }
        .availability-calendar__day--past { opacity: 0.45; }
        .availability-calendar__legend { display: flex; flex-wrap: wrap; gap: 0.5rem 1rem; margin-top: 0.75rem; font-size: 0.75rem; color: var(--gray-500); }
        .availability-calendar__swatch { display: inline-block; width: 0.75rem; height: 0.75rem; border-radius: 0.25rem; margin-right: 0.25rem; vertical-align: -0.125rem; border: 1px solid var(--ac-border); }
        @media (max-width: 640px) {
            .availability-calendar__day { min-height: 3.5rem; padding: 0.25rem; }
            .availability-calendar__price { display: none; }
            .availability-calendar__entry { font-size: 0.625rem; -webkit-line-clamp: 1; }
        }
    </style>

    @if ($units->count() > 1)
        <x-filament::tabs class="availability-calendar__units" label="Units">
            @foreach ($units as $tab)
                <x-filament::tabs.item
                    :active="$tab->is($unit)"
                    wire:click="selectUnit('{{ $tab->getKey() }}')"
                >
                    {{ $tab->code }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>
    @endif

    <div class="availability-calendar__header">
        <x-filament::icon-button
            :icon="\Filament\Support\Icons\Heroicon::ChevronLeft"
            color="gray"
            label="Previous month"
            wire:click="previousCalendarMonth"
        />

        <div style="text-align: center">
            <div class="availability-calendar__title">Unit {{ $unit->code }} · {{ $month }}</div>
            <div class="availability-calendar__summary">
                {{ $unit->roomType->name }} · {{ trans_choice(':count night taken|:count nights taken', $taken) }}
            </div>
        </div>

        <x-filament::icon-button
            :icon="\Filament\Support\Icons\Heroicon::ChevronRight"
            color="gray"
            label="Next month"
            wire:click="nextCalendarMonth"
        />
    </div>

    <div class="availability-calendar__grid">
        @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday)
            <div class="availability-calendar__weekday">{{ $weekday }}</div>
        @endforeach

        @foreach ($weeks as $week)
            @foreach ($week as $night)
                @if ($night === null)
                    <div aria-hidden="true"></div>
                @else
                    <div
                        @class([
                            'availability-calendar__day',
                            'availability-calendar__day--'.$night['color'] => $night['color'] !== null,
                            'availability-calendar__day--past' => $night['isPast'],
                        ])
                        data-date="{{ $night['date'] }}"
                        title="{{ $night['entries'] === [] ? 'Free' : implode("\n", array_column($night['entries'], 'title')) }}"
                    >
                        <div class="availability-calendar__top">
                            <span>{{ $night['day'] }}</span>
                            <span @class(['availability-calendar__price', 'availability-calendar__price--missing' => $night['price'] === null])>
                                {{ $night['price'] ?? 'No price' }}
                            </span>
                        </div>

                        @foreach ($night['entries'] as $entry)
                            <span class="availability-calendar__entry">{{ $entry['label'] }}</span>
                        @endforeach
                    </div>
                @endif
            @endforeach
        @endforeach
    </div>

    <div class="availability-calendar__legend">
        <span><span class="availability-calendar__swatch availability-calendar__day--success"></span>Confirmed</span>
        <span><span class="availability-calendar__swatch availability-calendar__day--warning"></span>Awaiting payment</span>
        <span><span class="availability-calendar__swatch availability-calendar__day--danger"></span>Blocked</span>
        <span><span class="availability-calendar__swatch availability-calendar__day--gray"></span>OTA booking</span>
    </div>
</div>

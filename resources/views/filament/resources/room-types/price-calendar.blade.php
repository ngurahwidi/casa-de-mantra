{{--
    Price calendar of the room type Pricing page (ManageRoomTypePricing::priceCalendar()).
    The admin panel ships precompiled CSS, so the grid is styled here with Filament's colour variables.
--}}
<div class="price-calendar">
    <style>
        .price-calendar { --pc-border: var(--gray-200); }
        .dark .price-calendar { --pc-border: var(--gray-700); }
        .price-calendar__header { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; margin-bottom: 0.75rem; }
        .price-calendar__title { font-weight: 600; font-size: 1rem; }
        .price-calendar__summary { font-size: 0.875rem; color: var(--gray-500); }
        .price-calendar__summary--missing { color: var(--danger-600); font-weight: 500; }
        .dark .price-calendar__summary--missing { color: var(--danger-400); }
        .price-calendar__grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 0.25rem; }
        .price-calendar__weekday { font-size: 0.75rem; font-weight: 500; text-align: center; color: var(--gray-500); padding-bottom: 0.25rem; }
        .price-calendar__day { min-height: 4rem; padding: 0.375rem; border-radius: 0.5rem; border: 1px solid var(--pc-border); display: flex; flex-direction: column; justify-content: space-between; overflow: hidden; }
        .price-calendar__date { font-size: 0.75rem; color: var(--gray-500); }
        .price-calendar__price { font-size: 0.8125rem; font-weight: 600; font-variant-numeric: tabular-nums; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .price-calendar__day--gray { background: color-mix(in oklab, var(--gray-500) 6%, transparent); }
        .price-calendar__day--info { background: color-mix(in oklab, var(--info-500) 14%, transparent); border-color: color-mix(in oklab, var(--info-500) 40%, transparent); }
        .price-calendar__day--warning { background: color-mix(in oklab, var(--warning-500) 16%, transparent); border-color: color-mix(in oklab, var(--warning-500) 45%, transparent); }
        .price-calendar__day--success { background: color-mix(in oklab, var(--success-500) 14%, transparent); border-color: color-mix(in oklab, var(--success-500) 40%, transparent); }
        .price-calendar__day--danger { background: color-mix(in oklab, var(--danger-500) 16%, transparent); border-color: var(--danger-500); color: var(--danger-700); }
        .dark .price-calendar__day--danger { color: var(--danger-300); }
        .price-calendar__day--past { opacity: 0.4; background: transparent; border-color: var(--pc-border); color: inherit; }
        .price-calendar__legend { display: flex; flex-wrap: wrap; gap: 0.5rem 1rem; margin-top: 0.75rem; font-size: 0.75rem; color: var(--gray-500); }
        .price-calendar__swatch { display: inline-block; width: 0.75rem; height: 0.75rem; border-radius: 0.25rem; margin-right: 0.25rem; vertical-align: -0.125rem; border: 1px solid var(--pc-border); }
        @media (max-width: 640px) {
            .price-calendar__day { min-height: 3rem; padding: 0.25rem; }
            .price-calendar__price { font-size: 0.6875rem; }
        }
    </style>

    <div class="price-calendar__header">
        <x-filament::icon-button
            :icon="\Filament\Support\Icons\Heroicon::ChevronLeft"
            color="gray"
            label="Previous month"
            wire:click="previousCalendarMonth"
        />

        <div style="text-align: center">
            <div class="price-calendar__title">{{ $month }}</div>
            <div @class(['price-calendar__summary', 'price-calendar__summary--missing' => $unpriced > 0])>
                @if ($unpriced > 0)
                    {{ trans_choice(':count night has no price|:count nights have no price', $unpriced) }}
                @else
                    Prices per night in {{ $currency }}
                @endif
            </div>
        </div>

        <x-filament::icon-button
            :icon="\Filament\Support\Icons\Heroicon::ChevronRight"
            color="gray"
            label="Next month"
            wire:click="nextCalendarMonth"
        />
    </div>

    <div class="price-calendar__grid">
        @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday)
            <div class="price-calendar__weekday">{{ $weekday }}</div>
        @endforeach

        @foreach ($weeks as $week)
            @foreach ($week as $night)
                @if ($night === null)
                    <div aria-hidden="true"></div>
                @else
                    <div
                        @class([
                            'price-calendar__day',
                            'price-calendar__day--'.$night['color'],
                            'price-calendar__day--past' => $night['isPast'],
                        ])
                        data-date="{{ $night['date'] }}"
                        title="{{ $night['name'] ?? 'No price' }}"
                    >
                        <span class="price-calendar__date">{{ $night['day'] }}</span>
                        <span class="price-calendar__price">{{ $night['price'] ?? 'No price' }}</span>
                    </div>
                @endif
            @endforeach
        @endforeach
    </div>

    <div class="price-calendar__legend">
        <span><span class="price-calendar__swatch price-calendar__day--gray"></span>Base price</span>
        <span><span class="price-calendar__swatch price-calendar__day--info"></span>Weekend price</span>
        <span><span class="price-calendar__swatch price-calendar__day--warning"></span>Seasonal price</span>
        <span><span class="price-calendar__swatch price-calendar__day--success"></span>Special dates</span>
        <span><span class="price-calendar__swatch price-calendar__day--danger"></span>No price</span>
    </div>
</div>

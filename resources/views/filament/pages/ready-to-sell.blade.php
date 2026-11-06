{{--
    The checks of the "Ready to sell?" page (ReadyToSell::report()).
    The admin panel ships precompiled CSS, so the page is styled here with Filament's colour variables.
--}}
<div class="ready-to-sell">
    <style>
        .ready-to-sell { display: grid; gap: 1.5rem; --rs-border: var(--gray-200); }
        .dark .ready-to-sell { --rs-border: var(--gray-700); }
        .ready-to-sell__verdict { display: flex; gap: 0.75rem; padding: 1rem 1.25rem; border-radius: 0.75rem; border: 1px solid transparent; }
        .ready-to-sell__verdict-icon { flex-shrink: 0; }
        .ready-to-sell__verdict-title { font-weight: 600; font-size: 1.125rem; }
        .ready-to-sell__verdict-text { font-size: 0.875rem; color: var(--gray-500); margin-top: 0.125rem; }
        .ready-to-sell__verdict--success { background: color-mix(in oklab, var(--success-500) 14%, transparent); border-color: color-mix(in oklab, var(--success-500) 45%, transparent); }
        .ready-to-sell__verdict--success .ready-to-sell__verdict-icon { color: var(--success-600); }
        .dark .ready-to-sell__verdict--success .ready-to-sell__verdict-icon { color: var(--success-400); }
        .ready-to-sell__verdict--warning { background: color-mix(in oklab, var(--warning-500) 16%, transparent); border-color: color-mix(in oklab, var(--warning-500) 50%, transparent); }
        .ready-to-sell__verdict--warning .ready-to-sell__verdict-icon { color: var(--warning-600); }
        .dark .ready-to-sell__verdict--warning .ready-to-sell__verdict-icon { color: var(--warning-400); }
        .ready-to-sell__verdict--danger { background: color-mix(in oklab, var(--danger-500) 12%, transparent); border-color: color-mix(in oklab, var(--danger-500) 40%, transparent); }
        .ready-to-sell__verdict--danger .ready-to-sell__verdict-icon { color: var(--danger-600); }
        .dark .ready-to-sell__verdict--danger .ready-to-sell__verdict-icon { color: var(--danger-400); }
        .ready-to-sell__issues { list-style: disc; padding-left: 1.25rem; margin-top: 0.375rem; font-size: 0.875rem; color: var(--gray-600); }
        .dark .ready-to-sell__issues { color: var(--gray-300); }
        .ready-to-sell__heading { font-size: 1rem; font-weight: 600; }
        .ready-to-sell__group { display: grid; gap: 0.5rem; margin-top: 0.75rem; }
        .ready-to-sell__row { display: flex; align-items: flex-start; gap: 0.625rem; padding: 0.75rem 1rem; border-radius: 0.5rem; border: 1px solid var(--rs-border); }
        .ready-to-sell__row-icon { flex-shrink: 0; margin-top: 0.125rem; }
        .ready-to-sell__row-icon--pass { color: var(--success-600); }
        .dark .ready-to-sell__row-icon--pass { color: var(--success-400); }
        .ready-to-sell__row-icon--fail { color: var(--danger-600); }
        .dark .ready-to-sell__row-icon--fail { color: var(--danger-400); }
        .ready-to-sell__row-body { display: grid; gap: 0.25rem; }
        .ready-to-sell__label { font-weight: 500; font-size: 0.875rem; }
        .ready-to-sell__detail { font-size: 0.875rem; color: var(--gray-500); }
        .ready-to-sell__row--fail { background: color-mix(in oklab, var(--danger-500) 6%, transparent); border-color: color-mix(in oklab, var(--danger-500) 35%, transparent); }
        .ready-to-sell__fix { margin-left: auto; flex-shrink: 0; }
        .ready-to-sell__room { padding: 1rem 1.25rem; border-radius: 0.75rem; border: 1px solid var(--rs-border); display: grid; gap: 0.875rem; }
        .ready-to-sell__room-head { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem 0.75rem; }
        .ready-to-sell__room-name { font-weight: 600; font-size: 1rem; }
        .ready-to-sell__room-links { margin-left: auto; display: flex; gap: 1rem; }
        .ready-to-sell__checks { display: grid; gap: 0.375rem; }
        .ready-to-sell__check { display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; }
        .ready-to-sell__check-icon--pass { color: var(--success-600); }
        .dark .ready-to-sell__check-icon--pass { color: var(--success-400); }
        .ready-to-sell__check-icon--fail { color: var(--danger-600); }
        .dark .ready-to-sell__check-icon--fail { color: var(--danger-400); }
        .ready-to-sell__ranges { list-style: disc; padding-left: 1.5rem; margin-top: 0.25rem; font-size: 0.875rem; color: var(--danger-600); }
        .dark .ready-to-sell__ranges { color: var(--danger-400); }
        .ready-to-sell__empty { padding: 1rem 1.25rem; border-radius: 0.75rem; border: 1px dashed var(--rs-border); font-size: 0.875rem; color: var(--gray-500); }
    </style>

    @php
        $passes = fn (bool $pass): string => $pass ? 'pass' : 'fail';
        $passIcon = \Filament\Support\Icons\Heroicon::OutlinedCheckCircle;
        $failIcon = \Filament\Support\Icons\Heroicon::OutlinedXCircle;
    @endphp

    <div @class([
        'ready-to-sell__verdict',
        'ready-to-sell__verdict--success' => $ready && $issues === [],
        'ready-to-sell__verdict--warning' => $ready && $issues !== [],
        'ready-to-sell__verdict--danger' => ! $ready,
    ])>
        <x-filament::icon
            :icon="$ready ? $passIcon : $failIcon"
            class="ready-to-sell__verdict-icon"
        />

        <div>
            <div class="ready-to-sell__verdict-title">{{ $ready ? 'Ready to sell' : 'Not ready to sell' }}</div>

            <div class="ready-to-sell__verdict-text">
                @if ($ready && $issues === [])
                    Everything the booking flow needs is in place. Guests can request every published room type.
                @elseif ($ready)
                    Guests can request the complete published room types. Some checks still need attention:
                @else
                    Guests cannot book yet. Fix the failed checks below:
                @endif
            </div>

            @if ($issues !== [])
                <ul class="ready-to-sell__issues">
                    @foreach ($issues as $issue)
                        <li>{{ $issue }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <section>
        <h3 class="ready-to-sell__heading">Property</h3>

        <div class="ready-to-sell__group">
            <div @class(['ready-to-sell__row', 'ready-to-sell__row--fail' => $profile['missing'] !== []])>
                <x-filament::icon
                    :icon="$profile['missing'] === [] ? $passIcon : $failIcon"
                    @class(['ready-to-sell__row-icon', 'ready-to-sell__row-icon--'.$passes($profile['missing'] === [])])
                />

                <div class="ready-to-sell__row-body">
                    <span class="ready-to-sell__label">Property profile</span>
                    <span class="ready-to-sell__detail">
                        @if ($profile['missing'] === [])
                            Everything the website and the booking flow need is filled in.
                        @else
                            Missing: {{ implode(', ', $profile['missing']) }}.
                        @endif
                    </span>
                </div>

                @if ($profile['missing'] !== [])
                    <div class="ready-to-sell__fix">
                        <x-filament::link :href="$profile['url']">Edit the property profile</x-filament::link>
                    </div>
                @endif
            </div>

            <div @class(['ready-to-sell__row', 'ready-to-sell__row--fail' => $templates['inactive'] !== []])>
                <x-filament::icon
                    :icon="$templates['inactive'] === [] ? $passIcon : $failIcon"
                    @class(['ready-to-sell__row-icon', 'ready-to-sell__row-icon--'.$passes($templates['inactive'] === [])])
                />

                <div class="ready-to-sell__row-body">
                    <span class="ready-to-sell__label">Notification templates</span>
                    <span class="ready-to-sell__detail">
                        @if ($templates['inactive'] === [])
                            Every notification of the booking flow has an active template.
                        @else
                            Missing or inactive: {{ implode(', ', $templates['inactive']) }}.
                        @endif
                    </span>
                </div>

                @if ($templates['inactive'] !== [])
                    <div class="ready-to-sell__fix">
                        <x-filament::link :href="$templates['url']">Manage notification templates</x-filament::link>
                    </div>
                @endif
            </div>

            <div @class(['ready-to-sell__row', 'ready-to-sell__row--fail' => $feeds['failing'] !== []])>
                <x-filament::icon
                    :icon="$feeds['failing'] === [] ? $passIcon : $failIcon"
                    @class(['ready-to-sell__row-icon', 'ready-to-sell__row-icon--'.$passes($feeds['failing'] === [])])
                />

                <div class="ready-to-sell__row-body">
                    <span class="ready-to-sell__label">iCal feeds</span>
                    <span class="ready-to-sell__detail">
                        @if ($feeds['failing'] === [])
                            No import feed is failing.
                        @else
                            Failing: {{ implode(', ', $feeds['failing']) }}.
                        @endif
                    </span>
                </div>

                @if ($feeds['failing'] !== [])
                    <div class="ready-to-sell__fix">
                        <x-filament::link :href="$feeds['url']">Manage iCal feeds</x-filament::link>
                    </div>
                @endif
            </div>
        </div>
    </section>

    <section>
        <h3 class="ready-to-sell__heading">Room types</h3>

        <div class="ready-to-sell__group">
            @forelse ($roomTypes as $roomType)
                <div class="ready-to-sell__room">
                    <div class="ready-to-sell__room-head">
                        <span class="ready-to-sell__room-name">{{ $roomType['readiness']->roomType->name }}</span>

                        @if ($roomType['readiness']->roomType->is_published)
                            <x-filament::badge color="success">Published</x-filament::badge>
                        @else
                            <x-filament::badge color="gray">Not published</x-filament::badge>
                        @endif

                        <div class="ready-to-sell__room-links">
                            <x-filament::link :href="$roomType['detailsUrl']">Details</x-filament::link>
                            <x-filament::link :href="$roomType['pricingUrl']">Pricing</x-filament::link>
                        </div>
                    </div>

                    <div class="ready-to-sell__checks">
                        @foreach ($roomType['checks'] as $check)
                            <div class="ready-to-sell__check">
                                <x-filament::icon
                                    :icon="$check['passes'] ? $passIcon : $failIcon"
                                    @class(['ready-to-sell__check-icon-'.$passes($check['passes'])])
                                />

                                <span>{{ $check['label'] }}</span>

                                @if (! $check['passes'] && $check['key'] === 'nights')
                                    <ul class="ready-to-sell__ranges">
                                        @foreach ($roomType['unpriced'] as $range)
                                            <li>{{ $range }}</li>
                                        @endforeach

                                        @if ($roomType['moreUnpriced'] > 0)
                                            <li>{{ trans_choice('...and :count more run|...and :count more runs', $roomType['moreUnpriced']) }}</li>
                                        @endif
                                    </ul>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="ready-to-sell__empty">
                    No room types yet. Add one under Room types, give it a photo, a unit and a base price, then publish it.
                </div>
            @endforelse
        </div>
    </section>
</div>

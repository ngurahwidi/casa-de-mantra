@props(['profile' => null])

@php
    $name = $profile?->name ?? config('app.name');
    $initials = \Illuminate\Support\Str::of($name)
        ->explode(' ')
        ->filter()
        ->take(2)
        ->map(fn (string $word) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($word, 0, 1)))
        ->implode('');

    $links = [
        ['label' => 'Home', 'route' => 'home', 'url' => route('home'), 'active' => request()->routeIs('home')],
        ['label' => 'Villas', 'route' => 'villas.index', 'url' => route('villas.index'), 'active' => request()->routeIs('villas.*')],
        ['label' => 'Gallery', 'route' => 'gallery', 'url' => route('gallery'), 'active' => request()->routeIs('gallery')],
        ['label' => 'Location', 'route' => 'location', 'url' => route('location'), 'active' => request()->routeIs('location')],
        ['label' => 'About', 'route' => 'about', 'url' => route('about'), 'active' => request()->routeIs('about')],
        ['label' => 'FAQ', 'route' => 'faq', 'url' => route('faq'), 'active' => request()->routeIs('faq')],
        ['label' => 'Contact', 'route' => 'contact', 'url' => route('contact'), 'active' => request()->routeIs('contact*')],
    ];
@endphp

<header class="sticky top-0 z-30 border-b border-[var(--color-line)]/80 bg-[var(--color-paper)]/95 backdrop-blur">
    <div class="site-container flex h-[4.5rem] items-center justify-between gap-4">
        <a href="{{ route('home') }}" class="flex min-w-0 items-center gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[var(--color-primary)] text-[11px] font-semibold tracking-[0.14em] text-[var(--color-paper)]">
                {{ $initials }}
            </span>
            <span class="min-w-0">
                <span class="block truncate font-serif text-xl leading-none">{{ $name }}</span>
                @if ($profile?->address)
                    <span class="mt-1 hidden truncate text-[10px] uppercase tracking-[0.2em] text-[var(--color-muted)] sm:block">
                        {{ \Illuminate\Support\Str::limit($profile->address, 48) }}
                    </span>
                @endif
            </span>
        </a>

        <nav class="hidden items-center gap-5 lg:flex" aria-label="Main navigation">
            @foreach ($links as $link)
                <a
                    href="{{ $link['url'] }}"
                    @class([
                        'text-[11px] font-semibold uppercase tracking-[0.18em] transition hover:text-[var(--color-primary)]',
                        'text-[var(--color-primary)]' => $link['active'],
                        'text-[var(--color-muted)]' => ! $link['active'],
                    ])
                    @if ($link['active']) aria-current="page" @endif
                >
                    {{ $link['label'] }}
                </a>
            @endforeach
        </nav>

        <div class="flex items-center gap-2">
            <a href="{{ route('villas.index') }}" class="btn-outline hidden !px-5 !py-2.5 sm:inline-flex">
                Explore villas
            </a>

            <details class="group relative lg:hidden">
                <summary class="flex h-10 w-10 cursor-pointer list-none items-center justify-center rounded-full border border-[var(--color-line)] text-[var(--color-primary)] transition hover:border-[var(--color-primary)] [&::-webkit-details-marker]:hidden" aria-label="Open menu">
                    <svg class="h-5 w-5 group-open:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                        <path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16" />
                    </svg>
                    <svg class="hidden h-5 w-5 group-open:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                        <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
                    </svg>
                </summary>

                <div class="absolute right-0 top-[calc(100%+0.75rem)] w-64 overflow-hidden rounded-2xl border border-[var(--color-line)] bg-[var(--color-paper)] p-2 shadow-xl shadow-black/10">
                    @foreach ($links as $link)
                        <a
                            href="{{ $link['url'] }}"
                            @class([
                                'flex items-center justify-between rounded-xl px-4 py-3 text-sm font-medium transition',
                                'bg-[var(--color-primary)] text-[var(--color-paper)]' => $link['active'],
                                'text-[var(--color-ink)] hover:bg-[var(--color-paper-deep)]' => ! $link['active'],
                            ])
                        >
                            {{ $link['label'] }}
                            <svg class="h-4 w-4 opacity-60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                        </a>
                    @endforeach
                    <a href="{{ route('villas.index') }}" class="btn-primary mt-2 w-full">
                        Explore villas
                    </a>
                </div>
            </details>
        </div>
    </div>
</header>

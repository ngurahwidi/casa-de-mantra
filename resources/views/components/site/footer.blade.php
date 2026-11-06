@props(['profile' => null, 'whatsappUrl' => null])

@php
    $name = $profile?->name ?? config('app.name');
    $year = now()->year;
@endphp

<footer class="mt-24 bg-[var(--color-primary)] text-[var(--color-paper)]">
    <div class="site-container py-16 sm:py-20">
        <div class="grid gap-12 border-b border-white/15 pb-12 lg:grid-cols-[1.4fr_1fr_1fr]">
            <div>
                <p class="eyebrow !text-white/60">Stay with us</p>
                <h2 class="mt-3 max-w-md font-serif text-4xl leading-[1.05] sm:text-5xl">{{ $name }}</h2>

                <div class="mt-6 space-y-2 text-sm leading-6 text-white/75">
                    @if ($profile?->address)
                        <p class="max-w-xs">{{ $profile->address }}</p>
                    @endif
                    @if ($profile?->email)
                        <p><a class="transition hover:text-white" href="mailto:{{ $profile->email }}">{{ $profile->email }}</a></p>
                    @endif
                    @if ($profile?->whatsapp)
                        <p><a class="transition hover:text-white" href="tel:{{ $profile->whatsapp }}">{{ $profile->whatsapp }}</a></p>
                    @endif
                </div>
            </div>

            <div>
                <p class="eyebrow !text-white/60">Explore</p>
                <nav class="mt-5 flex flex-col items-start gap-3 text-sm text-white/80" aria-label="Footer navigation">
                    <a class="transition hover:text-white" href="{{ route('home') }}">Home</a>
                    <a class="transition hover:text-white" href="{{ route('villas.index') }}">Villas</a>
                    <a class="transition hover:text-white" href="{{ route('gallery') }}">Gallery</a>
                    <a class="transition hover:text-white" href="{{ route('location') }}">Location</a>
                    <a class="transition hover:text-white" href="{{ route('about') }}">About</a>
                    <a class="transition hover:text-white" href="{{ route('faq') }}">FAQ</a>
                </nav>
            </div>

            <div>
                <p class="eyebrow !text-white/60">Guest information</p>
                <nav class="mt-5 flex flex-col items-start gap-3 text-sm text-white/80" aria-label="Policy navigation">
                    <a class="transition hover:text-white" href="{{ route('policies.index') }}">Policies</a>
                    <a class="transition hover:text-white" href="{{ route('contact') }}">Contact</a>
                    @if ($whatsappUrl)
                        <a class="transition hover:text-white" href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer">WhatsApp</a>
                    @endif
                </nav>

                @if ($whatsappUrl)
                    <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer" class="mt-8 inline-flex items-center gap-2 rounded-full bg-[var(--color-paper)] px-5 py-3 text-xs font-semibold uppercase tracking-[0.16em] text-[var(--color-primary)] transition hover:opacity-90">
                        Chat with us
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 17L17 7M9 7h8v8" />
                        </svg>
                    </a>
                @endif
            </div>
        </div>

        <div class="flex flex-col gap-3 pt-8 text-[11px] uppercase tracking-[0.16em] text-white/50 sm:flex-row sm:items-center sm:justify-between">
            <p>&copy; {{ $year }} {{ $name }}. All rights reserved.</p>
            <p>Direct booking — no platform fees.</p>
        </div>
    </div>
</footer>

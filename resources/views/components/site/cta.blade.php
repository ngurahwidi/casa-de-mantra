@props([
    'eyebrow' => 'Your stay starts here',
    'title' => 'The villa you are looking for might already be waiting.',
    'body' => 'Tell us your dates and who is coming. We will reply with availability, the total price, and the next step.',
    'primaryLabel' => 'Send an enquiry',
    'primaryUrl' => null,
    'whatsappUrl' => null,
])

@php
    $primaryUrl = $primaryUrl ?: route('contact');
@endphp

<section class="py-16 sm:py-24">
    <div class="site-container">
        <div class="overflow-hidden rounded-3xl bg-[var(--color-primary)] px-6 py-14 text-center text-[var(--color-paper)] sm:px-12 sm:py-20">
            <p class="eyebrow !text-white/60">{{ $eyebrow }}</p>
            <h2 class="mx-auto mt-4 max-w-2xl text-balance font-serif text-4xl leading-[1.05] sm:text-5xl">{{ $title }}</h2>
            <p class="mx-auto mt-4 max-w-xl text-sm leading-7 text-white/70">{{ $body }}</p>
            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <a href="{{ $primaryUrl }}" class="inline-flex items-center justify-center rounded-full bg-[var(--color-paper)] px-6 py-3.5 text-[11px] font-semibold uppercase tracking-[0.16em] text-[var(--color-primary)] transition hover:opacity-90">
                    {{ $primaryLabel }}
                </a>
                @if ($whatsappUrl)
                    <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer" class="btn-light">WhatsApp us</a>
                @endif
            </div>
        </div>
    </div>
</section>

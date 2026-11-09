@extends('layouts.public')

@push('structured-data')
    @if ($faqs->isNotEmpty())
        <script type="application/ld+json">
            {!! json_encode([
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => $faqs->map(fn ($faq) => [
                    '@type' => 'Question',
                    'name' => $faq->question,
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => strip_tags($faq->answer),
                    ],
                ])->values()->all(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}
        </script>
    @endif
@endpush

@section('content')
    <section class="border-b border-[var(--color-line)] bg-[var(--color-paper-deep)]">
        <div class="site-container py-14 sm:py-20">
            <nav class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]" aria-label="Breadcrumb">
                <a href="{{ route('home') }}" class="transition hover:text-[var(--color-primary)]">Home</a>
                <span class="mx-2 text-[var(--color-line)]" aria-hidden="true">/</span>
                <span class="text-[var(--color-primary)]" aria-current="page">FAQ</span>
            </nav>

            <div class="mt-8 max-w-3xl">
                <p class="eyebrow">Good to know</p>
                <h1 class="mt-4 text-balance font-serif text-4xl leading-[1.02] sm:text-5xl lg:text-6xl">
                    Questions, answered.
                </h1>
                <p class="mt-5 max-w-xl text-sm leading-7 text-[var(--color-muted)]">
                    The practical details about staying with us. If your question is not here, send us a message — we are happy to help.
                </p>
            </div>
        </div>
    </section>

    <section class="py-14 sm:py-20">
        <div class="site-container grid gap-12 lg:grid-cols-[1.3fr_0.7fr] lg:items-start">
            <div class="divide-y divide-[var(--color-line)] border-y border-[var(--color-line)]">
                @forelse ($faqs as $faq)
                    <details class="group py-5" @if ($loop->first) open @endif>
                        <summary class="flex cursor-pointer list-none items-start justify-between gap-6 text-left font-serif text-xl leading-snug [&::-webkit-details-marker]:hidden">
                            <span>{{ $faq->question }}</span>
                            <svg class="mt-1.5 h-4 w-4 shrink-0 text-[var(--color-muted)] transition group-open:rotate-45" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                                <path stroke-linecap="round" d="M12 5v14M5 12h14" />
                            </svg>
                        </summary>
                        <p class="mt-3 max-w-2xl text-sm leading-7 text-[var(--color-muted)]">{{ $faq->answer }}</p>
                    </details>
                @empty
                    <div class="py-8">
                        <p class="font-serif text-2xl">No questions yet.</p>
                        <p class="mt-2 text-sm text-[var(--color-muted)]">Send us a message and we will answer personally.</p>
                    </div>
                @endforelse
            </div>

            <aside class="rounded-2xl bg-[var(--color-primary)] p-6 text-[var(--color-paper)] sm:p-7">
                <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-white/60">Still wondering?</p>
                <p class="mt-3 font-serif text-2xl leading-tight">Ask us directly.</p>
                <p class="mt-3 text-sm leading-6 text-white/75">
                    Tell us what you need to know about the villa, the area, or your dates. We will reply personally.
                </p>
                <div class="mt-6 flex flex-col gap-3">
                    <a href="{{ route('contact') }}" class="inline-flex items-center justify-center rounded-full bg-[var(--color-paper)] px-5 py-3 text-[11px] font-semibold uppercase tracking-[0.16em] text-[var(--color-primary)] transition hover:opacity-90">
                        Send a message
                    </a>
                    @if ($whatsappUrl)
                        <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center justify-center rounded-full border border-white/30 px-5 py-3 text-[11px] font-semibold uppercase tracking-[0.16em] text-white transition hover:bg-white hover:text-[var(--color-primary)]">
                            WhatsApp us
                        </a>
                    @endif
                </div>
            </aside>
        </div>
    </section>
@endsection

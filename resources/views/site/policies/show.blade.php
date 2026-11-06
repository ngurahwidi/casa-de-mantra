@extends('layouts.public')

@section('content')
    <section class="border-b border-[var(--color-line)] bg-[var(--color-paper-deep)]">
        <div class="site-container py-14 sm:py-20">
            <nav class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]" aria-label="Breadcrumb">
                <a href="{{ route('home') }}" class="transition hover:text-[var(--color-primary)]">Home</a>
                <span class="mx-2 text-[var(--color-line)]">/</span>
                <a href="{{ route('policies.index') }}" class="transition hover:text-[var(--color-primary)]">Policies</a>
                <span class="mx-2 text-[var(--color-line)]">/</span>
                <span class="text-[var(--color-primary)]">{{ $page->title }}</span>
            </nav>

            <div class="mt-8 max-w-2xl">
                <p class="eyebrow">Policy</p>
                <h1 class="mt-4 text-balance font-serif text-4xl leading-[1.02] sm:text-5xl">{{ $page->title }}</h1>
                <p class="mt-4 text-xs text-[var(--color-muted)]">Last updated {{ $page->updated_at?->format('j M Y') }}</p>
            </div>
        </div>
    </section>

    <section class="py-14 sm:py-20">
        <div class="site-container grid gap-10 lg:grid-cols-[0.7fr_1.3fr]">
            <aside>
                <div class="rounded-2xl border border-[var(--color-line)] bg-[#FCFAF4] p-6">
                    <p class="eyebrow">Questions?</p>
                    <p class="mt-3 text-sm leading-6 text-[var(--color-muted)]">If anything is unclear, ask before you send a booking request.</p>
                    <div class="mt-5 flex flex-col gap-3">
                        <a href="{{ route('contact') }}" class="btn-primary w-full">Contact us</a>
                        @if ($whatsappUrl)
                            <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer" class="btn-outline w-full">WhatsApp</a>
                        @endif
                    </div>
                </div>
            </aside>

            <article class="prose-site max-w-2xl">
                {!! $page->body ?: '<p>This policy is being updated. Please contact us for the current details.</p>' !!}
            </article>
        </div>
    </section>
@endsection

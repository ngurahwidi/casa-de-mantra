@extends('layouts.public')

@section('content')
    <section class="border-b border-[var(--color-line)] bg-[var(--color-paper-deep)]">
        <div class="site-container py-14 sm:py-20">
            <nav class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]" aria-label="Breadcrumb">
                <a href="{{ route('home') }}" class="transition hover:text-[var(--color-primary)]">Home</a>
                <span class="mx-2 text-[var(--color-line)]">/</span>
                <span class="text-[var(--color-primary)]">Policies</span>
            </nav>

            <div class="mt-8 max-w-2xl">
                <p class="eyebrow">Before you book</p>
                <h1 class="mt-4 text-balance font-serif text-4xl leading-[1.02] sm:text-5xl lg:text-6xl">The fine print.</h1>
                <p class="mt-5 text-sm leading-7 text-[var(--color-muted)]">
                    The policies that apply to every stay. Please read them before sending a request.
                </p>
            </div>
        </div>
    </section>

    <section class="py-14 sm:py-20">
        <div class="site-container">
            @if ($pages->isNotEmpty())
                <div class="grid gap-5 sm:grid-cols-2">
                    @foreach ($pages as $page)
                        <a href="{{ route('policies.show', $page) }}" class="group rounded-2xl border border-[var(--color-line)] bg-[#FCFAF4] p-6 transition hover:-translate-y-1 hover:border-[var(--color-primary)]/40 hover:shadow-lg hover:shadow-black/5">
                            <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]">Policy</p>
                            <h2 class="mt-3 font-serif text-2xl leading-tight">{{ $page->title }}</h2>
                            <p class="mt-3 line-clamp-3 text-sm leading-6 text-[var(--color-muted)]">{{ \Illuminate\Support\Str::limit(strip_tags($page->body ?? ''), 140) }}</p>
                            <span class="mt-5 inline-flex items-center gap-2 text-[10px] font-semibold uppercase tracking-[0.16em] text-[var(--color-primary)]">
                                Read policy
                                <svg class="h-3.5 w-3.5 transition group-hover:translate-x-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M12 5l7 7-7 7" />
                                </svg>
                            </span>
                        </a>
                    @endforeach
                </div>
            @else
                <div class="rounded-2xl border border-[var(--color-line)] bg-[#FCFAF4] p-8 sm:p-12">
                    <p class="font-serif text-3xl">Policies are being finalised.</p>
                    <p class="mt-3 max-w-xl text-sm leading-7 text-[var(--color-muted)]">Send us a message and we will share the current cancellation and house policies before you book.</p>
                    <a href="{{ route('contact') }}" class="btn-primary mt-6">Ask us</a>
                </div>
            @endif
        </div>
    </section>
@endsection

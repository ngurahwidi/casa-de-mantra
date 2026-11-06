@extends('layouts.public')

@section('content')
    @php($featurePhoto = $villas->map(fn ($villa) => $villa->getFirstMedia(\App\Models\RoomType::GALLERY))->filter()->first())

    <section class="border-b border-[var(--color-line)] bg-[var(--color-paper-deep)]">
        <div class="site-container py-14 sm:py-20">
            <nav class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]" aria-label="Breadcrumb">
                <a href="{{ route('home') }}" class="transition hover:text-[var(--color-primary)]">Home</a>
                <span class="mx-2 text-[var(--color-line)]">/</span>
                <span class="text-[var(--color-primary)]">About</span>
            </nav>

            <div class="mt-8 max-w-3xl">
                <p class="eyebrow">About the villa</p>
                <h1 class="mt-4 text-balance font-serif text-4xl leading-[1.02] sm:text-5xl lg:text-6xl">
                    {{ $page?->title ?: 'A home with a little more room to breathe.' }}
                </h1>
                @if ($profile?->address)
                    <p class="mt-5 text-sm leading-7 text-[var(--color-muted)]">{{ $profile->address }}</p>
                @endif
            </div>
        </div>
    </section>

    <section class="py-14 sm:py-20">
        <div class="site-container grid gap-10 lg:grid-cols-[1.05fr_1fr] lg:items-start">
            <div class="prose-site">
                @if ($page?->body)
                    {!! $page->body !!}
                @else
                    <p>{{ $profile?->name ?? config('app.name') }} is a private stay built around the way a holiday should feel: unhurried mornings, space for everyone, and someone nearby when you need something.</p>
                    <p>We keep the team small on purpose. That means the person who answers your message is often the same person who welcomes you at the gate and helps you plan the rest of your day.</p>
                    <p>Book directly with us and the conversation stays simple — your dates, your questions, and a clear price before anything is confirmed.</p>
                @endif
            </div>

            <div class="grid gap-3">
                <x-site.photo
                    :src="$featurePhoto?->getUrl()"
                    :alt="$featurePhoto?->getCustomProperty('alt') ?: ($profile?->name ?? 'Villa')"
                    ratio="aspect-[4/3]"
                    class="rounded-2xl"
                />
                <div class="grid grid-cols-2 gap-3">
                    @foreach ($villas->take(2) as $villa)
                        @if ($villa->getFirstMedia(\App\Models\RoomType::GALLERY))
                            <x-site.photo
                                :src="$villa->getFirstMediaUrl(\App\Models\RoomType::GALLERY)"
                                :alt="$villa->getFirstMedia(\App\Models\RoomType::GALLERY)?->getCustomProperty('alt') ?: $villa->name"
                                ratio="aspect-square"
                                class="rounded-2xl"
                            />
                        @endif
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <section class="bg-[#E7E9DC] py-16 sm:py-20">
        <div class="site-container">
            <x-site.section-heading
                eyebrow="How we host"
                title="Small team. Personal stay."
                description="The details are handled by people who live here and care about the place."
            />

            <div class="mt-10 grid gap-8 sm:grid-cols-3">
                @foreach ([
                    ['title' => 'Direct conversation', 'body' => 'No call centre. Your questions go straight to the villa team.'],
                    ['title' => 'Clear, honest pricing', 'body' => 'You see the nightly rate and the total before you commit.'],
                    ['title' => 'A thoughtful welcome', 'body' => 'Local recommendations, transfer help, and a villa ready for you.'],
                ] as $item)
                    <div class="border-t border-[var(--color-primary)]/20 pt-5">
                        <p class="font-serif text-2xl leading-tight">{{ $item['title'] }}</p>
                        <p class="mt-3 text-sm leading-6 text-[var(--color-muted)]">{{ $item['body'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    @if ($amenities->isNotEmpty())
        <section class="py-16 sm:py-20">
            <div class="site-container grid gap-10 lg:grid-cols-[0.7fr_1.3fr]">
                <x-site.section-heading
                    eyebrow="The essentials"
                    title="Everything already here."
                    description="The practical things that make unpacking and settling in easier."
                />

                <ul class="grid gap-x-8 gap-y-4 border-t border-[var(--color-line)] pt-8 text-sm sm:grid-cols-2">
                    @foreach ($amenities as $amenity)
                        <li class="flex items-start gap-3 leading-6">
                            <svg class="mt-1 h-4 w-4 shrink-0 text-[var(--color-primary)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 12.5l4.2 4.2L19 7" />
                            </svg>
                            <span>{{ $amenity->name }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    @if ($reviews->isNotEmpty())
        <section class="border-y border-[var(--color-line)] bg-[var(--color-paper-deep)] py-16 sm:py-20">
            <div class="site-container">
                <x-site.section-heading eyebrow="Guest words" title="What stays with people." />

                <div class="mt-10 grid gap-6 md:grid-cols-3">
                    @foreach ($reviews as $review)
                        <figure class="rounded-2xl border border-[var(--color-line)] bg-[#FCFAF4] p-6">
                            <div class="flex items-center gap-1 text-[var(--color-primary)]" aria-label="{{ $review->rating }} out of 5 stars">
                                @for ($i = 1; $i <= 5; $i++)
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="{{ $i <= $review->rating ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="1" aria-hidden="true">
                                        <path d="M10 1.8l2.5 5.2 5.7.8-4.1 4 1 5.7-5.1-2.7-5.1 2.7 1-5.7-4.1-4 5.7-.8L10 1.8z" />
                                    </svg>
                                @endfor
                            </div>
                            <blockquote class="mt-4 font-serif text-xl leading-snug">
                                “{{ $review->comment ?: 'A lovely stay.' }}”
                            </blockquote>
                            <figcaption class="mt-4 text-[10px] font-semibold uppercase tracking-[0.16em] text-[var(--color-muted)]">
                                {{ $review->guest_name }}{{ $review->source ? ' · '.$review->source : '' }}
                            </figcaption>
                        </figure>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <section class="py-16 sm:py-20">
        <div class="site-container flex flex-col items-start justify-between gap-6 rounded-2xl bg-[var(--color-primary)] px-6 py-10 text-[var(--color-paper)] sm:flex-row sm:items-center sm:px-10">
            <div>
                <p class="font-serif text-3xl leading-tight">Come and see for yourself.</p>
                <p class="mt-2 text-sm text-white/70">Tell us your dates and we will help with the rest.</p>
            </div>
            <a href="{{ route('contact') }}" class="inline-flex shrink-0 items-center justify-center rounded-full bg-[var(--color-paper)] px-6 py-3.5 text-[11px] font-semibold uppercase tracking-[0.16em] text-[var(--color-primary)] transition hover:opacity-90">
                Get in touch
            </a>
        </div>
    </section>
@endsection

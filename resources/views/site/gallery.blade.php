@extends('layouts.public')

@section('content')
    <section class="border-b border-[var(--color-line)] bg-[var(--color-paper-deep)]">
        <div class="site-container py-14 sm:py-20">
            <nav class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--color-muted)]" aria-label="Breadcrumb">
                <a href="{{ route('home') }}" class="transition hover:text-[var(--color-primary)]">Home</a>
                <span class="mx-2 text-[var(--color-line)]" aria-hidden="true">/</span>
                <span class="text-[var(--color-primary)]" aria-current="page">Gallery</span>
            </nav>

            <div class="mt-8 grid gap-6 lg:grid-cols-[1.2fr_0.8fr] lg:items-end">
                <x-site.section-heading
                    as="h1"
                    eyebrow="A look around"
                    title="The villa, in pictures."
                    description="Gardens, pools, bedrooms, and the small details that make a stay feel easy."
                />
                <p class="text-sm leading-7 text-[var(--color-muted)] lg:pb-2">
                    Every photo is from the property. If you would like to see something specific, just ask.
                </p>
            </div>
        </div>
    </section>

    <section class="py-14 sm:py-20">
        <div class="site-container">
            @forelse ($groups as $group)
                <div class="{{ $loop->first ? '' : 'mt-16 border-t border-[var(--color-line)] pt-16' }}">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="eyebrow">{{ $group['villa']->name }}</p>
                            <h2 class="mt-2 font-serif text-3xl">{{ $group['villa']->bedrooms }} {{ \Illuminate\Support\Str::plural('bedroom', $group['villa']->bedrooms) }} villa</h2>
                        </div>
                        <a href="{{ route('villas.show', $group['villa']->slug) }}" class="inline-flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.16em] text-[var(--color-primary)]">
                            View villa
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M12 5l7 7-7 7" />
                            </svg>
                        </a>
                    </div>

                    <div class="mt-8 columns-1 gap-4 sm:columns-2 lg:columns-3">
                        @foreach ($group['photos'] as $photo)
                            <x-site.photo
                                :media="$photo['media']"
                                :alt="$photo['alt']"
                                sizes="(min-width: 1024px) 350px, (min-width: 640px) 50vw, 100vw"
                                :ratio="$loop->index % 3 === 0 ? 'aspect-[4/5]' : 'aspect-[4/3]'"
                                class="mb-4 break-inside-avoid rounded-xl"
                            />
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="rounded-2xl border border-[var(--color-line)] bg-[#FCFAF4] p-8 sm:p-12">
                    <p class="font-serif text-3xl">Photos are on their way.</p>
                    <p class="mt-3 max-w-xl text-sm leading-7 text-[var(--color-muted)]">
                        We are adding new pictures of the villa. In the meantime, send us a message and we will happily share more.
                    </p>
                    <a href="{{ route('contact') }}" class="btn-primary mt-6">Ask for photos</a>
                </div>
            @endforelse
        </div>
    </section>
@endsection

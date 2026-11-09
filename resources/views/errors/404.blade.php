@extends('layouts.public')

@php
    $title = 'Page not found';
    $robots = 'noindex,follow';
@endphp

@section('content')
    <section class="py-20 sm:py-32">
        <div class="site-container max-w-2xl text-center">
            <p class="eyebrow">Error 404</p>
            <h1 class="mt-4 text-balance font-serif text-4xl leading-[1.05] sm:text-6xl">We can't find that page.</h1>
            <p class="mx-auto mt-5 max-w-md text-pretty text-sm leading-7 text-[var(--color-muted)] sm:text-base">
                The link may be out of date, or the page may have moved. Everything you need to plan your stay is still here.
            </p>

            <div class="mt-9 flex flex-col justify-center gap-3 sm:flex-row">
                <a href="{{ route('home') }}" class="btn-primary">Back to home</a>
                <a href="{{ route('villas.index') }}" class="btn-outline">Explore villas</a>
            </div>

            <p class="mt-9 text-sm leading-7 text-[var(--color-muted)]">
                Looking for a reservation?
                <a href="{{ route('booking.status') }}" class="font-medium text-[var(--color-primary)] underline underline-offset-4">Check your booking</a>
                or
                <a href="{{ route('contact') }}" class="font-medium text-[var(--color-primary)] underline underline-offset-4">contact us</a>.
            </p>
        </div>
    </section>
@endsection

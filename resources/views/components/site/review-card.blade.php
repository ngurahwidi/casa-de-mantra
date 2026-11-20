@props(['review'])

<article {{ $attributes->merge([
    'class' => 'flex flex-col rounded-2xl border border-[var(--color-line)] bg-[#FCFAF4] p-6 shadow-sm',
    'data-review-card' => '',
]) }}>
    <div class="flex items-center gap-1 text-[var(--color-rating)]" aria-label="{{ $review->rating }} out of 5 stars">
        @for ($i = 1; $i <= 5; $i++)
            <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="{{ $i <= $review->rating ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="1.2" aria-hidden="true">
                <path d="M10 1.8l2.5 5.2 5.7.8-4.1 4 1 5.7-5.1-2.7-5.1 2.7 1-5.7-4.1-4 5.7-.8L10 1.8z" />
            </svg>
        @endfor
    </div>

    <blockquote class="mt-5 flex-1">
        <p class="line-clamp-6 text-sm leading-7 text-[var(--color-ink)]/90">“{{ $review->comment }}”</p>
    </blockquote>

    <div class="mt-6 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-t border-[var(--color-line)] pt-4">
        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-[var(--color-ink)]">{{ $review->guest_name }}</p>
        @if ($review->source)
            <p class="text-[10px] uppercase tracking-[0.14em] text-[var(--color-muted)]">via {{ $review->source }}</p>
        @endif
    </div>
</article>

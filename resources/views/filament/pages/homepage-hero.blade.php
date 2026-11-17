{{--
    Preview of the homepage hero on the "Homepage" admin page (Homepage::hero()).
    The admin panel ships precompiled CSS, so the preview is styled here with Filament's colour variables.
--}}
@php
    /** @var \App\Data\Site\HomepageHeroContent $hero */
@endphp

<div class="homepage-hero">
    <style>
        .homepage-hero { display: grid; gap: 1rem; --hh-border: var(--gray-200); }
        .dark .homepage-hero { --hh-border: var(--gray-700); }
        .homepage-hero__preview { position: relative; isolation: isolate; overflow: hidden; display: flex; flex-direction: column; justify-content: flex-end; min-height: 18rem; padding: 1.5rem; border-radius: 0.75rem; background: var(--gray-800); color: #fff; }
        .homepage-hero__image { position: absolute; inset: 0; z-index: -2; width: 100%; height: 100%; object-fit: cover; }
        .homepage-hero__shade { position: absolute; inset: 0; z-index: -1; background: linear-gradient(to top, rgb(0 0 0 / 0.8), rgb(0 0 0 / 0.35), rgb(0 0 0 / 0.1)); }
        .homepage-hero__title { max-width: 36rem; font-family: ui-serif, Georgia, serif; font-size: 2rem; line-height: 1.05; text-wrap: balance; }
        .homepage-hero__subtitle { max-width: 32rem; margin-top: 0.75rem; font-size: 0.875rem; line-height: 1.6; color: rgb(255 255 255 / 0.8); text-wrap: pretty; }
        .homepage-hero__facts { display: grid; gap: 0.5rem; font-size: 0.875rem; }
        .homepage-hero__fact { display: flex; flex-wrap: wrap; gap: 0.25rem 0.75rem; padding: 0.625rem 0.875rem; border: 1px solid var(--hh-border); border-radius: 0.5rem; }
        .homepage-hero__label { font-weight: 500; min-width: 6rem; }
        .homepage-hero__value { color: var(--gray-500); }
    </style>

    <div class="homepage-hero__preview">
        @if ($hero->banner)
            <img class="homepage-hero__image" src="{{ $hero->banner->getUrl() }}" alt="{{ $hero->bannerAlt }}">
        @endif
        <div class="homepage-hero__shade"></div>

        <p class="homepage-hero__title">{{ $hero->title }}</p>
        <p class="homepage-hero__subtitle">{{ $hero->subtitle }}</p>
    </div>

    <div class="homepage-hero__facts">
        <div class="homepage-hero__fact">
            <span class="homepage-hero__label">Banner</span>
            <span class="homepage-hero__value">
                @if ($hero->hasCustomBanner)
                    Uploaded banner{{ $hero->banner?->getCustomProperty('alt') ? '' : ' (no alt text yet)' }}
                @elseif ($hero->banner)
                    None uploaded, showing the first villa photo
                @else
                    None uploaded and no villa photos yet, showing a plain background
                @endif
            </span>
        </div>
        <div class="homepage-hero__fact">
            <span class="homepage-hero__label">Title</span>
            <span class="homepage-hero__value">{{ $hero->hasCustomTitle ? 'Custom' : 'Built-in wording' }}</span>
        </div>
        <div class="homepage-hero__fact">
            <span class="homepage-hero__label">Subtitle</span>
            <span class="homepage-hero__value">{{ $hero->hasCustomSubtitle ? 'Custom' : 'Built-in wording' }}</span>
        </div>
    </div>
</div>

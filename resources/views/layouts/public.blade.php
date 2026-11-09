<?php

$defaultTitle = $seoDefaults['title'] ?? config('app.name');
$pageTitle = isset($title) && $title !== ''
    ? $title.' — '.($profile?->name ?? config('app.name'))
    : $defaultTitle;
$pageDescription = $description ?? ($seoDefaults['description'] ?? null);
$canonicalUrl = $canonical ?? url()->current();
$shareImage = $ogImage ?? ($seoDefaults['image'] ?? null);
$shareType = $ogType ?? 'website';

['primary' => $primary, 'accent' => $accent] = \App\Support\SiteTheme::colors($profile);

$shareImage = $shareImage ? (str_starts_with($shareImage, 'http') ? $shareImage : url($shareImage)) : null;
$jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;

if (isset($defaultSchema) && is_array($defaultSchema)) {
    $defaultSchema['url'] = $canonicalUrl;
}
?>
<!DOCTYPE html>
<html lang="en" class="motion-safe:scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light">
        <meta name="theme-color" content="{{ $primary }}">

        <title>{{ $pageTitle }}</title>
        @if ($pageDescription)
            <meta name="description" content="{{ $pageDescription }}">
        @endif
        <link rel="canonical" href="{{ $canonicalUrl }}">
        <meta name="robots" content="{{ $robots ?? 'index,follow' }}">

        <meta property="og:type" content="{{ $shareType }}">
        <meta property="og:site_name" content="{{ $profile?->name ?? config('app.name') }}">
        <meta property="og:title" content="{{ $pageTitle }}">
        @if ($pageDescription)
            <meta property="og:description" content="{{ $pageDescription }}">
        @endif
        <meta property="og:url" content="{{ $canonicalUrl }}">
        @if ($shareImage)
            <meta property="og:image" content="{{ $shareImage }}">
        @endif

        <meta name="twitter:card" content="{{ $shareImage ? 'summary_large_image' : 'summary' }}">
        <meta name="twitter:title" content="{{ $pageTitle }}">
        @if ($pageDescription)
            <meta name="twitter:description" content="{{ $pageDescription }}">
        @endif
        @if ($shareImage)
            <meta name="twitter:image" content="{{ $shareImage }}">
        @endif

        <style>
            :root {
                --color-primary: {{ $primary }};
                --color-accent: {{ $accent }};
                --color-paper: #F6F2E9;
                --color-paper-deep: #EFEADF;
                --color-ink: #2B312A;
                --color-muted: #5E6257;
                --color-line: #DED8CB;
            }
        </style>

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @if ($defaultSchema)
            <script type="application/ld+json">{!! json_encode($defaultSchema, $jsonFlags) !!}</script>
        @endif
        @stack('structured-data')
        @stack('head')
    </head>
    <body class="flex min-h-screen flex-col bg-[var(--color-paper)] font-sans text-[var(--color-ink)] antialiased">
        <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-full focus:bg-[var(--color-primary)] focus:px-5 focus:py-3 focus:text-sm focus:font-semibold focus:text-[var(--color-paper)]">
            Skip to content
        </a>

        <x-site.header :profile="$profile" />

        <main id="main-content" class="flex-1">
            @yield('content')
        </main>

        <x-site.footer :profile="$profile" :whatsapp-url="$whatsappUrl" />

        <x-site.whatsapp-button :url="$whatsappUrl" />

        @stack('scripts')
    </body>
</html>

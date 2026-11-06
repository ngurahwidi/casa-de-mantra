<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url>
        <loc>{{ route('home') }}</loc>
        <lastmod>{{ now()->toAtomString() }}</lastmod>
        <changefreq>weekly</changefreq>
        <priority>1.0</priority>
    </url>
    <url>
        <loc>{{ route('villas.index') }}</loc>
        <lastmod>{{ now()->toAtomString() }}</lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.9</priority>
    </url>
    @foreach ($villas as $villa)
        <url>
            <loc>{{ route('villas.show', $villa->slug) }}</loc>
            <lastmod>{{ $villa->updated_at?->toAtomString() ?? now()->toAtomString() }}</lastmod>
            <changefreq>weekly</changefreq>
            <priority>0.8</priority>
        </url>
    @endforeach
    @foreach ([
        route('gallery') => 0.7,
        route('location') => 0.7,
        route('about') => 0.7,
        route('faq') => 0.6,
        route('contact') => 0.6,
        route('policies.index') => 0.4,
    ] as $url => $priority)
        <url>
            <loc>{{ $url }}</loc>
            <lastmod>{{ now()->toAtomString() }}</lastmod>
            <changefreq>monthly</changefreq>
            <priority>{{ number_format($priority, 1) }}</priority>
        </url>
    @endforeach
    @foreach ($pages as $page)
        <url>
            <loc>{{ route('policies.show', $page->slug) }}</loc>
            <lastmod>{{ $page->updated_at?->toAtomString() ?? now()->toAtomString() }}</lastmod>
            <changefreq>yearly</changefreq>
            <priority>0.4</priority>
        </url>
    @endforeach
</urlset>

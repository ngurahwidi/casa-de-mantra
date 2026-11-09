{{-- Self-contained on purpose: this page must still render when the database, cache or Vite build is the cause of the error. --}}
@php
    $profile = rescue(fn () => \App\Models\PropertyProfile::current(), null, false);
    $name = $profile?->name ?? config('app.name');
    ['primary' => $primary] = \App\Support\SiteTheme::colors($profile);
    $whatsappUrl = \App\Support\WhatsApp::url($profile);
@endphp
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <meta name="theme-color" content="{{ $primary }}">
        <title>Something went wrong — {{ $name }}</title>
        <style>
            *, ::before, ::after { box-sizing: border-box; }
            body {
                margin: 0;
                min-height: 100vh;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 2.5rem;
                padding: 3rem 1.25rem;
                background: #F6F2E9;
                color: #2B312A;
                font-family: ui-sans-serif, system-ui, sans-serif;
                text-align: center;
            }
            main { max-width: 32rem; }
            .brand, h1 { font-family: 'Iowan Old Style', 'Palatino Linotype', 'Book Antiqua', Palatino, Georgia, ui-serif, serif; }
            .brand { margin: 0; font-size: 1.25rem; }
            .eyebrow { margin: 0; color: #5E6257; font-size: 0.75rem; font-weight: 600; letter-spacing: 0.2em; text-transform: uppercase; }
            h1 { margin: 1rem 0 0; font-size: clamp(2.25rem, 8vw, 3.5rem); font-weight: 400; line-height: 1.05; }
            p.body { margin: 1.25rem 0 0; color: #5E6257; font-size: 1rem; line-height: 1.75; }
            .actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 0.75rem; margin-top: 2.25rem; }
            .button { border: 1px solid {{ $primary }}; border-radius: 999px; padding: 0.9rem 1.5rem; color: {{ $primary }}; font-size: 0.75rem; font-weight: 600; letter-spacing: 0.14em; text-decoration: none; text-transform: uppercase; }
            .button.primary { background: {{ $primary }}; color: #F6F2E9; }
            a:focus-visible { outline: 2px solid {{ $primary }}; outline-offset: 3px; }
        </style>
    </head>
    <body>
        <p class="brand">{{ $name }}</p>

        <main>
            <p class="eyebrow">Error 500</p>
            <h1>Something went wrong on our side.</h1>
            <p class="body">
                We could not load this page. Please try again in a few minutes. If you were sending a booking request,
                contact us before sending it again.
            </p>

            <div class="actions">
                <a class="button primary" href="{{ url('/') }}">Back to home</a>
                @if ($whatsappUrl)
                    <a class="button" href="{{ $whatsappUrl }}" rel="noopener noreferrer">Chat on WhatsApp</a>
                @elseif ($profile?->email)
                    <a class="button" href="mailto:{{ $profile->email }}">Email us</a>
                @endif
            </div>
        </main>
    </body>
</html>

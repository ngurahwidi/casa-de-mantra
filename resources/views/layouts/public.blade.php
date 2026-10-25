<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title', config('app.name'))</title>
        <meta name="description" content="@yield('description')">

        @stack('meta')

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @stack('head')
    </head>
    <body class="flex min-h-screen flex-col bg-stone-50 font-sans text-stone-800 antialiased">
        <header class="border-b border-stone-200 bg-white">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-4 sm:px-6">
                <a href="{{ route('home') }}" class="text-lg font-semibold tracking-tight">
                    {{ config('app.name') }}
                </a>
            </div>
        </header>

        <main class="flex-1">
            @yield('content')
        </main>

        <footer class="border-t border-stone-200 bg-white">
            <div class="mx-auto max-w-6xl px-4 py-6 text-sm text-stone-500 sm:px-6">
                &copy; {{ now()->year }} {{ config('app.name') }}
            </div>
        </footer>

        @stack('scripts')
    </body>
</html>

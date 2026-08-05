@php
    use App\Services\SiteIdentityService;

    $siteIdentity = app(SiteIdentityService::class);
    $siteIdentitySettings = $siteIdentity->all();
    $siteName = $siteIdentity->siteNameForLocale();
    $isMarathiLocale = \App\Support\LocalizedText::isMarathiLoose();
    $devanagariClass = $isMarathiLocale ? 'font-devanagari' : '';

    $sections = $document['sections'] ?? [];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $document['title'] }} — {{ $siteName }}</title>
        @section('og_title'){{ $document['title'] }} — {{ $siteName }}@endsection
        @section('og_description'){{ $document['summary'] ?? '' }}@endsection
        @include('layouts.partials.site-identity-head')

        <link rel="canonical" href="{{ $meta['url'] ?? url()->current() }}">
        <meta name="robots" content="index, follow">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />
        <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet" />

        {{-- Same Vite guard the homepage uses: a missing build must not 500 a page
             that external reviewers are fetching. --}}
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @else
            <style>
                *,::before,::after{box-sizing:border-box}
                body{margin:0;font-family:system-ui,sans-serif;line-height:1.6;color:#201a1a;padding:1.5rem}
            </style>
        @endif

        <style>
            :root { --brand-red: #b91c1c; --ink: #201a1a; }
            .font-devanagari { font-family: 'Noto Sans Devanagari', 'Instrument Sans', sans-serif; }
            .legal-prose p { margin: 0 0 0.9rem; }
            .legal-prose li { margin: 0 0 0.5rem; }
        </style>
    </head>
    <body class="bg-white text-[#201a1a] antialiased">

        <header class="border-b border-zinc-200 bg-white">
            <div class="mx-auto flex max-w-4xl flex-wrap items-center justify-between gap-3 px-4 py-4 sm:px-6">
                <a href="{{ url('/') }}" class="{{ $devanagariClass }} text-lg font-extrabold text-[color:var(--brand-red)]">
                    {{ $siteName }}
                </a>
                <x-language-switcher :on-red="false" />
            </div>
        </header>

        <main class="mx-auto max-w-4xl px-4 py-8 sm:px-6 sm:py-12">

            <nav class="mb-6 text-xs text-zinc-500">
                <a href="{{ url('/') }}" class="hover:underline">{{ __('legal.common.home') }}</a>
                <span class="mx-1">/</span>
                <span class="{{ $devanagariClass }}">{{ $document['title'] }}</span>
            </nav>

            <h1 class="{{ $devanagariClass }} text-2xl font-extrabold leading-tight text-[#201a1a] sm:text-3xl">
                {{ $document['title'] }}
            </h1>

            @if (! empty($document['summary']))
                <p class="{{ $devanagariClass }} mt-3 max-w-3xl text-sm leading-7 text-zinc-600">
                    {{ $document['summary'] }}
                </p>
            @endif

            <dl class="mt-5 flex flex-wrap gap-x-8 gap-y-2 rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 text-xs text-zinc-600">
                <div>
                    <dt class="{{ $devanagariClass }} font-semibold uppercase tracking-wide text-zinc-500">{{ __('legal.common.version') }}</dt>
                    <dd class="mt-0.5 font-mono">{{ $meta['version'] }}</dd>
                </div>
                <div>
                    <dt class="{{ $devanagariClass }} font-semibold uppercase tracking-wide text-zinc-500">{{ __('legal.common.effective_from') }}</dt>
                    <dd class="mt-0.5 font-mono">{{ $meta['effective_from'] }}</dd>
                </div>
                <div>
                    <dt class="{{ $devanagariClass }} font-semibold uppercase tracking-wide text-zinc-500">{{ __('legal.common.last_updated') }}</dt>
                    <dd class="mt-0.5 font-mono">{{ $meta['last_updated'] }}</dd>
                </div>
            </dl>

            <div class="legal-prose mt-8 space-y-8">
                @foreach ($sections as $section)
                    <section>
                        @if (! empty($section['heading']))
                            <h2 class="{{ $devanagariClass }} text-base font-bold leading-snug text-[#201a1a] sm:text-lg">
                                {{ $section['heading'] }}
                            </h2>
                        @endif

                        @foreach ((array) ($section['body'] ?? []) as $paragraph)
                            <p class="{{ $devanagariClass }} mt-3 text-sm leading-7 text-zinc-700">{{ $paragraph }}</p>
                        @endforeach

                        @if (! empty($section['facts']))
                            <dl class="mt-4 grid gap-3 rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-4 sm:grid-cols-2">
                                @foreach ($section['facts'] as $factLabel => $factValue)
                                    <div>
                                        <dt class="{{ $devanagariClass }} text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ $factLabel }}</dt>
                                        <dd class="{{ $devanagariClass }} mt-0.5 break-words text-sm text-zinc-800">{{ $factValue }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        @endif

                        @if (! empty($section['list']))
                            <ul class="mt-3 list-disc space-y-1 pl-5 text-sm leading-7 text-zinc-700">
                                @foreach ($section['list'] as $item)
                                    <li class="{{ $devanagariClass }}">{{ $item }}</li>
                                @endforeach
                            </ul>
                        @endif

                        @foreach ((array) ($section['after'] ?? []) as $paragraph)
                            <p class="{{ $devanagariClass }} mt-3 text-sm leading-7 text-zinc-700">{{ $paragraph }}</p>
                        @endforeach
                    </section>
                @endforeach
            </div>

            @if (! empty($legalLinks))
                <nav class="mt-12 border-t border-zinc-200 pt-6">
                    <p class="{{ $devanagariClass }} text-xs font-bold uppercase tracking-wide text-zinc-500">{{ __('legal.common.other_documents') }}</p>
                    <ul class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-sm">
                        @foreach ($legalLinks as $linkKey => $link)
                            @continue($linkKey === $documentKey)
                            <li>
                                <a href="{{ $link['url'] }}" class="{{ $devanagariClass }} text-[color:var(--brand-red)] hover:underline">{{ $link['label'] }}</a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            @endif
        </main>

        <footer class="bg-zinc-950 px-4 py-8 text-sm text-zinc-400 sm:px-6">
            <div class="mx-auto max-w-4xl">
                <p class="font-devanagari text-base font-bold text-white">{{ $siteIdentitySettings['company_name'] ?: $siteName }}</p>
                <p class="{{ $devanagariClass }} mt-2 text-xs leading-6 text-zinc-500">{{ __('legal.common.footer_entity', ['entity' => config('legal.entity.legal_name')]) }}</p>
                <div class="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-xs">
                    @foreach ($legalLinks as $link)
                        <a href="{{ $link['url'] }}" class="{{ $devanagariClass }} text-white hover:underline">{{ $link['label'] }}</a>
                    @endforeach
                </div>
                <div class="mt-6 border-t border-zinc-800 pt-5 text-xs text-zinc-600">
                    {{ $siteIdentity->copyrightText() }}
                </div>
            </div>
        </footer>
    </body>
</html>

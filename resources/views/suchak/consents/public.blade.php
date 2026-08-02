{{--
    The consent letter, and the only page the person being represented ever
    sees before their biodata is shown to anyone.

    It used to carry its own `$isMr ? [...] : [...]` array — a SECOND
    translation mechanism beside __(), which meant an admin could not correct
    the wording of a consent letter from the translations table, and the
    gendered name label arriving from the controller was Marathi-only anyway.
    Every sentence now comes from `suchak.public_pages.consent.*`.
--}}
@php
    $siteIdentityLayout = app(\App\Services\SiteIdentityService::class);
    $guestBackgroundImageUrl = $siteIdentityLayout->assetUrl('auth_background_image');
    $faviconUrl = $siteIdentityLayout->assetUrl('favicon');
    $suchakSummary = $summary['suchak'] ?? [];
    $profileSummary = $summary['profile'] ?? [];
    $suchakDisplayName = trim((string) ($suchakSummary['name'] ?? __('profile.suchak_default_name')));
    $suchakBusinessName = trim((string) ($suchakSummary['business_name'] ?? ''));
    $suchakAddress = trim((string) ($suchakSummary['address'] ?? ''));
    $suchakMaskedMobile = trim((string) ($suchakSummary['masked_mobile'] ?? ''));
    $photoPath = trim((string) ($suchakSummary['photo_path'] ?? ''));
    $suchakPhotoUrl = $photoPath !== ''
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($photoPath)
        : asset('images/placeholders/default-profile.svg');
    $suchakPhotoUrl = str_starts_with($suchakPhotoUrl, 'http') ? $suchakPhotoUrl : url($suchakPhotoUrl);
    $publicUrl = route('suchak.consents.public.show', ['token' => $token]);

    $pageTitle = __('suchak.public_pages.consent.title');
    $ogDescription = __('suchak.public_pages.consent.og_description');

    // "Not available" is one pair of words for the whole product, so it is read
    // from the shared label rather than restated per page.
    $notAvailable = __('suchak.labels.common.not_available');

    // The controller sends the gender-specific key (bride / groom / candidate);
    // the wording of that label belongs to the page's own vocabulary.
    $profileNameLabelKey = trim((string) ($profileSummary['name_label_key'] ?? 'candidate'));
    $profileNameLabel = __('suchak.public_pages.consent.name_label.'.$profileNameLabelKey);
    $profileName = trim((string) ($profileSummary['name'] ?? $notAvailable));
    $profileAge = trim((string) ($profileSummary['age'] ?? $notAvailable));
    $profilePhotoUrl = trim((string) ($profileSummary['photo_url'] ?? ''));
    $profilePhotoUrl = $profilePhotoUrl !== '' ? (str_starts_with($profilePhotoUrl, 'http') ? $profilePhotoUrl : url($profilePhotoUrl)) : asset('images/placeholders/default-profile.svg');
    $suchakPrimaryLine = $suchakBusinessName !== '' ? $suchakBusinessName : $suchakDisplayName;
    $suchakSecondaryLine = $suchakBusinessName !== '' && $suchakBusinessName !== $suchakDisplayName ? $suchakDisplayName : '';
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $pageTitle }}</title>
        @if ($faviconUrl)
            <link rel="icon" href="{{ $faviconUrl }}">
        @endif
        <meta name="description" content="{{ $ogDescription }}">
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ $publicUrl }}">
        <meta property="og:site_name" content="{{ $suchakDisplayName }}">
        <meta property="og:title" content="{{ $pageTitle }}">
        <meta property="og:description" content="{{ $ogDescription }}">
        <meta property="og:image" content="{{ $suchakPhotoUrl }}">
        <meta name="twitter:card" content="summary_large_image">
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <main class="relative min-h-screen bg-gray-100 dark:bg-gray-900">
            @if ($guestBackgroundImageUrl)
                <div class="absolute inset-0 bg-cover bg-center bg-no-repeat" style="background-image: url('{{ $guestBackgroundImageUrl }}');" aria-hidden="true"></div>
                <div class="absolute inset-0 bg-white/80 dark:bg-gray-950/86" aria-hidden="true"></div>
            @endif

            <div class="relative z-10 mx-auto flex min-h-screen w-full max-w-4xl items-center px-3 py-3">
                <section class="w-full rounded-xl border border-gray-200 bg-white/96 p-3 shadow-xl backdrop-blur-sm dark:border-gray-700 dark:bg-gray-800/96 sm:p-4">
                    <header class="flex flex-col gap-3 border-b border-gray-200 pb-3 dark:border-gray-700 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex min-w-0 items-center gap-3">
                            <img src="{{ $suchakPhotoUrl }}" alt="" class="h-12 w-12 shrink-0 rounded-full border border-gray-200 object-cover shadow-sm dark:border-gray-700">
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('nav.suchak') }}</p>
                                <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                    <p class="truncate text-base font-semibold text-gray-950 dark:text-gray-50">{{ $suchakPrimaryLine }}</p>
                                    @if ($suchakSecondaryLine !== '')
                                        <span class="text-xs text-gray-400">•</span>
                                        <p class="truncate text-sm text-gray-700 dark:text-gray-200">{{ $suchakSecondaryLine }}</p>
                                    @endif
                                </div>
                                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-600 dark:text-gray-300">
                                    @if ($suchakAddress !== '')
                                        <span>{{ $suchakAddress }}</span>
                                    @endif
                                    @if ($suchakMaskedMobile !== '')
                                        <span>{{ __('suchak.public_pages.consent.mobile') }}: {{ $suchakMaskedMobile }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <x-language-switcher :on-red="false" />
                    </header>

                    <div class="mt-3">
                        <h1 class="text-2xl font-bold leading-tight text-gray-950 dark:text-gray-50">{{ $pageTitle }}</h1>
                        <p class="mt-1 text-sm leading-5 text-gray-600 dark:text-gray-300">{{ __('suchak.public_pages.consent.intro') }}</p>
                    </div>

                    @if ($message)
                        <div class="mt-2 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                            {{ $message }}
                        </div>
                    @endif

                    @if ($state === 'invalid')
                        <div class="mt-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900 shadow-sm dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">{{ __('suchak.public_pages.link_invalid') }}</div>
                    @elseif ($state === 'expired')
                        <div class="mt-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 shadow-sm dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">{{ __('suchak.public_pages.link_expired') }}</div>
                    @elseif ($state === \App\Models\SuchakConsent::STATUS_ACCEPTED)
                        <div class="mt-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-900 shadow-sm dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">{{ __('suchak.public_pages.consent.accepted') }}</div>
                    @elseif ($state === \App\Models\SuchakConsent::STATUS_REJECTED)
                        <div class="mt-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-900 shadow-sm dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">{{ __('suchak.public_pages.consent.rejected') }}</div>
                    @elseif (in_array($state, [\App\Models\SuchakConsent::STATUS_REVOKED, \App\Models\SuchakConsent::STATUS_CANCELLED], true))
                        <div class="mt-2 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-800 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">{{ __('suchak.public_pages.consent.inactive') }}</div>
                    @endif

                    @if ($consent)
                        <section class="mt-3 rounded-lg border border-gray-200 bg-gray-50 p-3 shadow-sm dark:border-gray-700 dark:bg-gray-950">
                            <div class="flex items-center gap-3">
                                <img src="{{ $profilePhotoUrl }}" alt="" class="h-12 w-12 shrink-0 rounded-md border border-gray-200 object-cover shadow-sm dark:border-gray-700">
                                <div class="min-w-0 flex-1">
                                    <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('suchak.public_pages.consent.profile_card') }}</h2>
                                    <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-800 dark:text-gray-100">
                                        <p class="min-w-0">
                                            <span class="font-semibold">{{ $profileNameLabel }}:</span>
                                            <span>{{ $profileName }}</span>
                                        </p>
                                        <p>
                                            <span class="font-semibold">{{ __('suchak.public_pages.consent.age') }}:</span>
                                            {{-- Latin digits, always: the age is a number, not a word. --}}
                                            <span>{{ $profileAge }}@if ($profileAge !== $notAvailable) {{ __('wizard.years') }}@endif</span>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="mt-3 rounded-lg border border-gray-200 bg-white p-3 text-sm text-gray-700 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                            <h2 class="text-base font-semibold text-gray-950 dark:text-gray-50">{{ __('suchak.public_pages.consent.consent_text') }}</h2>
                            {{-- The Suchak's name is a placeholder, not a glued fragment: it does not sit in the same position in both languages. --}}
                            <p class="mt-2 leading-5">{{ __('suchak.public_pages.consent.consent_intro', ['suchak_name' => $suchakDisplayName]) }}</p>
                            <p class="mt-2 font-semibold text-gray-950 dark:text-gray-100">{{ __('suchak.public_pages.consent.if_yes') }}</p>
                            <ul class="mt-2 grid gap-2 leading-5 sm:grid-cols-3">
                                <li class="rounded-md bg-gray-50 px-3 py-2 dark:bg-gray-950">{{ __('suchak.public_pages.consent.point_biodata', ['suchak_name' => $suchakDisplayName]) }}</li>
                                <li class="rounded-md bg-gray-50 px-3 py-2 dark:bg-gray-950">{{ __('suchak.public_pages.consent.point_summary') }}</li>
                                <li class="rounded-md bg-gray-50 px-3 py-2 dark:bg-gray-950">{{ __('suchak.public_pages.consent.point_contact') }}</li>
                            </ul>
                            <p class="mt-2 leading-5">{{ __('suchak.public_pages.consent.privacy') }}</p>
                            <p class="mt-2 border-t border-gray-200 pt-2 text-xs leading-5 text-gray-500 dark:border-gray-700 dark:text-gray-400">{{ __('suchak.public_pages.consent.evidence') }}</p>
                        </section>

                        @if ($state === 'open')
                            <form method="POST" action="{{ route('suchak.consents.public.decision', ['token' => $token]) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                                @csrf
                                <button type="submit" name="decision" value="accepted" class="rounded-md bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">
                                    {{ __('suchak.public_pages.consent.yes') }}
                                </button>
                                <button type="submit" name="decision" value="rejected" class="rounded-md border border-red-300 bg-white px-4 py-2.5 text-sm font-semibold text-red-700 shadow-sm hover:bg-red-50 dark:border-red-800 dark:bg-gray-900 dark:text-red-200 dark:hover:bg-red-950/30">
                                    {{ __('suchak.public_pages.consent.no') }}
                                </button>
                            </form>
                        @endif
                    @endif
                </section>
            </div>
        </main>
    </body>
</html>

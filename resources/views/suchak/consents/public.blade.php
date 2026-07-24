@php
    $siteIdentityLayout = app(\App\Services\SiteIdentityService::class);
    $guestBackgroundImageUrl = $siteIdentityLayout->assetUrl('auth_background_image');
    $faviconUrl = $siteIdentityLayout->assetUrl('favicon');
    $isMr = \App\Support\LocalizedText::isMarathiLoose();
    $suchakSummary = $summary['suchak'] ?? [];
    $profileSummary = $summary['profile'] ?? [];
    $suchakDisplayName = trim((string) ($suchakSummary['name'] ?? 'सूचक'));
    $suchakBusinessName = trim((string) ($suchakSummary['business_name'] ?? ''));
    $suchakAddress = trim((string) ($suchakSummary['address'] ?? ''));
    $suchakMaskedMobile = trim((string) ($suchakSummary['masked_mobile'] ?? ''));
    $photoPath = trim((string) ($suchakSummary['photo_path'] ?? ''));
    $suchakPhotoUrl = $photoPath !== ''
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($photoPath)
        : asset('images/placeholders/default-profile.svg');
    $suchakPhotoUrl = str_starts_with($suchakPhotoUrl, 'http') ? $suchakPhotoUrl : url($suchakPhotoUrl);
    $publicUrl = route('suchak.consents.public.show', ['token' => $token]);
    $text = $isMr ? [
        'page_title' => 'संमतीपत्र',
        'intro' => 'कृपया खालील माहिती तपासा आणि तुमचा निर्णय निवडा.',
        'mobile' => 'मोबाईल',
        'profile_card' => 'स्थळाचा थोडक्यात तपशील',
        'age' => 'वय',
        'age_suffix' => 'वर्षे',
        'consent_text' => 'तुमची संमती',
        'consent_intro' => 'श्री. :suchak_name यांना तुमच्या विवाहाचे स्थळ योग्य आणि अनुरूप कुटुंबांपर्यंत पोहोचवण्यासाठी तुमच्या होकाराची गरज आहे.',
        'if_yes' => "तुम्ही 'होय' निवडल्यास:",
        'point_biodata' => ':suchak_name हे तुमचा बायोडाटा चांगल्या स्थळांच्या पालकांना सुरक्षितपणे दाखवू शकतील.',
        'point_summary' => 'योग्य स्थळांशी चर्चा करण्यासाठी ते तुमच्या स्थळाची थोडक्यात माहिती वापरू शकतील.',
        'point_contact' => 'पुढील बातचीत आणि परिचयासाठी ते तुमच्याशी किंवा तुमच्या पालकांशी संपर्क साधू शकतील.',
        'privacy' => 'तुमची खाजगी माहिती तुमच्या परवानगीशिवाय कोणालाही दिली जाणार नाही, याची पूर्ण खात्री बाळगा.',
        'evidence' => 'तुमचा निर्णय, वेळ आणि आवश्यक तांत्रिक नोंद सुरक्षित पुरावा म्हणून जतन केली जाईल.',
        'yes' => 'होय, मी संमती देतो/देते',
        'no' => 'नाही, मी संमती देत नाही',
        'invalid' => 'ही link योग्य नाही.',
        'expired' => 'ही link expired झाली आहे. कृपया Suchak कडून नवीन link मागा.',
        'accepted' => 'तुमची संमती नोंदवली आहे.',
        'rejected' => 'तुमचा नकार नोंदवला आहे.',
        'inactive' => 'ही request आता active नाही.',
        'not_available' => 'उपलब्ध नाही',
        'og_description' => 'संमतीपत्र तपासा आणि होय किंवा नाही निवडा.',
    ] : [
        'page_title' => 'Consent letter',
        'intro' => 'Please review the details below and choose your response.',
        'mobile' => 'Mobile',
        'profile_card' => 'Profile summary',
        'age' => 'Age',
        'age_suffix' => 'years',
        'consent_text' => 'Your consent',
        'consent_intro' => 'Mr./Ms. :suchak_name needs your consent to take this marriage profile to suitable families.',
        'if_yes' => "If you choose Yes:",
        'point_biodata' => ':suchak_name can safely show your biodata to parents of suitable profiles.',
        'point_summary' => 'They can use the short profile summary while discussing suitable matches.',
        'point_contact' => 'They can contact you or your parents for further conversation and introductions.',
        'privacy' => 'Your private information will not be shared with anyone without your permission.',
        'evidence' => 'Your decision, time, and required technical record will be stored as secure evidence.',
        'yes' => 'Yes, I give consent',
        'no' => 'No, I do not give consent',
        'invalid' => 'This link is invalid.',
        'expired' => 'This link has expired. Ask the Suchak for a new link.',
        'accepted' => 'Consent accepted.',
        'rejected' => 'Consent rejected.',
        'inactive' => 'This request is no longer active.',
        'not_available' => 'Not available',
        'og_description' => 'Review the profile summary and choose Yes or No.',
    ];
    $pageTitle = $text['page_title'];
    $profileNameLabel = trim((string) ($profileSummary['name_label'] ?? ($isMr ? 'उमेदवाराचे नाव' : 'Candidate name')));
    $profileName = trim((string) ($profileSummary['name'] ?? $text['not_available']));
    $profileAge = trim((string) ($profileSummary['age'] ?? $text['not_available']));
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
        <meta name="description" content="{{ $text['og_description'] }}">
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ $publicUrl }}">
        <meta property="og:site_name" content="{{ $suchakDisplayName }}">
        <meta property="og:title" content="{{ $pageTitle }}">
        <meta property="og:description" content="{{ $text['og_description'] }}">
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
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $isMr ? 'सूचक' : 'Suchak' }}</p>
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
                                        <span>{{ $text['mobile'] }}: {{ $suchakMaskedMobile }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <x-language-switcher :on-red="false" />
                    </header>

                    <div class="mt-3">
                        <h1 class="text-2xl font-bold leading-tight text-gray-950 dark:text-gray-50">{{ $text['page_title'] }}</h1>
                        <p class="mt-1 text-sm leading-5 text-gray-600 dark:text-gray-300">{{ $text['intro'] }}</p>
                    </div>

                    @if ($message)
                        <div class="mt-2 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                            {{ $message }}
                        </div>
                    @endif

                    @if ($state === 'invalid')
                        <div class="mt-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900 shadow-sm dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">{{ $text['invalid'] }}</div>
                    @elseif ($state === 'expired')
                        <div class="mt-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 shadow-sm dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">{{ $text['expired'] }}</div>
                    @elseif ($state === \App\Models\SuchakConsent::STATUS_ACCEPTED)
                        <div class="mt-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-900 shadow-sm dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">{{ $text['accepted'] }}</div>
                    @elseif ($state === \App\Models\SuchakConsent::STATUS_REJECTED)
                        <div class="mt-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-900 shadow-sm dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">{{ $text['rejected'] }}</div>
                    @elseif (in_array($state, [\App\Models\SuchakConsent::STATUS_REVOKED, \App\Models\SuchakConsent::STATUS_CANCELLED], true))
                        <div class="mt-2 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-800 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">{{ $text['inactive'] }}</div>
                    @endif

                    @if ($consent)
                        <section class="mt-3 rounded-lg border border-gray-200 bg-gray-50 p-3 shadow-sm dark:border-gray-700 dark:bg-gray-950">
                            <div class="flex items-center gap-3">
                                <img src="{{ $profilePhotoUrl }}" alt="" class="h-12 w-12 shrink-0 rounded-md border border-gray-200 object-cover shadow-sm dark:border-gray-700">
                                <div class="min-w-0 flex-1">
                                    <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $text['profile_card'] }}</h2>
                                    <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-800 dark:text-gray-100">
                                        <p class="min-w-0">
                                            <span class="font-semibold">{{ $profileNameLabel }}:</span>
                                            <span>{{ $profileName }}</span>
                                        </p>
                                        <p>
                                            <span class="font-semibold">{{ $text['age'] }}:</span>
                                            <span>{{ $profileAge }}@if ($profileAge !== $text['not_available']) {{ $text['age_suffix'] }}@endif</span>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="mt-3 rounded-lg border border-gray-200 bg-white p-3 text-sm text-gray-700 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                            <h2 class="text-base font-semibold text-gray-950 dark:text-gray-50">{{ $text['consent_text'] }}</h2>
                            <p class="mt-2 leading-5">{{ str_replace(':suchak_name', $suchakDisplayName, $text['consent_intro']) }}</p>
                            <p class="mt-2 font-semibold text-gray-950 dark:text-gray-100">{{ $text['if_yes'] }}</p>
                            <ul class="mt-2 grid gap-2 leading-5 sm:grid-cols-3">
                                <li class="rounded-md bg-gray-50 px-3 py-2 dark:bg-gray-950">{{ str_replace(':suchak_name', $suchakDisplayName, $text['point_biodata']) }}</li>
                                <li class="rounded-md bg-gray-50 px-3 py-2 dark:bg-gray-950">{{ $text['point_summary'] }}</li>
                                <li class="rounded-md bg-gray-50 px-3 py-2 dark:bg-gray-950">{{ $text['point_contact'] }}</li>
                            </ul>
                            <p class="mt-2 leading-5">{{ $text['privacy'] }}</p>
                            <p class="mt-2 border-t border-gray-200 pt-2 text-xs leading-5 text-gray-500 dark:border-gray-700 dark:text-gray-400">{{ $text['evidence'] }}</p>
                        </section>

                        @if ($state === 'open')
                            <form method="POST" action="{{ route('suchak.consents.public.decision', ['token' => $token]) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                                @csrf
                                <button type="submit" name="decision" value="accepted" class="rounded-md bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">
                                    {{ $text['yes'] }}
                                </button>
                                <button type="submit" name="decision" value="rejected" class="rounded-md border border-red-300 bg-white px-4 py-2.5 text-sm font-semibold text-red-700 shadow-sm hover:bg-red-50 dark:border-red-800 dark:bg-gray-900 dark:text-red-200 dark:hover:bg-red-950/30">
                                    {{ $text['no'] }}
                                </button>
                            </form>
                        @endif
                    @endif
                </section>
            </div>
        </main>
    </body>
</html>

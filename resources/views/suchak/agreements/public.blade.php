@php
    $siteIdentityLayout = app(\App\Services\SiteIdentityService::class);
    $guestBackgroundImageUrl = $siteIdentityLayout->assetUrl('auth_background_image');
    $faviconUrl = $siteIdentityLayout->assetUrl('favicon');

    $suchakDisplayName = trim((string) ($suchak['name'] ?? '')) ?: 'सूचक';
    $suchakOfficeName = trim((string) ($suchak['office_name'] ?? ''));
    $photoPath = trim((string) ($suchak['photo_path'] ?? ''));
    $suchakPhotoUrl = $photoPath !== ''
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($photoPath)
        : asset('images/placeholders/default-profile.svg');
    $suchakPhotoUrl = str_starts_with($suchakPhotoUrl, 'http') ? $suchakPhotoUrl : url($suchakPhotoUrl);
    $suchakPrimaryLine = $suchakOfficeName !== '' ? $suchakOfficeName : $suchakDisplayName;
    $suchakSecondaryLine = $suchakOfficeName !== '' && $suchakOfficeName !== $suchakDisplayName ? $suchakDisplayName : '';

    $currency = strtoupper((string) ($terms['currency'] ?? 'INR'));

    // Latin digits and Indian grouping both, from the one shared formatter —
    // ₹1,00,000, not ₹100,000. See App\Support\MoneyFormat.
    $money = static fn ($amount): ?string => \App\Support\MoneyFormat::amount($amount, $currency);

    $notQuoted = 'ठरलेले नाही';
    $registrationFee = $money($terms['registration_fee'] ?? null) ?? $notQuoted;
    $offlineMeetingFee = $money($terms['meeting_offline_fee'] ?? null) ?? $notQuoted;
    $onlineMeetingFee = $money($terms['meeting_online_fee'] ?? null) ?? $notQuoted;

    // The success fee is a mode first and an amount second: "as wished" and "none"
    // are real answers, and printing a rupee figure for them would invent a price.
    $successFee = match ($terms['success_fee_mode'] ?? null) {
        \App\Models\SuchakCustomerPlan::MODE_FIXED => $money($terms['success_fee_amount'] ?? null) ?? $notQuoted,
        \App\Models\SuchakCustomerPlan::MODE_AS_WISHED => 'तुमच्या इच्छेनुसार',
        \App\Models\SuchakCustomerPlan::MODE_NONE => 'नाही',
        default => $notQuoted,
    };

    $pageTitle = 'शुल्क करार';
    $ogDescription = 'शुल्क तपासा आणि स्वीकारा.';

    $feeRows = [
        ['label' => 'नोंदणी शुल्क', 'value' => $registrationFee],
        ['label' => 'प्रत्यक्ष भेटीचे शुल्क (प्रति भेट)', 'value' => $offlineMeetingFee],
        ['label' => 'ऑनलाइन भेटीचे शुल्क (प्रति भेट)', 'value' => $onlineMeetingFee],
        ['label' => 'विवाह ठरल्यानंतरचे शुल्क', 'value' => $successFee],
    ];
@endphp

<!DOCTYPE html>
<html lang="mr">
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
        <meta property="og:url" content="{{ route('suchak.agreements.public.show', ['token' => $token]) }}">
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
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">सूचक</p>
                                <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                    <p class="truncate text-base font-semibold text-gray-950 dark:text-gray-50">{{ $suchakPrimaryLine }}</p>
                                    @if ($suchakSecondaryLine !== '')
                                        <span class="text-xs text-gray-400">•</span>
                                        <p class="truncate text-sm text-gray-700 dark:text-gray-200">{{ $suchakSecondaryLine }}</p>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </header>

                    <div class="mt-3">
                        <h1 class="text-2xl font-bold leading-tight text-gray-950 dark:text-gray-50">{{ $pageTitle }}</h1>
                        <p class="mt-1 text-sm leading-5 text-gray-600 dark:text-gray-300">कृपया खालील शुल्क तपासा आणि स्वीकारा.</p>
                    </div>

                    @if ($message)
                        <div class="mt-2 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                            {{ $message }}
                        </div>
                    @endif

                    @error('accepted_by_name')
                        <div class="mt-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900 shadow-sm dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">कृपया स्वीकारणाऱ्या व्यक्तीचे नाव लिहा.</div>
                    @enderror

                    @if ($state === 'invalid')
                        <div class="mt-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900 shadow-sm dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">ही link योग्य नाही.</div>
                    @elseif ($state === 'expired')
                        <div class="mt-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 shadow-sm dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">ही link expired झाली आहे. कृपया सूचकांकडून नवीन link मागा.</div>
                    @elseif ($state === 'accepted')
                        <div class="mt-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-900 shadow-sm dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">तुमचा स्वीकार नोंदवला आहे. वरील रक्कम आता कायम झाल्या आहेत.</div>
                    @elseif ($state === 'inactive')
                        <div class="mt-2 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-800 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">हा करार आता active नाही.</div>
                    @endif

                    @if ($agreement)
                        <section class="mt-3 rounded-lg border border-gray-200 bg-gray-50 p-3 shadow-sm dark:border-gray-700 dark:bg-gray-950">
                            <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">शुल्क</h2>
                            <dl class="mt-2 grid gap-2 sm:grid-cols-2">
                                @foreach ($feeRows as $feeRow)
                                    <div class="flex items-baseline justify-between gap-3 rounded-md bg-white px-3 py-2 shadow-sm dark:bg-gray-900">
                                        <dt class="text-sm text-gray-700 dark:text-gray-200">{{ $feeRow['label'] }}</dt>
                                        <dd class="shrink-0 text-base font-bold text-gray-950 dark:text-gray-50">{{ $feeRow['value'] }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </section>

                        <section class="mt-3 rounded-lg border border-gray-200 bg-white p-3 text-sm text-gray-700 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                            <p class="font-semibold leading-5 text-gray-950 dark:text-gray-100">तुम्ही स्वीकारल्यानंतर वरील रक्कम कायम होतील. त्यानंतर सूचक त्या बदलू शकणार नाहीत.</p>
                            <p class="mt-2 border-t border-gray-200 pt-2 text-xs leading-5 text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                तुमचे नाव, स्वीकारण्याची वेळ, IP address आणि device ची तांत्रिक नोंद पुरावा म्हणून जतन केली जाईल. या पानावर OTP पडताळणी होत नाही.
                            </p>
                        </section>

                        @if ($state === 'open')
                            <form method="POST" action="{{ route('suchak.agreements.public.decision', ['token' => $token]) }}" class="mt-3 grid gap-3">
                                @csrf
                                <label class="block">
                                    <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">स्वीकारणाऱ्या व्यक्तीचे नाव</span>
                                    <input
                                        type="text"
                                        name="accepted_by_name"
                                        value="{{ old('accepted_by_name') }}"
                                        maxlength="160"
                                        required
                                        autocomplete="name"
                                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                                    >
                                </label>
                                <button type="submit" class="rounded-md bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">
                                    होय, मी ही शुल्क स्वीकारतो/स्वीकारते
                                </button>
                            </form>
                        @endif
                    @endif
                </section>
            </div>
        </main>
    </body>
</html>

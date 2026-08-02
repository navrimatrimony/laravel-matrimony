{{--
    The page where a family agrees a price. Every sentence comes from
    `suchak.public_pages.agreement.*` or from `suchak.fees.*`.

    It used to be Devanagari literals with no __() and `<html lang="mr">`, which
    stopped being merely untranslated the moment stage labels became
    locale-aware: the installment rows started printing "Once the match is
    settled" inside Marathi prose, because the labels followed the reader and
    the page did not. Fee NAMES are not restated here — they belong to
    `suchak.fees.*`, which the API's "you already accepted these terms" refusal
    reads too, so a Suchak cannot quote a row the family cannot find.
--}}
@php
    $siteIdentityLayout = app(\App\Services\SiteIdentityService::class);
    $guestBackgroundImageUrl = $siteIdentityLayout->assetUrl('auth_background_image');
    $faviconUrl = $siteIdentityLayout->assetUrl('favicon');

    $suchakDisplayName = trim((string) ($suchak['name'] ?? '')) ?: __('profile.suchak_default_name');
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

    $notQuoted = __('suchak.fees.not_quoted');
    $registrationFee = $money($terms['registration_fee'] ?? null) ?? $notQuoted;
    $offlineMeetingFee = $money($terms['meeting_offline_fee'] ?? null) ?? $notQuoted;
    $onlineMeetingFee = $money($terms['meeting_online_fee'] ?? null) ?? $notQuoted;

    // The success fee is a mode first and an amount second: "as wished" and "none"
    // are real answers, and printing a rupee figure for them would invent a price.
    $successFee = match ($terms['success_fee_mode'] ?? null) {
        \App\Models\SuchakCustomerPlan::MODE_FIXED => $money($terms['success_fee_amount'] ?? null) ?? $notQuoted,
        \App\Models\SuchakCustomerPlan::MODE_AS_WISHED => __('suchak.public_pages.agreement.success_fee_as_wished'),
        \App\Models\SuchakCustomerPlan::MODE_NONE => __('suchak.public_pages.agreement.success_fee_none'),
        default => $notQuoted,
    };

    $pageTitle = __('suchak.public_pages.agreement.title');
    $ogDescription = __('suchak.public_pages.agreement.og_description');
    $successFeeLabel = __('suchak.fees.post_marriage_fee_amount');

    // The success fee is quoted once above; these rows say WHEN each part of it
    // falls due. Every row carries its own rupee figure so nobody has to compute
    // a percentage of a number printed further up the page.
    $trancheRows = collect($terms['success_fee_tranches'] ?? [])
        ->map(static fn (array $row): array => [
            'label' => $row['label'],
            'share' => $row['share'],
            'amount' => \App\Support\MoneyFormat::amount($row['amount'] ?? null, $currency),
        ])
        ->all();

    // Fee NAMES bind to the shared `suchak.fees.*` vocabulary — the same words
    // the API's terms-change refusal reads back. Only the "charged every time"
    // qualifier is added here, and only where it is true.
    $perMeeting = static fn (string $feeKey): string => __(
        'suchak.public_pages.agreement.fee_per_meeting',
        ['fee' => __($feeKey)],
    );

    $feeRows = [
        ['label' => __('suchak.fees.price_amount'), 'value' => $registrationFee],
        ['label' => $perMeeting('suchak.fees.per_meeting_fee_amount'), 'value' => $offlineMeetingFee],
        ['label' => $perMeeting('suchak.fees.per_meeting_online_fee_amount'), 'value' => $onlineMeetingFee],
        ['label' => $successFeeLabel, 'value' => $successFee],
    ];
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
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('nav.suchak') }}</p>
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
                        <p class="mt-1 text-sm leading-5 text-gray-600 dark:text-gray-300">{{ __('suchak.public_pages.agreement.intro') }}</p>
                    </div>

                    @if ($message)
                        <div class="mt-2 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                            {{ $message }}
                        </div>
                    @endif

                    @error('accepted_by_name')
                        <div class="mt-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900 shadow-sm dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">{{ __('suchak.public_pages.agreement.name_required') }}</div>
                    @enderror

                    @if ($state === 'invalid')
                        <div class="mt-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900 shadow-sm dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">{{ __('suchak.public_pages.link_invalid') }}</div>
                    @elseif ($state === 'expired')
                        <div class="mt-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 shadow-sm dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">{{ __('suchak.public_pages.link_expired') }}</div>
                    @elseif ($state === 'accepted')
                        <div class="mt-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-900 shadow-sm dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">{{ __('suchak.public_pages.agreement.accepted') }}</div>
                    @elseif ($state === 'inactive')
                        <div class="mt-2 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-800 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">{{ __('suchak.public_pages.agreement.inactive') }}</div>
                    @endif

                    @if ($agreement)
                        <section class="mt-3 rounded-lg border border-gray-200 bg-gray-50 p-3 shadow-sm dark:border-gray-700 dark:bg-gray-950">
                            <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('suchak.public_pages.agreement.fees_heading') }}</h2>
                            <dl class="mt-2 grid gap-2 sm:grid-cols-2">
                                @foreach ($feeRows as $feeRow)
                                    <div class="flex items-baseline justify-between gap-3 rounded-md bg-white px-3 py-2 shadow-sm dark:bg-gray-900">
                                        <dt class="text-sm text-gray-700 dark:text-gray-200">{{ $feeRow['label'] }}</dt>
                                        <dd class="shrink-0 text-base font-bold text-gray-950 dark:text-gray-50">{{ $feeRow['value'] }}</dd>
                                    </div>
                                @endforeach
                            </dl>

                            @if ($trancheRows !== [])
                                <div class="mt-3 border-t border-gray-200 pt-3 dark:border-gray-700">
                                    {{-- The success fee is named once, by its shared key; this heading only says the rows below split it. --}}
                                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('suchak.public_pages.agreement.tranche_heading', ['fee' => $successFeeLabel]) }}</h3>
                                    <ul class="mt-2 grid gap-1.5">
                                        @foreach ($trancheRows as $trancheRow)
                                            <li class="flex items-baseline justify-between gap-3 rounded-md bg-white px-3 py-2 shadow-sm dark:bg-gray-900">
                                                <span class="text-sm text-gray-700 dark:text-gray-200">{{ $trancheRow['label'] }}</span>
                                                <span class="flex shrink-0 items-baseline gap-2">
                                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $trancheRow['share'] }}</span>
                                                    @if ($trancheRow['amount'] !== null)
                                                        <span class="text-base font-bold text-gray-950 dark:text-gray-50">{{ $trancheRow['amount'] }}</span>
                                                    @endif
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </section>

                        <section class="mt-3 rounded-lg border border-gray-200 bg-white p-3 text-sm text-gray-700 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                            <p class="font-semibold leading-5 text-gray-950 dark:text-gray-100">{{ __('suchak.public_pages.agreement.freeze_note') }}</p>
                            <p class="mt-2 border-t border-gray-200 pt-2 text-xs leading-5 text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                {{ __('suchak.public_pages.agreement.evidence_note') }}
                            </p>
                        </section>

                        @if ($state === 'open')
                            <form method="POST" action="{{ route('suchak.agreements.public.decision', ['token' => $token]) }}" class="mt-3 grid gap-3">
                                @csrf
                                <label class="block">
                                    <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('suchak.public_pages.agreement.accepted_by_name') }}</span>
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
                                    {{ __('suchak.public_pages.agreement.accept_button') }}
                                </button>
                            </form>
                        @endif
                    @endif
                </section>
            </div>
        </main>
    </body>
</html>

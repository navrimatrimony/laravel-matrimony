@extends('layouts.app')

@php
    $suchakText = \App\Support\Suchak\SuchakLocalizedText::class;
    $adminReviewPending = (bool) ($onboarding['admin_review_pending'] ?? false);
    $suchakCanOperate = (bool) ($canOperate ?? false);
    $statusTone = match (true) {
        in_array($suchakAccount->verification_status, [
            \App\Models\SuchakAccount::VERIFICATION_SUSPENDED,
            \App\Models\SuchakAccount::VERIFICATION_REJECTED,
            \App\Models\SuchakAccount::VERIFICATION_ARCHIVED,
        ], true) => 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100',
        $adminReviewPending => 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-100',
        $suchakAccount->verification_status === \App\Models\SuchakAccount::VERIFICATION_VERIFIED => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100',
        default => 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100',
    };
    $verificationLabel = $adminReviewPending && $suchakAccount->verification_status === \App\Models\SuchakAccount::VERIFICATION_VERIFIED
        ? __('suchak.status.work_allowed_review_pending_badge')
        : $suchakText::label($suchakAccount->verification_status);
    $accountStatusText = match (true) {
        in_array($suchakAccount->verification_status, [
            \App\Models\SuchakAccount::VERIFICATION_SUSPENDED,
            \App\Models\SuchakAccount::VERIFICATION_REJECTED,
            \App\Models\SuchakAccount::VERIFICATION_ARCHIVED,
        ], true) => 'Suchak work is paused until admin reactivates this account.',
        $suchakCanOperate && $suchakAccount->verification_status === \App\Models\SuchakAccount::VERIFICATION_PENDING => 'Work enabled. Admin review is still pending.',
        $adminReviewPending => 'Work unlocked. Admin/KYC review is still pending.',
        $suchakAccount->isVerified() => 'Approved. You can start Suchak work.',
        default => 'Pending. Admin approval is required before customer entry.',
    };
    $fieldLabels = collect($allowedSuggestionFields)
        ->mapWithKeys(fn (string $field) => [$field => ucwords(str_replace('_', ' ', $field))])
        ->all();
    $consentTypeLabels = collect($consentTypeOptions)
        ->mapWithKeys(fn (string $type) => [$type => ucwords(str_replace('_', ' ', $type))])
        ->all();
    $consentChannelLabels = collect($consentChannelOptions)
        ->mapWithKeys(fn (string $channel) => [$channel => ucwords(str_replace('_', ' ', $channel))])
        ->all();
    $consentChannelLabels[\App\Models\SuchakConsent::METHOD_SUCHAK_RELAYED_LINK] = 'Send consent link from my WhatsApp';
    $consentChannelLabels[\App\Models\SuchakConsent::METHOD_OFFLINE_SIGNED_PROOF] = 'Upload signed proof';
    $consentChannelLabels[\App\Models\SuchakConsent::METHOD_PLATFORM_ASSISTED_LINK] = 'Platform-assisted consent';
    $consentMethodLabel = static fn (?string $method) => $consentChannelLabels[$method ?: ''] ?? ($method ? ucwords(str_replace('_', ' ', $method)) : 'Consent');
    $maskConsentMobile = static function (?string $mobile): string {
        $digits = preg_replace('/\D/', '', (string) $mobile) ?: '';
        if (strlen($digits) < 4) {
            return 'Not available';
        }

        return substr($digits, 0, 2).str_repeat('x', max(2, strlen($digits) - 4)).substr($digits, -2);
    };
    $consentRelationOptions = [
        'candidate_self' => 'Candidate self',
        'father' => 'Father',
        'mother' => 'Mother',
        'brother' => 'Brother',
        'sister' => 'Sister',
        'guardian' => 'Guardian',
        'other_family' => 'Other family',
    ];
    $noteTypeLabels = collect($noteTypeOptions)
        ->mapWithKeys(fn (string $type) => [$type => ucwords(str_replace('_', ' ', $type))])
        ->all();
    $ledgerTypeLabels = collect($ledgerTypeOptions)
        ->mapWithKeys(fn (string $type) => [$type => ucwords(str_replace('_', ' ', $type))])
        ->all();
    $ledgerStatusLabels = collect($ledgerStatusOptions)
        ->mapWithKeys(fn (string $status) => [$status => ucwords(str_replace('_', ' ', $status))])
        ->all();
    $sourceOwnerLabels = collect($sourceOwnerOptions)
        ->filter(fn (string $owner) => $owner === \App\Models\SuchakPaymentContext::SOURCE_SUCHAK)
        ->mapWithKeys(fn (string $owner) => [$owner => ucwords(str_replace('_', ' ', $owner))])
        ->all();
    $paymentCollectorLabels = collect($paymentCollectorOptions)
        ->filter(fn (string $collector) => $collector === \App\Models\SuchakPaymentContext::COLLECTOR_SUCHAK)
        ->mapWithKeys(fn (string $collector) => [$collector => ucwords(str_replace('_', ' ', $collector))])
        ->all();
    $formatAnalyticsMoney = fn ($amount, string $currency = 'INR') => $currency.' '.number_format((float) ($amount ?? 0), 2);
    $dashboardSectionKeys = ['profile', 'work', 'profiles', 'requests', 'money', 'sharing', 'records'];
    $dashboardHasBusinessFilters = $businessRecordFilters['business_q'] !== ''
        || $businessRecordFilters['note_type'] !== null
        || $businessRecordFilters['ledger_status'] !== null;
    $requestedDashboardTab = (string) request('dashboard_tab', '');
    $activeDashboardTab = in_array($requestedDashboardTab, $dashboardSectionKeys, true)
        ? $requestedDashboardTab
        : ($dashboardHasBusinessFilters ? 'profiles' : 'work');
    $selectedRepresentationId = (int) request('manage_representation', 0);
    $selectedRepresentationCard = $selectedRepresentationId > 0
        ? $representationCards->first(fn (array $card): bool => (int) $card['representation']->id === $selectedRepresentationId)
        : null;
    $showOnboardingPanel = $activeDashboardTab === 'profile';
    $showDashboardSummary = $activeDashboardTab === 'work';
    $onboardingSteps = collect($onboarding['steps'] ?? []);
    $kycRows = collect($onboarding['document_rows'] ?? []);
    $identityKycRow = $kycRows->firstWhere('type', \App\Models\SuchakVerificationRecord::TYPE_IDENTITY);
    $officeKycRow = $kycRows->firstWhere('type', \App\Models\SuchakVerificationRecord::TYPE_OFFICE);
    $businessKycRow = $kycRows->firstWhere('type', \App\Models\SuchakVerificationRecord::TYPE_BUSINESS);
    $isOrganizationSuchak = $suchakAccount->business_type === \App\Models\SuchakAccount::BUSINESS_TYPE_ORGANIZATION;
    $kycBadgeTone = static function (?array $row): string {
        return match ($row['status'] ?? null) {
            \App\Models\SuchakVerificationRecord::STATUS_APPROVED => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100',
            \App\Models\SuchakVerificationRecord::STATUS_REJECTED => 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100',
            default => 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-100',
        };
    };
    $currentOnboardingKey = (string) ($onboarding['current_step_key'] ?? 'documents');
    $currentOnboardingStep = $onboarding['current_step'] ?? $onboardingSteps->first();
    $cardPhotoUrl = $suchakAccount->profile_photo_path
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($suchakAccount->profile_photo_path)
        : null;
    $cardName = $suchakAccount->suchak_name_mr ?: $suchakAccount->suchak_name;
    $cardOffice = $suchakAccount->office_name_mr ?: ($suchakAccount->office_name ?: $suchakText::label($suchakAccount->business_type));
    $cardAddress = $suchakAccount->address_line_mr ?: $suchakAccount->address_line;
    $cardShareText = trim($cardName."\n".$cardOffice."\nWhatsApp: ".($suchakAccount->whatsapp_number ?: $suchakAccount->mobile_number)."\n".($cardAddress ?: '')."\n\nलग्न जुळवण्यासाठी विश्वासार्ह सूचक सेवा. अधिक माहितीसाठी संपर्क करा.");
    $cardWhatsappUrl = 'https://wa.me/?text='.rawurlencode($cardShareText);
    $profileRequestReplyTemplates = [
        'मी हे स्थळ संबंधित कुटुंबाला दाखवतो. उत्तर आले की तुम्हाला कळवतो.',
        'या स्थळाबद्दल अधिक माहिती देण्यासाठी कृपया तुमचा संपर्क क्रमांक आणि सोयीची वेळ पाठवा.',
        'हे स्थळ सध्या चर्चेत आहे. पुढील माहिती मिळताच तुम्हाला कळवतो.',
    ];
    $profileRequestName = static fn ($profile, int $fallbackId): string => trim((string) ($profile?->full_name ?? '')) !== ''
        ? trim((string) $profile->full_name)
        : 'Profile #'.$fallbackId;
    $profileRequestAge = static function ($profile): string {
        $dateOfBirth = $profile?->date_of_birth ?? null;
        if ($dateOfBirth === null || $dateOfBirth === '') {
            return 'Age not available';
        }

        try {
            $age = \Illuminate\Support\Carbon::parse($dateOfBirth)->age;
        } catch (\Throwable) {
            return 'Age not available';
        }

        return $age >= 18 && $age <= 100 ? $age.' years' : 'Age not available';
    };
    $profileRequestPhoto = static fn ($profile): string => $profile?->profile_photo_url ?: asset('images/placeholders/default-profile.svg');
    $profileRequestUrl = static fn ($profile): ?string => $profile ? route('matrimony.profile.show', $profile->id) : null;
@endphp

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8">
    @if (session('qr_url_path'))
        <section class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900 shadow-sm dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="font-semibold">Secure PDF/QR generated</p>
                    <p class="mt-1 break-all font-mono text-xs">{{ url(session('qr_url_path')) }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ url(session('qr_url_path')) }}" target="_blank" rel="noopener" class="inline-flex justify-center rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                        Open QR preview
                    </a>
                    @if (session('export_id'))
                        <a href="{{ route('suchak.exports.download', session('export_id')) }}" class="inline-flex justify-center rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">
                            Download PDF
                        </a>
                        <form method="POST" action="{{ route('suchak.exports.mark-shared', session('export_id')) }}">
                            @csrf
                            <button type="submit" class="inline-flex justify-center rounded-md border border-emerald-700 px-4 py-2 text-sm font-semibold text-emerald-900 hover:bg-emerald-100 dark:border-emerald-300 dark:text-emerald-100 dark:hover:bg-emerald-900">
                                Mark shared
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </section>
    @endif

    @if (session('status') && ! session('success') && ! session('info') && ! session('error'))
        <section class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 shadow-sm dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
            {{ session('status') }}
        </section>
    @endif

    @if (session('suchak_consent_forward_message'))
        <section class="mb-6 rounded-lg border border-emerald-200 bg-white p-4 text-sm shadow-sm dark:border-emerald-900 dark:bg-gray-800">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-sm font-semibold text-emerald-900 dark:text-emerald-100">Consent message ready</p>
                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">Send this message from Suchak WhatsApp to the customer/family.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if (session('suchak_consent_whatsapp_url'))
                        <a href="{{ session('suchak_consent_whatsapp_url') }}" target="_blank" rel="noopener" class="inline-flex w-fit rounded-md bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700">
                            Send on WhatsApp
                        </a>
                    @endif
                    @if (session('suchak_consent_url'))
                        <a href="{{ session('suchak_consent_url') }}" target="_blank" rel="noopener" class="inline-flex w-fit rounded-md border border-emerald-300 px-3 py-2 text-xs font-semibold text-emerald-900 hover:bg-emerald-50 dark:border-emerald-800 dark:text-emerald-100 dark:hover:bg-emerald-950/40">
                            Open consent page
                        </a>
                    @endif
                </div>
            </div>
            <textarea readonly rows="4" class="mt-3 w-full rounded-md border-gray-300 bg-gray-50 text-xs shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">{{ session('suchak_consent_forward_message') }}</textarea>
            <button type="button" onclick="navigator.clipboard?.writeText(this.previousElementSibling.value)" class="mt-2 rounded-md bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700">
                Copy message
            </button>
        </section>
    @endif

    @if ($showOnboardingPanel)
    <section class="mb-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-wide text-red-700 dark:text-red-300">Suchak onboarding</p>
                <h1 class="mt-2 text-2xl font-bold text-gray-950 dark:text-gray-100">{{ $currentOnboardingStep['label'] ?? 'Suchak work' }}</h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $currentOnboardingStep['body'] ?? 'Use dashboard tools to continue Suchak work.' }}</p>
            </div>
            <a href="{{ route('suchak.register.status') }}" class="inline-flex w-fit rounded-md border border-red-200 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 dark:border-red-900 dark:text-red-200 dark:hover:bg-red-950/40">
                View full status
            </a>
        </div>

        @include('suchak.partials.onboarding-tracker', [
            'steps' => $onboardingSteps,
            'currentStepKey' => $currentOnboardingKey,
        ])

        <div class="mt-7 space-y-3">
            @foreach ($onboardingSteps as $step)
                <article class="rounded-md border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-bold text-gray-950 dark:text-gray-100">{{ $step['label'] }}</h3>
                            <p class="mt-1 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $step['body'] }}</p>
                        </div>
                        <span class="shrink-0 rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-gray-600 shadow-sm dark:bg-gray-950 dark:text-gray-300">
                            {{ __('suchak.status.step_states.'.$step['state']) }}
                        </span>
                    </div>
                </article>
            @endforeach
        </div>
    </section>
    @endif

    @if ($showDashboardSummary)
    <div class="mb-6 flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
        <div>
            <p class="text-sm font-semibold text-gray-500 dark:text-gray-400">{{ $suchakAccount->office_name ?: $suchakAccount->suchak_name }}</p>
            <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">Suchak Dashboard</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-300">
                Manage represented profiles, source biodata, masked discovery, collaborations, and governed profile update suggestions.
            </p>
        </div>
        <div class="flex flex-col gap-2 md:items-end">
            <div class="rounded-md border px-4 py-3 text-sm font-semibold {{ $statusTone }}">
                Verification: {{ $verificationLabel }}
                <span class="ml-2 font-normal">Public: {{ $suchakText::label($suchakAccount->public_status) }}</span>
            </div>
        </div>
    </div>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Represented</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['representations_total'] }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Active consent</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['representations_active'] }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Source links</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['source_links'] }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Pending collaborations</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['pending_collaborations'] }}</div>
        </div>
    </div>
    @endif

    <div class="space-y-6">

    <section class="{{ $activeDashboardTab !== 'profile' ? 'hidden ' : '' }}rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-wide text-red-700 dark:text-red-300">Profile setup</p>
                <h2 class="mt-1 text-xl font-bold text-gray-950 dark:text-gray-100">Complete your Suchak profile first</h2>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-300">
                    Add your photo, visiting card or office proof, and organization document when applicable. You can start customer entry while admin verification continues.
                </p>
            </div>
            <a href="{{ route('suchak.manual-profiles.create') }}" class="inline-flex w-fit items-center justify-center rounded-md bg-red-600 px-4 py-2 text-sm font-bold text-white shadow-sm hover:bg-red-700">
                Add customer entry
            </a>
        </div>

        <div class="mt-6 grid gap-5 lg:grid-cols-[1fr_1.15fr]">
            <article class="rounded-lg border border-gray-200 bg-gray-50 p-5 dark:border-gray-700 dark:bg-gray-900">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                    <div class="flex h-24 w-24 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-white text-2xl font-bold text-gray-500 ring-1 ring-gray-200 dark:bg-gray-950 dark:text-gray-300 dark:ring-gray-700">
                        @if ($cardPhotoUrl)
                            <img src="{{ $cardPhotoUrl }}" alt="{{ $cardName }}" class="h-full w-full object-cover">
                        @else
                            {{ mb_substr($cardName, 0, 1) }}
                        @endif
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Suchak share card</p>
                        <h3 class="mt-1 text-lg font-bold text-gray-950 dark:text-gray-100">{{ $cardName }}</h3>
                        <p class="mt-1 text-sm font-semibold text-gray-700 dark:text-gray-200">{{ $cardOffice }}</p>
                        <dl class="mt-4 grid gap-2 text-sm text-gray-600 dark:text-gray-300">
                            <div><dt class="font-semibold text-gray-950 dark:text-gray-100">WhatsApp</dt><dd>{{ $suchakAccount->whatsapp_number ?: $suchakAccount->mobile_number }}</dd></div>
                            @if ($cardAddress)
                                <div><dt class="font-semibold text-gray-950 dark:text-gray-100">Address</dt><dd>{{ $cardAddress }}</dd></div>
                            @endif
                        </dl>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <a href="{{ $cardWhatsappUrl }}" target="_blank" rel="noopener" class="inline-flex rounded-md border border-emerald-300 px-4 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-50 dark:border-emerald-800 dark:text-emerald-100 dark:hover:bg-emerald-950/40">
                                Share on WhatsApp
                            </a>
                        </div>
                    </div>
                </div>
                <div class="mt-5 rounded-md border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-950">
                    <p class="block text-sm font-semibold text-gray-800 dark:text-gray-200">Your photo</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Use the same crop and review flow as profile photos. The photo appears on your Suchak card only after review.</p>
                    <a href="{{ route('suchak.register.photo') }}" class="mt-3 inline-flex rounded-md bg-gray-950 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-950 dark:hover:bg-white">
                        Open photo uploader
                    </a>
                </div>
            </article>

            <div class="grid gap-4">
                @foreach ([
                    ['row' => $identityKycRow, 'type' => \App\Models\SuchakVerificationRecord::TYPE_IDENTITY, 'title' => 'Identity proof', 'body' => 'Upload Aadhaar, passport, or other readable identity proof for KYC.'],
                    ['row' => $officeKycRow, 'type' => \App\Models\SuchakVerificationRecord::TYPE_OFFICE, 'title' => 'Visiting card / office proof', 'body' => 'Upload your visiting card, office proof, or bureau letterhead.'],
                    ['row' => $businessKycRow, 'type' => \App\Models\SuchakVerificationRecord::TYPE_BUSINESS, 'title' => 'Organization logo / document', 'body' => 'For organization Suchak, upload logo image or organization document for admin verification.', 'visible' => $isOrganizationSuchak],
                ] as $setupUpload)
                    @php
                        $setupVisible = $setupUpload['visible'] ?? true;
                        $setupRow = $setupUpload['row'] ?? null;
                    @endphp
                    @continue(! $setupVisible)
                    <article class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h3 class="text-sm font-bold text-gray-950 dark:text-gray-100">{{ $setupUpload['title'] }}</h3>
                                <p class="mt-1 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $setupUpload['body'] }}</p>
                            </div>
                            <span class="inline-flex w-fit shrink-0 rounded-full border px-2.5 py-1 text-xs font-semibold {{ $kycBadgeTone($setupRow) }}">
                                {{ $setupRow['status_label'] ?? __('suchak.labels.common.pending') }}
                            </span>
                        </div>
                        <form method="POST" action="{{ route('suchak.register.documents.store') }}" enctype="multipart/form-data" class="mt-4 grid gap-3 sm:grid-cols-[1fr_auto] sm:items-center">
                            @csrf
                            <input type="hidden" name="verification_type" value="{{ $setupUpload['type'] }}">
                            <input name="document" type="file" required accept=".pdf,.jpg,.jpeg,.png" class="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-gray-700 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:file:bg-gray-800 dark:file:text-gray-100">
                            <button type="submit" class="rounded-md bg-gray-950 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-950 dark:hover:bg-white">
                                {{ ($setupRow['uploaded'] ?? false) ? 'Replace' : 'Upload' }}
                            </button>
                        </form>
                    </article>
                @endforeach

                <article class="rounded-lg border border-indigo-200 bg-indigo-50 p-4 dark:border-indigo-900 dark:bg-indigo-950/30">
                    <h3 class="text-sm font-bold text-indigo-950 dark:text-indigo-100">Start customer work</h3>
                    <p class="mt-1 text-sm leading-6 text-indigo-900/80 dark:text-indigo-100/80">Add a customer through biodata intake or the manual form. Both routes keep Suchak source tracking separate from your own account.</p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <a href="{{ route('suchak.intakes.create') }}" class="inline-flex rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Upload / paste biodata</a>
                        <a href="{{ route('suchak.manual-profiles.create') }}" class="inline-flex rounded-md border border-indigo-300 bg-white px-4 py-2 text-sm font-semibold text-indigo-800 hover:bg-indigo-100 dark:border-indigo-700 dark:bg-indigo-950 dark:text-indigo-100 dark:hover:bg-indigo-900">Manual profile form</a>
                    </div>
                </article>
            </div>
        </div>
    </section>

    <section id="white-label-sharing-kit" class="{{ $activeDashboardTab !== 'sharing' ? 'hidden ' : '' }}rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">White-label Sharing Kit</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Share assets with masked candidate details, QR verification, and powered-by footer.</p>
            </div>
            <span class="inline-flex w-fit rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold uppercase text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-100">
                {{ count($sharingKit['assets']) }} generated
            </span>
        </div>

        @unless ($sharingKit['is_publicly_routable'])
            <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                Public profile assets need verified Suchak status with public listing active.
            </div>
        @endunless

        <div class="mt-5 grid gap-4 lg:grid-cols-2">
            @forelse ($sharingKit['assets'] as $asset)
                <article class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">{{ $asset['label'] }}</p>
                            <h3 class="mt-1 text-base font-semibold text-gray-900 dark:text-gray-100">{{ $asset['title'] }}</h3>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $asset['source_type'] }} #{{ $asset['source_id'] }}</p>
                        </div>
                        @if (! empty($asset['qr_data_uri']))
                            <img src="{{ $asset['qr_data_uri'] }}" alt="{{ $asset['label'] }} QR" class="h-28 w-28 rounded-md border border-gray-200 bg-white p-2 dark:border-gray-700">
                        @endif
                    </div>
                    <dl class="mt-4 grid gap-2 text-sm text-gray-700 dark:text-gray-300 sm:grid-cols-2">
                        @foreach ($asset['lines'] as $line)
                            <div class="rounded-md bg-white px-3 py-2 dark:bg-gray-800">{{ $line }}</div>
                        @endforeach
                    </dl>
                    <label class="mt-4 block text-xs font-semibold uppercase text-gray-500 dark:text-gray-400" for="share_text_{{ $asset['type'] }}_{{ $asset['source_id'] }}">Share copy</label>
                    <textarea id="share_text_{{ $asset['type'] }}_{{ $asset['source_id'] }}" rows="5" readonly class="mt-1 w-full rounded-md border-gray-300 bg-white text-xs text-gray-700 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200">{{ $asset['share_text'] }}</textarea>
                    <div class="mt-3 flex flex-col gap-2 text-xs text-gray-500 dark:text-gray-400 sm:flex-row sm:items-center sm:justify-between">
                        <span>{{ $asset['powered_by_footer'] }}</span>
                        <a href="{{ $asset['qr_url'] }}" target="_blank" rel="noopener" class="font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-300 dark:hover:text-indigo-200">Open linked record</a>
                    </div>
                </article>
            @empty
                <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-6 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                    No white-label assets are available yet. Publish the Suchak profile and issue a receipt to generate share-kit assets.
                </div>
            @endforelse
        </div>
    </section>

    <section id="income-analytics" class="{{ $activeDashboardTab !== 'money' ? 'hidden ' : '' }}rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Income Dashboard</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Suchak-scoped value summary from persisted payment, ledger, payout, reward, package, and source records.</p>
            </div>
            <span class="inline-flex w-fit rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold uppercase text-gray-600 dark:bg-gray-900 dark:text-gray-300">
                Persisted records only
            </span>
        </div>

        <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Expected income</div>
                <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $formatAnalyticsMoney($incomeAnalytics['customer_ledger']['expected_income_amount'], $incomeAnalytics['currency']) }}</div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Suchak customer ledger and direct requests.</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Received income</div>
                <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $formatAnalyticsMoney($incomeAnalytics['customer_ledger']['received_income_amount'], $incomeAnalytics['currency']) }}</div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Direct Suchak customer payments only.</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Pending / overdue</div>
                <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $formatAnalyticsMoney($incomeAnalytics['customer_ledger']['pending_amount'], $incomeAnalytics['currency']) }}</div>
                <p class="mt-1 text-xs text-red-700 dark:text-red-300">Overdue {{ $formatAnalyticsMoney($incomeAnalytics['customer_ledger']['overdue_amount'], $incomeAnalytics['currency']) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Net benefit</div>
                <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $formatAnalyticsMoney($incomeAnalytics['net_benefit_amount'], $incomeAnalytics['currency']) }}</div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Customer net + payout due + credits - plan cost.</p>
            </div>
        </div>

        <div class="mt-5 grid gap-4 lg:grid-cols-3">
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Platform revenue</h3>
                <dl class="mt-3 space-y-2 text-sm text-gray-600 dark:text-gray-300">
                    <div class="flex justify-between gap-3">
                        <dt>Plan payments received</dt>
                        <dd class="font-semibold text-gray-900 dark:text-gray-100">{{ $formatAnalyticsMoney($incomeAnalytics['platform_revenue']['plan_payment_received_amount'], $incomeAnalytics['currency']) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt>Plan cost</dt>
                        <dd class="font-semibold text-gray-900 dark:text-gray-100">{{ $formatAnalyticsMoney($incomeAnalytics['plan_cost_amount'], $incomeAnalytics['currency']) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt>Pending plan payments</dt>
                        <dd class="font-semibold text-gray-900 dark:text-gray-100">{{ $formatAnalyticsMoney($incomeAnalytics['platform_revenue']['plan_payment_pending_amount'], $incomeAnalytics['currency']) }}</dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Payout liability</h3>
                <dl class="mt-3 space-y-2 text-sm text-gray-600 dark:text-gray-300">
                    <div class="flex justify-between gap-3">
                        <dt>Platform payout due</dt>
                        <dd class="font-semibold text-gray-900 dark:text-gray-100">{{ $formatAnalyticsMoney($incomeAnalytics['payout_liability']['due_amount'], $incomeAnalytics['currency']) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt>On hold</dt>
                        <dd class="font-semibold text-gray-900 dark:text-gray-100">{{ $formatAnalyticsMoney($incomeAnalytics['payout_liability']['held_amount'], $incomeAnalytics['currency']) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt>Paid</dt>
                        <dd class="font-semibold text-gray-900 dark:text-gray-100">{{ $formatAnalyticsMoney($incomeAnalytics['payout_liability']['paid_amount'], $incomeAnalytics['currency']) }}</dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Referral rewards</h3>
                <dl class="mt-3 space-y-2 text-sm text-gray-600 dark:text-gray-300">
                    <div class="flex justify-between gap-3">
                        <dt>Cash rewards</dt>
                        <dd class="font-semibold text-gray-900 dark:text-gray-100">{{ $formatAnalyticsMoney($incomeAnalytics['referral_rewards']['cash_amount'], $incomeAnalytics['currency']) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt>Credits</dt>
                        <dd class="font-semibold text-gray-900 dark:text-gray-100">{{ $formatAnalyticsMoney($incomeAnalytics['referral_rewards']['credit_value'], $incomeAnalytics['currency']) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt>Admin actions</dt>
                        <dd class="font-semibold text-gray-900 dark:text-gray-100">{{ number_format($incomeAnalytics['referral_rewards']['admin_action_count']) }}</dd>
                    </div>
                </dl>
            </div>
        </div>

        <div class="mt-5 grid gap-4 lg:grid-cols-2">
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Package performance</h3>
                <div class="mt-3 space-y-3">
                    @forelse ($incomeAnalytics['package_performance'] as $packageMetric)
                        <div class="rounded-md bg-white px-3 py-2 text-sm dark:bg-gray-800">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="font-semibold text-gray-900 dark:text-gray-100">{{ $packageMetric['package_name'] }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ ucwords(str_replace('_', ' ', $packageMetric['package_status'])) }} · {{ $formatAnalyticsMoney($packageMetric['price_amount'], $incomeAnalytics['currency']) }}</p>
                                </div>
                                <div class="text-right text-xs text-gray-600 dark:text-gray-300">
                                    <div>{{ number_format($packageMetric['request_count']) }} requests</div>
                                    <div>{{ number_format($packageMetric['payment_count']) }} payments</div>
                                </div>
                            </div>
                            <div class="mt-2 grid gap-2 text-xs text-gray-600 dark:text-gray-300 sm:grid-cols-3">
                                <div>Requested {{ $formatAnalyticsMoney($packageMetric['requested_amount'], $incomeAnalytics['currency']) }}</div>
                                <div>Received {{ $formatAnalyticsMoney($packageMetric['received_amount'], $incomeAnalytics['currency']) }}</div>
                                <div>Balance {{ $formatAnalyticsMoney($packageMetric['balance_amount'], $incomeAnalytics['currency']) }}</div>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-600 dark:text-gray-300">No service packages have persisted payment analytics yet.</p>
                    @endforelse
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Source performance</h3>
                <div class="mt-3 space-y-3">
                    @forelse ($incomeAnalytics['source_performance'] as $sourceMetric)
                        <div class="rounded-md bg-white px-3 py-2 text-sm dark:bg-gray-800">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="font-semibold text-gray-900 dark:text-gray-100">{{ ucwords(str_replace('_', ' ', $sourceMetric['source_type'])) }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Owner: {{ ucwords(str_replace('_', ' ', $sourceMetric['source_owner'])) }}</p>
                                </div>
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600 dark:bg-gray-900 dark:text-gray-300">
                                    {{ number_format($sourceMetric['customer_count']) }} customers
                                </span>
                            </div>
                            <div class="mt-2 grid gap-2 text-xs text-gray-600 dark:text-gray-300 sm:grid-cols-2">
                                <div>Requested {{ $formatAnalyticsMoney($sourceMetric['requested_amount'], $incomeAnalytics['currency']) }}</div>
                                <div>Received {{ $formatAnalyticsMoney($sourceMetric['received_amount'], $incomeAnalytics['currency']) }}</div>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-600 dark:text-gray-300">No customer source records are available yet.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </section>

    <section id="daily-opportunities" class="{{ $activeDashboardTab !== 'work' ? 'hidden ' : '' }}rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Daily Opportunities</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Deterministic worklist for follow-ups, consent, PDFs, SLA risk, payments, and collaboration.</p>
            </div>
            <span class="inline-flex w-fit rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold uppercase text-gray-600 dark:bg-gray-900 dark:text-gray-300">
                {{ $dailyOpportunities->count() }} open
            </span>
        </div>

        <div class="mt-5 space-y-3">
            @forelse ($dailyOpportunities as $opportunity)
                <article class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                        <div class="min-w-0 space-y-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-gray-700 shadow-sm dark:bg-gray-800 dark:text-gray-200">
                                    {{ $opportunity['label'] }}
                                </span>
                                @if ($opportunity['due_at'])
                                    <span class="text-xs font-semibold text-red-700 dark:text-red-300">
                                        Due {{ $opportunity['due_at']->format('Y-m-d H:i') }}
                                    </span>
                                @endif
                            </div>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $opportunity['reason'] }}</p>
                            @if ($opportunity['candidate_reference'])
                                <p class="text-sm text-gray-600 dark:text-gray-300">Candidate: {{ $opportunity['candidate_reference'] }}</p>
                            @endif
                        </div>
                        <a href="{{ $opportunity['action_url'] }}" class="inline-flex shrink-0 justify-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                            {{ $opportunity['action_label'] }}
                        </a>
                    </div>
                </article>
            @empty
                <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-4 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                    No deterministic daily opportunities are due right now.
                </div>
            @endforelse
        </div>
    </section>

    <section id="workflow-reminders" class="{{ $activeDashboardTab !== 'work' ? 'hidden ' : '' }}rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Workflow Reminders</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Follow-up, payment, consent, and meeting reminder copies with immutable workflow timeline.</p>
            </div>
            <span class="inline-flex w-fit rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold uppercase text-amber-800 dark:bg-amber-950/50 dark:text-amber-100">
                Provider pending
            </span>
        </div>

        <div class="mt-5 grid gap-4 lg:grid-cols-2">
            <div>
                <h3 class="text-sm font-semibold uppercase text-gray-500 dark:text-gray-400">Recent reminders</h3>
                <div class="mt-3 space-y-3">
                    @forelse ($workflowReminders as $reminder)
                        <article class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-gray-700 shadow-sm dark:bg-gray-800 dark:text-gray-200">
                                    {{ ucwords(str_replace('_', ' ', $reminder->reminder_type)) }}
                                </span>
                                <span class="text-xs font-semibold text-amber-700 dark:text-amber-300">
                                    {{ str_replace('_', ' ', $reminder->provider_status) }}
                                </span>
                            </div>
                            <p class="mt-2 text-sm text-gray-900 dark:text-gray-100">{{ $reminder->message_copy }}</p>
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                Due {{ $reminder->due_at->format('Y-m-d H:i') }} · {{ $reminder->source_type }} #{{ $reminder->source_id }}
                            </p>
                        </article>
                    @empty
                        <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-4 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                            No workflow reminders have been generated yet.
                        </div>
                    @endforelse
                </div>
            </div>

            <div>
                <h3 class="text-sm font-semibold uppercase text-gray-500 dark:text-gray-400">Timeline</h3>
                <div class="mt-3 space-y-3">
                    @forelse ($workflowTimeline as $event)
                        <article class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $suchakText::column($event, 'event_title') }}</p>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $suchakText::column($event, 'event_summary') }}</p>
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                {{ $event->occurred_at->format('Y-m-d H:i') }} · {{ $event->event_type }}
                            </p>
                        </article>
                    @empty
                        <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-4 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                            No workflow timeline events are recorded yet.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="mt-5">
            <h3 class="text-sm font-semibold uppercase text-gray-500 dark:text-gray-400">WhatsApp copy templates</h3>
            <div class="mt-3 grid gap-3 md:grid-cols-2">
                @foreach ($workflowTemplates as $templateKey => $template)
                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $template['label'] }}</span>
                            <span class="rounded-full bg-white px-2 py-0.5 text-xs font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ $template['provider_status'] }}</span>
                        </div>
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $template['body'] }}</p>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ $templateKey }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="{{ $activeDashboardTab !== 'work' ? 'hidden ' : '' }}rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Suchak Quick Links</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">नवीन Suchak साठी सर्व मुख्य कामांचे सोपे links.</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('suchak.home') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Suchak Centre</a>
                <a href="{{ route('suchak.intakes.create') }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Upload / paste biodata</a>
                <a href="{{ route('suchak.manual-profiles.create') }}" class="rounded-md border border-indigo-300 px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 dark:border-indigo-700 dark:text-indigo-200 dark:hover:bg-indigo-950/40">Manual profile form</a>
                <a href="{{ route('suchak.search.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Find Matches</a>
                <a href="{{ route('suchak.dashboard', ['dashboard_tab' => 'profiles']) }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Generate PDF/QR</a>
                <a href="{{ route('suchak.dashboard', ['dashboard_tab' => 'profiles']) }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Suggest profile update</a>
            </div>
        </div>

        <div class="mt-5 grid gap-3 md:grid-cols-5">
            <div class="rounded-md bg-gray-50 p-4 text-sm dark:bg-gray-900">
                <p class="font-semibold text-gray-900 dark:text-gray-100">1. Account status</p>
                <p class="mt-2 text-gray-600 dark:text-gray-300">
                    {{ $accountStatusText }}
                </p>
            </div>
            <a href="{{ route('suchak.intakes.create') }}" class="rounded-md bg-gray-50 p-4 text-sm hover:bg-gray-100 dark:bg-gray-900 dark:hover:bg-gray-950">
                <p class="font-semibold text-gray-900 dark:text-gray-100">2. Upload / paste</p>
                <p class="mt-2 text-gray-600 dark:text-gray-300">Create intake source: customer चा biodata paste किंवा upload करा.</p>
            </a>
            <a href="{{ route('suchak.manual-profiles.create') }}" class="rounded-md bg-gray-50 p-4 text-sm hover:bg-gray-100 dark:bg-gray-900 dark:hover:bg-gray-950">
                <p class="font-semibold text-gray-900 dark:text-gray-100">3. Manual form</p>
                <p class="mt-2 text-gray-600 dark:text-gray-300">Existing centralized profile form वापरून biodata भरा.</p>
            </a>
            <a href="{{ route('suchak.search.index') }}" class="rounded-md bg-gray-50 p-4 text-sm hover:bg-gray-100 dark:bg-gray-900 dark:hover:bg-gray-950">
                <p class="font-semibold text-gray-900 dark:text-gray-100">4. Find Matches</p>
                <p class="mt-2 text-gray-600 dark:text-gray-300">Masked profiles शोधा, contact leak नाही.</p>
            </a>
            <a href="{{ route('suchak.home') }}" class="rounded-md bg-gray-50 p-4 text-sm hover:bg-gray-100 dark:bg-gray-900 dark:hover:bg-gray-950">
                <p class="font-semibold text-gray-900 dark:text-gray-100">5. Help links</p>
                <p class="mt-2 text-gray-600 dark:text-gray-300">Registration and admin approval links.</p>
            </a>
        </div>
    </section>

    <div class="space-y-6">
            @if ($activeDashboardTab === 'profiles')
            @if (! $selectedRepresentationCard)
                @include('suchak.partials.customer-list-table', ['activeDashboardTab' => $activeDashboardTab])
            @endif

            @if ($selectedRepresentationCard)
            <section id="customer-management" class="rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-700">
                    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Customer details</h2>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Consent, CRM notes, ledger, and exports for the selected represented profile.</p>
                        </div>
                        <a href="{{ route('suchak.dashboard', ['dashboard_tab' => 'profiles']) }}" class="inline-flex w-fit rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                            Back to customer list
                        </a>
                    </div>
                    <form method="GET" action="{{ route('suchak.dashboard') }}" class="mt-4 grid gap-3 md:grid-cols-[1fr_12rem_12rem_auto_auto]">
                        <input type="hidden" name="dashboard_tab" value="profiles">
                        <input type="hidden" name="manage_representation" value="{{ $selectedRepresentationId }}">
                        <label class="sr-only" for="business_q">Search CRM and ledger</label>
                        <input id="business_q" name="business_q" value="{{ $businessRecordFilters['business_q'] }}" maxlength="80" placeholder="Search CRM or ledger" class="rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        <label class="sr-only" for="note_type_filter">Note type</label>
                        <select id="note_type_filter" name="note_type" class="rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                            <option value="">All notes</option>
                            @foreach ($noteTypeLabels as $type => $label)
                                <option value="{{ $type }}" @selected($businessRecordFilters['note_type'] === $type)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <label class="sr-only" for="ledger_status_filter">Ledger status</label>
                        <select id="ledger_status_filter" name="ledger_status" class="rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                            <option value="">All payments</option>
                            @foreach ($ledgerStatusLabels as $status => $label)
                                <option value="{{ $status }}" @selected($businessRecordFilters['ledger_status'] === $status)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">Filter</button>
                        <a href="{{ route('suchak.dashboard', ['dashboard_tab' => 'profiles', 'manage_representation' => $selectedRepresentationId]) }}#customer-management" class="rounded-md border border-gray-300 px-4 py-2 text-center text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Clear</a>
                    </form>
                </div>
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ([$selectedRepresentationCard] as $card)
                        @php
                            $representation = $card['representation'];
                            $summary = $card['summary'];
                            $latestConsent = $card['latest_consent'];
                            $pendingConsent = $card['pending_consent'];
                            $acceptedConsent = $card['accepted_consent'];
                            $consentTimeline = $card['consent_timeline'];
                            $crmNotes = $card['crm_notes'];
                            $ledgerEntries = $card['ledger_entries'];
                            $ownedProfile = $representation->matrimonyProfile;
                            $heightCm = is_numeric($ownedProfile?->height_cm) ? (int) round((float) $ownedProfile->height_cm) : null;
                            $heightDisplay = $heightCm !== null
                                ? ((int) floor($heightCm / 30.48)).' ft '.((int) round(($heightCm / 2.54) % 12)).' in'.' / '.$heightCm.' cm'
                                : 'Not available';
                            $residenceDisplay = $ownedProfile?->residenceLocationDisplayLine()
                                ?: collect([$summary['location']['city'] ?? null, $summary['location']['district'] ?? null])->filter()->implode(', ');
                        @endphp
                        <article class="p-5">
                            <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                                <div class="min-w-0">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Matrimony ID #{{ $ownedProfile?->id ?? $representation->matrimony_profile_id ?? 'pending' }}</p>
                                    <h3 class="mt-1 text-base font-semibold text-gray-900 dark:text-gray-100">{{ $ownedProfile?->full_name ?: 'Name pending' }}</h3>
                                    <div class="mt-2 flex flex-wrap gap-2 text-xs">
                                        <span class="rounded-full bg-gray-100 px-2 py-1 font-medium text-gray-700 dark:bg-gray-900 dark:text-gray-200">{{ ucfirst($representation->representation_status) }}</span>
                                        <span class="rounded-full bg-gray-100 px-2 py-1 font-medium text-gray-700 dark:bg-gray-900 dark:text-gray-200">Consent: {{ ucfirst($representation->consent_status) }}</span>
                                        <span class="rounded-full bg-gray-100 px-2 py-1 font-medium text-gray-700 dark:bg-gray-900 dark:text-gray-200">{{ ucwords(str_replace('_', ' ', $representation->representation_mode)) }}</span>
                                    </div>
                                    <dl class="mt-4 grid gap-3 text-sm text-gray-700 dark:text-gray-300 md:grid-cols-2">
                                        <div>
                                            <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Name</dt>
                                            <dd>{{ $ownedProfile?->full_name ?: 'Not available' }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Gender</dt>
                                            <dd>{{ $ownedProfile?->gender?->label ?? $summary['basic']['gender'] ?? 'Not available' }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Height</dt>
                                            <dd>{{ $heightDisplay }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Village / residence</dt>
                                            <dd>{{ $residenceDisplay ?: 'Not available' }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Age</dt>
                                            <dd>{{ $summary['basic']['age_range'] ?? 'Not available' }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Education</dt>
                                            <dd>{{ $summary['education']['highest'] ?? 'Not available' }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Location</dt>
                                            <dd>{{ collect([$summary['location']['city'] ?? null, $summary['location']['district'] ?? null])->filter()->implode(', ') ?: 'Broad location unavailable' }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Profile state</dt>
                                            <dd>{{ ucfirst((string) ($representation->matrimonyProfile?->lifecycle_state ?? 'unknown')) }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Consent valid until</dt>
                                            <dd>{{ $representation->consent_valid_until?->format('Y-m-d H:i') ?: ($representation->consent_status === \App\Models\SuchakProfileRepresentation::CONSENT_ACCEPTED ? 'Until revoked' : 'Not active') }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Latest consent</dt>
                                            <dd>{{ $latestConsent ? ucfirst(str_replace('_', ' ', $latestConsent->consent_status)).' / '.ucwords(str_replace('_', ' ', $latestConsent->consent_channel)) : 'Not requested' }}</dd>
                                        </div>
                                    </dl>

                                    @if ($consentTimeline->isNotEmpty())
                                        <div class="mt-4 rounded-md border border-gray-200 bg-gray-50 p-3 text-xs dark:border-gray-700 dark:bg-gray-900">
                                            <p class="font-semibold text-gray-700 dark:text-gray-200">Consent timeline</p>
                                            <div class="mt-2 space-y-1">
                                                @foreach ($consentTimeline as $event)
                                                    <div class="flex flex-col gap-0.5 sm:flex-row sm:items-center sm:justify-between">
                                                        <span class="text-gray-700 dark:text-gray-300">{{ ucfirst(str_replace('_', ' ', $event->event_type)) }} · {{ $event->actor_type }}</span>
                                                        <span class="text-gray-500 dark:text-gray-400">{{ $event->created_at?->format('Y-m-d H:i') }}</span>
                                                    </div>
                                                    @if ($event->event_note)
                                                        <div class="text-gray-500 dark:text-gray-400">{{ $event->event_note }}</div>
                                                    @endif
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                </div>
                                <div class="flex min-w-[16rem] flex-col gap-3">
                                    @if ($ownedProfile)
                                        <a href="{{ route('suchak.representations.profile-form', $representation) }}" class="w-full rounded-md bg-gray-900 px-4 py-2 text-center text-sm font-semibold text-white hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">
                                            Manage profile form
                                        </a>
                                        <a href="{{ route('matrimony.profile.show', $ownedProfile) }}" class="w-full rounded-md border border-gray-300 px-4 py-2 text-center text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                                            View public profile
                                        </a>
                                    @endif

                                    @php
                                        $consentAction = $card['can_renew_consent'] ? route('suchak.representations.consents.renew', $representation) : route('suchak.representations.consents.request', $representation);
                                        $defaultConsentType = \App\Models\SuchakConsent::TYPE_ONE_YEAR;
                                        $latestMethod = $latestConsent?->consent_method ?: $latestConsent?->consent_channel;
                                        $pendingMethod = $pendingConsent?->consent_method ?: $pendingConsent?->consent_channel;
                                        $pendingIsPlatform = $pendingMethod === \App\Models\SuchakConsent::METHOD_PLATFORM_ASSISTED_LINK
                                            || $pendingMethod === \App\Models\SuchakConsent::CHANNEL_ADMIN_ASSISTED;
                                        $pendingIsLink = $pendingConsent && in_array($pendingMethod, [
                                            \App\Models\SuchakConsent::METHOD_SUCHAK_RELAYED_LINK,
                                            \App\Models\SuchakConsent::METHOD_PLATFORM_ASSISTED_LINK,
                                            \App\Models\SuchakConsent::CHANNEL_WHATSAPP_DEEP_LINK,
                                            \App\Models\SuchakConsent::CHANNEL_ADMIN_ASSISTED,
                                        ], true);
                                        $noticeForPendingConsent = $pendingConsent && (int) session('suchak_consent_notice_id') === (int) $pendingConsent->id;
                                    @endphp

                                    @if ($pendingConsent)
                                        <div class="space-y-3 rounded-md border border-amber-200 bg-amber-50 p-3 dark:border-amber-900 dark:bg-amber-950/30">
                                            <div>
                                                <div class="text-xs font-semibold uppercase text-amber-900 dark:text-amber-100">
                                                    {{ $pendingIsPlatform ? 'Platform-assisted consent in progress' : 'Consent link ready' }}
                                                </div>
                                                <p class="mt-1 text-xs text-amber-900 dark:text-amber-100">Waiting for customer/family response</p>
                                            </div>
                                            <dl class="grid gap-2 text-xs text-amber-950 dark:text-amber-100">
                                                <div>
                                                    <dt class="font-semibold uppercase">Requested mobile</dt>
                                                    <dd>{{ $maskConsentMobile($pendingConsent->intended_mobile ?: $pendingConsent->consent_mobile_number) }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="font-semibold uppercase">Relation</dt>
                                                    <dd>{{ $consentRelationOptions[$pendingConsent->consent_giver_relation ?: $pendingConsent->relationship_to_candidate] ?? ($pendingConsent->relationship_to_candidate ?: 'Not available') }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="font-semibold uppercase">Valid until</dt>
                                                    <dd>{{ ($pendingConsent->expires_at ?? $pendingConsent->token_expires_at)?->format('Y-m-d H:i') ?: 'Not available' }}</dd>
                                                </div>
                                            </dl>

                                            @if ($pendingIsPlatform)
                                                <p class="rounded-md border border-amber-300 bg-white px-3 py-2 text-xs text-amber-900 dark:border-amber-800 dark:bg-gray-950 dark:text-amber-100">
                                                    Delivery gateway is not connected. Use this link for testing or connect notification provider later.
                                                </p>
                                            @endif

                                            @if ($noticeForPendingConsent && session('suchak_consent_forward_message'))
                                                <div class="rounded-md border border-amber-300 bg-white p-2 dark:border-amber-800 dark:bg-gray-950">
                                                    <p class="text-xs font-semibold text-gray-900 dark:text-gray-100">Message to send</p>
                                                    <textarea readonly rows="5" class="mt-1 w-full rounded-md border-gray-300 bg-gray-50 text-xs shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">{{ session('suchak_consent_forward_message') }}</textarea>
                                                    <div class="mt-2 flex flex-col gap-2 sm:flex-row">
                                                        <button type="button" onclick="navigator.clipboard?.writeText(this.closest('div').previousElementSibling.value)" class="rounded-md border border-gray-300 px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">
                                                            Copy message
                                                        </button>
                                                        @if (session('suchak_consent_whatsapp_url'))
                                                            <a href="{{ session('suchak_consent_whatsapp_url') }}" target="_blank" rel="noopener" class="rounded-md bg-emerald-600 px-3 py-2 text-center text-xs font-semibold text-white hover:bg-emerald-700">
                                                                Send on WhatsApp
                                                            </a>
                                                        @endif
                                                        @if (session('suchak_consent_url'))
                                                            <a href="{{ session('suchak_consent_url') }}" target="_blank" rel="noopener" class="rounded-md border border-gray-300 px-3 py-2 text-center text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">
                                                                Open public consent page
                                                            </a>
                                                        @endif
                                                    </div>
                                                </div>
                                            @endif

                                            @if ($pendingIsLink)
                                                <div class="grid gap-2 sm:grid-cols-2">
                                                    <form method="POST" action="{{ route('suchak.consents.resend', $pendingConsent) }}">
                                                        @csrf
                                                        <button type="submit" class="w-full rounded-md border border-amber-300 px-3 py-2 text-sm font-semibold text-amber-900 hover:bg-amber-100 dark:border-amber-700 dark:text-amber-100 dark:hover:bg-amber-900">
                                                            Regenerate link
                                                        </button>
                                                    </form>
                                                    <form method="POST" action="{{ route('suchak.consents.cancel-pending', $pendingConsent) }}" onsubmit="return confirm('Cancel this pending consent request? The old link will stop working and you can create a new request.');">
                                                        @csrf
                                                        <button type="submit" class="w-full rounded-md border border-red-300 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-200 dark:hover:bg-red-950/40">
                                                            Cancel and create new
                                                        </button>
                                                    </form>
                                                </div>
                                            @endif
                                        </div>
                                    @elseif ($acceptedConsent)
                                        <div class="space-y-3 rounded-md border border-emerald-200 bg-emerald-50 p-3 dark:border-emerald-900 dark:bg-emerald-950/30">
                                            <div>
                                                <div class="text-xs font-semibold uppercase text-emerald-900 dark:text-emerald-100">Consent active</div>
                                                <p class="mt-1 text-xs text-emerald-900 dark:text-emerald-100">
                                                    {{ $consentMethodLabel($acceptedConsent->consent_method ?: $acceptedConsent->consent_channel) }}
                                                </p>
                                            </div>
                                            <dl class="grid gap-2 text-xs text-emerald-950 dark:text-emerald-100">
                                                <div>
                                                    <dt class="font-semibold uppercase">Accepted</dt>
                                                    <dd>{{ $acceptedConsent->accepted_at?->format('Y-m-d H:i') ?: 'Recorded' }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="font-semibold uppercase">Consent valid until</dt>
                                                    <dd>{{ $acceptedConsent->valid_until?->format('Y-m-d H:i') ?: 'Until revoked' }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="font-semibold uppercase">Evidence</dt>
                                                    <dd>
                                                        @if (($acceptedConsent->consent_method ?: $acceptedConsent->consent_channel) === \App\Models\SuchakConsent::METHOD_OFFLINE_SIGNED_PROOF)
                                                            Offline proof uploaded
                                                        @elseif (($acceptedConsent->consent_method ?: $acceptedConsent->consent_channel) === \App\Models\SuchakConsent::METHOD_PLATFORM_ASSISTED_LINK)
                                                            Platform-assisted link accepted
                                                        @else
                                                            Accepted for requested mobile number
                                                        @endif
                                                    </dd>
                                                </div>
                                            </dl>
                                            @if ($card['can_renew_consent'])
                                                <button type="button" x-data x-on:click="$dispatch('open-consent-modal-{{ $representation->id }}')" class="w-full rounded-md border border-emerald-300 px-3 py-2 text-sm font-semibold text-emerald-900 hover:bg-emerald-100 dark:border-emerald-700 dark:text-emerald-100 dark:hover:bg-emerald-900">
                                                    Renew consent
                                                </button>
                                            @endif
                                        </div>
                                    @elseif ($latestConsent && in_array($latestConsent->consent_status, [\App\Models\SuchakConsent::STATUS_REJECTED, \App\Models\SuchakConsent::STATUS_EXPIRED], true))
                                        <div class="space-y-2 rounded-md border border-red-200 bg-red-50 p-3 text-sm dark:border-red-900 dark:bg-red-950/30">
                                            <p class="font-semibold text-red-900 dark:text-red-100">{{ $latestConsent->consent_status === \App\Models\SuchakConsent::STATUS_REJECTED ? 'Consent rejected' : 'Consent expired' }}</p>
                                            <p class="text-xs text-red-800 dark:text-red-100">Requested mobile: {{ $maskConsentMobile($latestConsent->intended_mobile ?: $latestConsent->consent_mobile_number) }}</p>
                                        </div>
                                    @endif

                                    @if ($card['can_request_consent'] || $card['can_renew_consent'])
                                        @if (! $acceptedConsent && ! $pendingConsent)
                                            <div class="rounded-md border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-900">
                                                <div>
                                                    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">Consent not active</p>
                                                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">Consent is required before PDF/QR, public routing, and update suggestions.</p>
                                                </div>
                                                @include('suchak.partials.consent-action-modal', [
                                                    'representationId' => $representation->id,
                                                    'modalKey' => 'manage-'.$representation->id,
                                                    'consentAction' => $consentAction,
                                                    'buttonLabel' => $card['can_renew_consent'] ? 'Renew consent' : 'Get consent',
                                                    'buttonClass' => 'mt-3 w-full rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white',
                                                    'defaultConsentMobile' => $ownedProfile?->primary_contact_number,
                                                    'defaultConsentGiverName' => $ownedProfile?->full_name,
                                                    'defaultConsentRelation' => 'candidate_self',
                                                    'defaultConsentType' => $defaultConsentType,
                                                    'consentRelationOptions' => $consentRelationOptions,
                                                ])
                                            </div>
                                        @else
                                            @include('suchak.partials.consent-action-modal', [
                                                'representationId' => $representation->id,
                                                'modalKey' => 'manage-'.$representation->id,
                                                'consentAction' => $consentAction,
                                                'showTriggerButton' => false,
                                                'defaultConsentMobile' => $ownedProfile?->primary_contact_number,
                                                'defaultConsentGiverName' => $ownedProfile?->full_name,
                                                'defaultConsentRelation' => 'candidate_self',
                                                'defaultConsentType' => $defaultConsentType,
                                                'consentRelationOptions' => $consentRelationOptions,
                                            ])
                                        @endif
                                    @endif

                                    @if ($card['can_revoke_consent'] && $acceptedConsent)
                                        <form method="POST" action="{{ route('suchak.consents.revoke', $acceptedConsent) }}" class="space-y-2 rounded-md border border-red-200 bg-red-50 p-3 dark:border-red-900 dark:bg-red-950/30">
                                            @csrf
                                            <textarea name="reason" rows="2" required minlength="10" maxlength="500" placeholder="Revocation reason" class="w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100"></textarea>
                                            <button type="submit" class="w-full rounded-md border border-red-300 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-100 dark:border-red-800 dark:text-red-200 dark:hover:bg-red-950/50">
                                                Revoke consent
                                            </button>
                                        </form>
                                    @endif

                                    <form method="POST" action="{{ route('suchak.representations.exports.store', $representation) }}">
                                        @csrf
                                        <button type="submit" @disabled(! $card['can_export']) class="w-full rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-gray-300 disabled:text-gray-600 dark:disabled:bg-gray-700 dark:disabled:text-gray-300">
                                            Generate PDF/QR
                                        </button>
                                    </form>

                                    @if ($card['can_suggest_updates'] && count($fieldLabels) > 0)
                                        <form method="POST" action="{{ route('suchak.representations.profile-update-suggestions.store', $representation) }}" class="space-y-2">
                                            @csrf
                                            <label class="sr-only" for="field_key_{{ $representation->id }}">Field</label>
                                            <select id="field_key_{{ $representation->id }}" name="field_key" required class="w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                                @foreach ($fieldLabels as $fieldKey => $fieldLabel)
                                                    <option value="{{ $fieldKey }}" @selected(old('field_key') === $fieldKey)>{{ $fieldLabel }}</option>
                                                @endforeach
                                            </select>
                                            <label class="sr-only" for="suggested_value_{{ $representation->id }}">Suggested value</label>
                                            <input id="suggested_value_{{ $representation->id }}" name="suggested_value" value="{{ old('suggested_value') }}" maxlength="4000" placeholder="Suggested value" required class="w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                            <button type="submit" class="w-full rounded-md border border-indigo-300 px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 dark:border-indigo-700 dark:text-indigo-200 dark:hover:bg-indigo-950/40">
                                                Suggest profile update
                                            </button>
                                        </form>
                                    @else
                                        <p class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                            Update suggestions need verified Suchak status and active candidate consent.
                                        </p>
                                    @endif
                                </div>
                            </div>

                            <div class="mt-5 grid gap-4 xl:grid-cols-2">
                                <section class="rounded-md border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">CRM Notes & Follow-ups</h3>
                                    <form method="POST" action="{{ route('suchak.representations.crm-notes.store', $representation) }}" class="mt-3 grid gap-2">
                                        @csrf
                                        <label class="sr-only" for="note_type_{{ $representation->id }}">Note type</label>
                                        <select id="note_type_{{ $representation->id }}" name="note_type" required class="rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                                            @foreach ($noteTypeLabels as $type => $label)
                                                <option value="{{ $type }}" @selected(old('note_type') === $type)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <label class="sr-only" for="follow_up_at_{{ $representation->id }}">Follow-up at</label>
                                        <input id="follow_up_at_{{ $representation->id }}" name="follow_up_at" type="datetime-local" value="{{ old('follow_up_at') }}" class="rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                                        <label class="sr-only" for="note_text_{{ $representation->id }}">Note</label>
                                        <textarea id="note_text_{{ $representation->id }}" name="note_text" rows="3" required maxlength="4000" placeholder="Private note or follow-up" class="rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">{{ old('note_text') }}</textarea>
                                        <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Add note</button>
                                    </form>

                                    <div class="mt-4 space-y-2">
                                        @forelse ($crmNotes as $note)
                                            <div class="rounded-md border border-gray-200 bg-white p-3 text-sm dark:border-gray-700 dark:bg-gray-800">
                                                <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                                    <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $noteTypeLabels[$note->note_type] ?? ucfirst(str_replace('_', ' ', $note->note_type)) }}</span>
                                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $note->created_at?->format('Y-m-d H:i') }}</span>
                                                </div>
                                                @if ($note->follow_up_at)
                                                    <div class="mt-1 text-xs font-semibold text-amber-700 dark:text-amber-200">Follow-up: {{ $note->follow_up_at->format('Y-m-d H:i') }}</div>
                                                @endif
                                                <p class="mt-2 text-gray-700 dark:text-gray-300">{{ $note->note_text }}</p>
                                            </div>
                                        @empty
                                            <p class="rounded-md border border-dashed border-gray-300 px-3 py-4 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">No CRM notes match.</p>
                                        @endforelse
                                    </div>
                                </section>

                                <section class="rounded-md border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Ledger & Customer Payments</h3>
                                    <form method="POST" action="{{ route('suchak.representations.ledger-entries.store', $representation) }}" class="mt-3 grid gap-2 sm:grid-cols-2">
                                        @csrf
                                        <label class="sr-only" for="entry_type_{{ $representation->id }}">Entry type</label>
                                        <select id="entry_type_{{ $representation->id }}" name="entry_type" required class="rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                                            @foreach ($ledgerTypeLabels as $type => $label)
                                                <option value="{{ $type }}" @selected(old('entry_type') === $type)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <label class="sr-only" for="status_{{ $representation->id }}">Status</label>
                                        <select id="status_{{ $representation->id }}" name="status" required class="rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                                            @foreach ($ledgerStatusLabels as $status => $label)
                                                <option value="{{ $status }}" @selected(old('status', \App\Models\SuchakLedgerEntry::STATUS_EXPECTED) === $status)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <label class="sr-only" for="source_owner_{{ $representation->id }}">Source owner</label>
                                        <select id="source_owner_{{ $representation->id }}" name="source_owner" required class="rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                                            <option value="">Source owner</option>
                                            @foreach ($sourceOwnerLabels as $owner => $label)
                                                <option value="{{ $owner }}" @selected(old('source_owner', \App\Models\SuchakPaymentContext::SOURCE_SUCHAK) === $owner)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <label class="sr-only" for="payment_collector_{{ $representation->id }}">Payment collector</label>
                                        <select id="payment_collector_{{ $representation->id }}" name="payment_collector" required class="rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                                            <option value="">Payment collector</option>
                                            @foreach ($paymentCollectorLabels as $collector => $label)
                                                <option value="{{ $collector }}" @selected(old('payment_collector', \App\Models\SuchakPaymentContext::COLLECTOR_SUCHAK) === $collector)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <label class="sr-only" for="amount_{{ $representation->id }}">Amount</label>
                                        <input id="amount_{{ $representation->id }}" name="amount" value="{{ old('amount') }}" inputmode="decimal" placeholder="Amount" class="rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                                        <label class="sr-only" for="currency_{{ $representation->id }}">Currency</label>
                                        <input id="currency_{{ $representation->id }}" name="currency" value="{{ old('currency', 'INR') }}" maxlength="3" required class="rounded-md border-gray-300 text-sm uppercase shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                                        <label class="sr-only" for="due_date_{{ $representation->id }}">Due date</label>
                                        <input id="due_date_{{ $representation->id }}" name="due_date" type="date" value="{{ old('due_date') }}" class="rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                                        <label class="sr-only" for="paid_at_{{ $representation->id }}">Paid at</label>
                                        <input id="paid_at_{{ $representation->id }}" name="paid_at" type="datetime-local" value="{{ old('paid_at') }}" class="rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                                        <label class="sr-only" for="ledger_note_{{ $representation->id }}">Ledger note</label>
                                        <textarea id="ledger_note_{{ $representation->id }}" name="note" rows="2" maxlength="2000" placeholder="Ledger note" class="rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100 sm:col-span-2">{{ old('note') }}</textarea>
                                        <button type="submit" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 sm:col-span-2">Add ledger entry</button>
                                    </form>

                                    <div class="mt-4 space-y-2">
                                        @forelse ($ledgerEntries as $entry)
                                            <div class="rounded-md border border-gray-200 bg-white p-3 text-sm dark:border-gray-700 dark:bg-gray-800">
                                                <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                                    <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $ledgerTypeLabels[$entry->entry_type] ?? ucfirst(str_replace('_', ' ', $entry->entry_type)) }}</span>
                                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $entry->created_at?->format('Y-m-d H:i') }}</span>
                                                </div>
                                                <dl class="mt-2 grid gap-2 text-xs text-gray-600 dark:text-gray-300 sm:grid-cols-2">
                                                    <div>
                                                        <dt class="font-semibold uppercase text-gray-500 dark:text-gray-400">Amount</dt>
                                                        <dd>{{ $entry->amount !== null ? $entry->currency.' '.$entry->amount : 'Not set' }}</dd>
                                                    </div>
                                                    <div>
                                                        <dt class="font-semibold uppercase text-gray-500 dark:text-gray-400">Payment status</dt>
                                                        <dd>{{ $ledgerStatusLabels[$entry->status] ?? ucfirst($entry->status) }}</dd>
                                                    </div>
                                                    <div>
                                                        <dt class="font-semibold uppercase text-gray-500 dark:text-gray-400">Due</dt>
                                                        <dd>{{ $entry->due_date?->format('Y-m-d') ?: '-' }}</dd>
                                                    </div>
                                                    <div>
                                                        <dt class="font-semibold uppercase text-gray-500 dark:text-gray-400">Receipt</dt>
                                                        <dd>{{ $entry->paid_at ? 'Paid '.$entry->paid_at->format('Y-m-d H:i') : ($entry->status === \App\Models\SuchakLedgerEntry::STATUS_PAID ? 'Paid timestamp pending' : 'Not paid') }}</dd>
                                                    </div>
                                                </dl>
                                                @if ($entry->note)
                                                    <p class="mt-2 text-gray-700 dark:text-gray-300">{{ $entry->note }}</p>
                                                @endif
                                            </div>
                                        @empty
                                            <p class="rounded-md border border-dashed border-gray-300 px-3 py-4 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">No ledger entries match.</p>
                                        @endforelse
                                    </div>
                                </section>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
            @endif
            @endif

            <section class="{{ $activeDashboardTab !== 'requests' ? 'hidden ' : '' }}rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-700">
                    <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Incoming Profile Requests</h2>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Members who asked you for more information about your represented profiles.</p>
                        </div>
                        <span class="inline-flex w-fit rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold uppercase text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-100">
                            {{ $incomingProfileRequests->count() }} recent
                        </span>
                    </div>
                </div>
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($incomingProfileRequests as $profileRequest)
                        @php
                            $requesterProfile = $profileRequest->requestingMatrimonyProfile;
                            $targetProfile = $profileRequest->targetMatrimonyProfile;
                            $requesterName = $profileRequestName($requesterProfile, (int) $profileRequest->requesting_matrimony_profile_id);
                            $targetName = $profileRequestName($targetProfile, (int) $profileRequest->target_matrimony_profile_id);
                            $requesterAge = $profileRequestAge($requesterProfile);
                            $targetAge = $profileRequestAge($targetProfile);
                            $requesterPhoto = $profileRequestPhoto($requesterProfile);
                            $targetPhoto = $profileRequestPhoto($targetProfile);
                            $requesterUrl = $profileRequestUrl($requesterProfile);
                            $targetUrl = $profileRequestUrl($targetProfile);
                            $requestMessage = trim((string) ($profileRequest->message ?? ''));
                            $replyMessage = trim((string) ($profileRequest->chatMessage?->body_text ?? ''));
                            $conversationMessages = $profileRequest->chatConversation?->messages
                                ? $profileRequest->chatConversation->messages
                                    ->sortBy(fn ($message) => sprintf(
                                        '%012d-%012d',
                                        $message->sent_at?->timestamp ?? 0,
                                        (int) $message->id,
                                    ))
                                    ->values()
                                : collect();
                            if ($conversationMessages->count() > 8) {
                                $conversationMessages = $conversationMessages->slice(-8)->values();
                            }
                            $statusLabel = \Illuminate\Support\Str::headline((string) $profileRequest->request_status);
                            $latestConversationMessage = $conversationMessages->last();
                            $latestMessagePreview = trim((string) ($latestConversationMessage?->body_text ?: $requestMessage));
                            if (mb_strlen($latestMessagePreview) > 120) {
                                $latestMessagePreview = mb_substr($latestMessagePreview, 0, 120).'...';
                            }
                        @endphp
                        <article class="p-4">
                            <details class="group">
                                <summary class="list-none cursor-pointer rounded-md border border-gray-200 bg-gray-50 px-4 py-3 transition hover:bg-gray-100 dark:border-gray-700 dark:bg-gray-900 dark:hover:bg-gray-950 [&::-webkit-details-marker]:hidden">
                                    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">Request #{{ $profileRequest->id }}</span>
                                                <span class="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-gray-700 shadow-sm dark:bg-gray-950 dark:text-gray-200">{{ $statusLabel }}</span>
                                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $profileRequest->created_at?->format('Y-m-d H:i') }}</span>
                                            </div>
                                            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                                <span class="inline-flex min-w-0 items-center gap-2">
                                                    <img src="{{ $requesterPhoto }}" alt="" class="h-8 w-8 rounded-full border border-gray-200 object-cover dark:border-gray-700">
                                                    <span class="min-w-0">
                                                        <span class="block truncate font-semibold text-gray-900 dark:text-gray-100">{{ $requesterName }}</span>
                                                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $requesterAge }}</span>
                                                    </span>
                                                </span>
                                                <span class="text-gray-400">→</span>
                                                <span class="inline-flex min-w-0 items-center gap-2">
                                                    <img src="{{ $targetPhoto }}" alt="" class="h-8 w-8 rounded-full border border-gray-200 object-cover dark:border-gray-700">
                                                    <span class="min-w-0">
                                                        <span class="block truncate font-semibold text-gray-900 dark:text-gray-100">{{ $targetName }}</span>
                                                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $targetAge }}</span>
                                                    </span>
                                                </span>
                                            </div>
                                            <p class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400">{{ $latestMessagePreview !== '' ? $latestMessagePreview : 'No message provided.' }}</p>
                                        </div>
                                        <span class="inline-flex w-fit shrink-0 rounded-md bg-gray-900 px-3 py-2 text-xs font-semibold text-white dark:bg-gray-100 dark:text-gray-900">
                                            Open details / reply
                                        </span>
                                    </div>
                                </summary>
                                <div class="mt-4">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                            Request #{{ $profileRequest->id }}
                                        </p>
                                        <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                            {{ $statusLabel }}
                                        </span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ $profileRequest->created_at?->format('Y-m-d H:i') }}
                                        </span>
                                    </div>

                                    <div class="mt-3 grid gap-3 text-sm text-gray-700 dark:text-gray-300 md:grid-cols-2">
                                        <a href="{{ $requesterUrl ?: '#' }}" class="flex items-center gap-3 rounded-md bg-gray-50 px-3 py-3 transition hover:bg-gray-100 dark:bg-gray-900 dark:hover:bg-gray-950 {{ $requesterUrl ? '' : 'pointer-events-none' }}">
                                            <img src="{{ $requesterPhoto }}" alt="" class="h-14 w-14 rounded-full border border-gray-200 object-cover dark:border-gray-700">
                                            <span class="min-w-0">
                                                <span class="block text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Member</span>
                                                <span class="mt-1 block truncate font-semibold text-gray-900 dark:text-gray-100">{{ $requesterName }}</span>
                                                <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">{{ $requesterAge }}</span>
                                                @if ($requesterUrl)
                                                    <span class="mt-1 block text-xs font-semibold text-indigo-700 dark:text-indigo-300">Open profile</span>
                                                @endif
                                            </span>
                                        </a>
                                        <a href="{{ $targetUrl ?: '#' }}" class="flex items-center gap-3 rounded-md bg-gray-50 px-3 py-3 transition hover:bg-gray-100 dark:bg-gray-900 dark:hover:bg-gray-950 {{ $targetUrl ? '' : 'pointer-events-none' }}">
                                            <img src="{{ $targetPhoto }}" alt="" class="h-14 w-14 rounded-full border border-gray-200 object-cover dark:border-gray-700">
                                            <span class="min-w-0">
                                                <span class="block text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">For profile</span>
                                                <span class="mt-1 block truncate font-semibold text-gray-900 dark:text-gray-100">{{ $targetName }}</span>
                                                <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">{{ $targetAge }}</span>
                                                @if ($targetUrl)
                                                    <span class="mt-1 block text-xs font-semibold text-indigo-700 dark:text-indigo-300">Open profile</span>
                                                @endif
                                            </span>
                                        </a>
                                    </div>

                                    <div class="mt-3 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                        <p class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Member message</p>
                                        <p class="mt-1 whitespace-pre-line">{{ $requestMessage !== '' ? $requestMessage : 'No message provided.' }}</p>
                                    </div>

                                    @if ($conversationMessages->isNotEmpty())
                                        <div class="mt-3 rounded-md border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950">
                                            <p class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Conversation history</p>
                                            <div class="mt-2 space-y-2">
                                                @foreach ($conversationMessages as $conversationMessage)
                                                    @php
                                                        $fromMember = (int) $conversationMessage->sender_profile_id === (int) $profileRequest->requesting_matrimony_profile_id;
                                                        $lineTone = $fromMember
                                                            ? 'border-blue-100 bg-blue-50 text-blue-950 dark:border-blue-900/60 dark:bg-blue-950/25 dark:text-blue-100'
                                                            : 'border-emerald-100 bg-emerald-50 text-emerald-950 dark:border-emerald-900/60 dark:bg-emerald-950/25 dark:text-emerald-100';
                                                    @endphp
                                                    <div class="rounded-md border px-3 py-2 {{ $lineTone }}">
                                                        <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                                                            <span class="font-semibold">{{ $fromMember ? 'Member' : 'Suchak reply' }}</span>
                                                            <span>{{ $conversationMessage->sent_at?->format('Y-m-d H:i') }}</span>
                                                        </div>
                                                        <p class="mt-1 whitespace-pre-line leading-relaxed">{{ $conversationMessage->body_text ?: '[Attachment]' }}</p>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                </div>

                                <div class="w-full lg:max-w-md">
                                    @if ($profileRequest->chatMessage)
                                        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/35 dark:text-emerald-100">
                                            <p class="font-semibold">Latest message sent to member chat</p>
                                            <p class="mt-1 text-xs">
                                                {{ $profileRequest->replied_at?->format('Y-m-d H:i') ?: $profileRequest->chatMessage->sent_at?->format('Y-m-d H:i') }}
                                            </p>
                                            @if ($replyMessage !== '')
                                                <p class="mt-2 whitespace-pre-line text-emerald-950 dark:text-emerald-50">{{ $replyMessage }}</p>
                                            @endif
                                        </div>
                                    @endif

                                    @if ($profileRequest->isOpen())
                                        <div class="{{ $profileRequest->chatMessage ? 'mt-4 ' : '' }}rounded-md border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">Message member</p>
                                            <div class="mt-2 grid gap-2">
                                                @foreach ($profileRequestReplyTemplates as $template)
                                                    <button type="button" data-profile-request-template="{{ $template }}" data-profile-request-target="profile_request_reply_{{ $profileRequest->id }}" class="w-full rounded-md border border-emerald-200 bg-white px-3 py-2 text-left text-sm font-medium leading-6 text-emerald-900 hover:bg-emerald-50 dark:border-emerald-900 dark:bg-gray-950 dark:text-emerald-100 dark:hover:bg-emerald-950/35">
                                                        {{ $template }}
                                                    </button>
                                                @endforeach
                                            </div>

                                            <form method="POST" action="{{ route('suchak.profile-requests.reply', $profileRequest) }}" class="mt-4 border-t border-gray-200 pt-4 dark:border-gray-700">
                                                @csrf
                                                <label for="profile_request_reply_{{ $profileRequest->id }}" class="block text-sm font-semibold text-gray-900 dark:text-gray-100">Message</label>
                                                <textarea id="profile_request_reply_{{ $profileRequest->id }}" name="reply_message" rows="3" maxlength="1600" required class="mt-2 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100" placeholder="Write what the member should know next.">{{ old('reply_message') }}</textarea>
                                                <button type="submit" class="mt-3 inline-flex rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                                                    Send message
                                                </button>
                                            </form>
                                        </div>
                                    @else
                                        <div class="rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                            This request is not open.
                                        </div>
                                    @endif
                                </div>
                            </div>
                                </div>
                            </details>
                        </article>
                    @empty
                        <div class="p-6 text-sm text-gray-600 dark:text-gray-300">No incoming profile requests yet.</div>
                    @endforelse
                </div>
            </section>

            @if ($activeDashboardTab === 'profiles' && ! $selectedRepresentationCard)
            <section class="rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-700">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Incoming Collaborations</h2>
                </div>
                <div class="p-5">
                    <div class="flex flex-col gap-4 rounded-md border border-gray-200 bg-gray-50 p-4 md:flex-row md:items-center md:justify-between dark:border-gray-700 dark:bg-gray-900">
                        <div>
                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                You have {{ number_format($pendingCollaborations->count()) }} incoming collaboration requests
                            </p>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                                Review candidate summaries, commission acknowledgement, and accept/reject decisions in Collaboration Center.
                            </p>
                        </div>
                        <a href="{{ route('suchak.collaborations.index', ['direction' => 'incoming', 'status' => \App\Models\SuchakCollaborationRequest::STATUS_PENDING]) }}" class="inline-flex w-fit rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                            Open Collaboration Center
                        </a>
                    </div>
                </div>
            </section>
            @endif
            <section class="{{ $activeDashboardTab !== 'money' ? 'hidden ' : '' }}rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Billing & Limits</h2>
                <div class="mt-3 rounded-md bg-gray-50 p-3 text-sm dark:bg-gray-900">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Subscription status</span>
                        <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $suchakText::label($paymentStatus['status'] ?? 'none') }}</span>
                    </div>
                    <div class="mt-2 grid gap-2 text-xs text-gray-600 dark:text-gray-300">
                        <div>Free trial policy: {{ number_format($billingPolicySummary['free_trial_days']) }} days</div>
                        <div>Grace policy: {{ number_format($billingPolicySummary['grace_period_days']) }} days</div>
                        <div>Payment mode: {{ $suchakText::label($billingPolicySummary['payment_mode']) }}</div>
                    </div>
                </div>

                @if ($activeSubscription?->suchakPlan)
                    <div class="mt-4">
                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $suchakText::column($activeSubscription->suchakPlan, 'name') }}</p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ $activeSubscription->suchakPlan->hasConfiguredPrice() ? $activeSubscription->suchakPlan->currency.' '.$activeSubscription->suchakPlan->price_amount : $suchakText::label('manual_assignment_price_not_configured') }}
                        </p>
                        <dl class="mt-3 grid gap-2 text-xs text-gray-600 dark:text-gray-300">
                            <div class="flex justify-between gap-3">
                                <dt>Starts</dt>
                                <dd class="font-semibold text-gray-900 dark:text-gray-100">{{ $activeSubscription->starts_at?->format('Y-m-d') ?: '-' }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt>Ends</dt>
                                <dd class="font-semibold text-gray-900 dark:text-gray-100">{{ $activeSubscription->ends_at?->format('Y-m-d') ?: 'No end date' }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="mt-4 border-t border-gray-200 pt-4 dark:border-gray-700">
                        <p class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Usage</p>
                    </div>

                    <dl class="mt-3 space-y-3 text-sm text-gray-700 dark:text-gray-300">
                        @forelse ($featureLimits as $feature => $value)
                            @php
                                $usage = $billingUsageSummary[$feature] ?? null;
                                $limitDisplay = is_bool($value) ? ($value ? $suchakText::label('enabled') : $suchakText::label('disabled')) : (string) $value;
                                $usageDisplay = null;

                                if ($usage && $usage['used'] !== null) {
                                    $usageDisplay = is_int($value)
                                        ? number_format($usage['used']).' / '.number_format($value)
                                        : number_format($usage['used']).' used';

                                    if ($usage['window']) {
                                        $usageDisplay .= ' '.$usage['window'];
                                    }
                                }
                            @endphp
                            <div class="rounded-md border border-gray-200 px-3 py-2 dark:border-gray-700">
                                <div class="flex justify-between gap-3">
                                <dt>{{ $suchakText::label($feature) }}</dt>
                                    <dd class="font-semibold text-gray-900 dark:text-gray-100">{{ $usageDisplay ?: $limitDisplay }}</dd>
                                </div>
                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    Limit: {{ $limitDisplay }}{{ $usage && $usage['label'] ? ' · '.$usage['label'] : '' }}
                                </div>
                            </div>
                        @empty
                            <div>No feature limits configured.</div>
                        @endforelse
                    </dl>
                @else
                    <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">No active Suchak subscription is assigned.</p>
                @endif

                @if ($catalogPlans->isNotEmpty())
                    <div class="mt-5 border-t border-gray-200 pt-4 dark:border-gray-700">
                        <p class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Visible catalog</p>
                        <div class="mt-3 space-y-2">
                            @foreach ($catalogPlans as $plan)
                                <div class="rounded-md bg-gray-50 px-3 py-2 text-sm dark:bg-gray-900">
                                    <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $suchakText::column($plan, 'name') }}</div>
                                    @if ($suchakText::column($plan, 'description') !== '')
                                        <div class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ $suchakText::column($plan, 'description') }}</div>
                                    @endif
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ $plan->hasConfiguredPrice() ? $plan->currency.' '.$plan->price_amount : $suchakText::label('manual_assignment') }}
                                        · {{ number_format($plan->billing_period_days ?? 30) }} days
                                    </div>
                                    @if (($billingPolicySummary['payment_mode'] ?? '') === 'payu_test_mode' && $plan->hasConfiguredPrice() && strtoupper((string) $plan->currency) === 'INR')
                                        <form method="POST" action="{{ route('suchak.plans.payu.start', $plan) }}" class="mt-3">
                                            @csrf
                                            <button type="submit" class="w-full rounded-md bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700">
                                                Pay with PayU test
                                            </button>
                                        </form>
                                    @elseif (($billingPolicySummary['payment_mode'] ?? '') !== 'payu_test_mode')
                                        <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">PayU test checkout is not enabled.</div>
                                    @endif
                                    @if ($plan->enabledFeatures->isNotEmpty())
                                        <div class="mt-2 space-y-1 text-xs text-gray-600 dark:text-gray-300">
                                            @foreach ($plan->enabledFeatures->take(4) as $feature)
                                                @php
                                                    $typedValue = $feature->typedValue();
                                                @endphp
                                                <div class="flex justify-between gap-2">
                                                    <span>{{ $suchakText::label($feature->feature_key) }}</span>
                                                    <span class="font-semibold">{{ is_bool($typedValue) ? ($typedValue ? $suchakText::label('yes') : $suchakText::label('no')) : $typedValue }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($recentPlanPayments->isNotEmpty())
                    <div class="mt-5 border-t border-gray-200 pt-4 dark:border-gray-700">
                        <p class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Payment history</p>
                        <div class="mt-3 space-y-2">
                            @foreach ($recentPlanPayments as $payment)
                                <div class="rounded-md bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:bg-gray-900 dark:text-gray-300">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $suchakText::column($payment, 'plan_name') }}</div>
                                            <div>{{ $payment->currency }} {{ $payment->amount }} · {{ $suchakText::label($payment->payment_status) }}</div>
                                            <div>Txn {{ $payment->txnid }}</div>
                                        </div>
                                        <div class="text-right">
                                            <div>{{ $payment->created_at?->format('Y-m-d') }}</div>
                                            <div>{{ $payment->invoice?->invoice_number ?: 'No receipt yet' }}</div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </section>

            <section class="{{ $activeDashboardTab !== 'records' ? 'hidden ' : '' }}rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Recent Source Links</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($recentSourceLinks as $link)
                        <div class="text-sm">
                            <div class="font-medium text-gray-900 dark:text-gray-100">{{ $suchakText::label($link->source_status) }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Intake #{{ $link->biodata_intake_id }} · {{ $link->created_at?->format('Y-m-d H:i') }}</div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-600 dark:text-gray-300">No source links yet.</p>
                    @endforelse
                </div>
            </section>

            <section class="{{ $activeDashboardTab !== 'sharing' ? 'hidden ' : '' }}rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Recent PDF/QR Records</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($recentExports as $export)
                        @php
                            $latestQrToken = $export->qrTokens->first();
                        @endphp
                        <div class="text-sm">
                            <div class="font-medium text-gray-900 dark:text-gray-100">Export #{{ $export->id }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $export->created_at?->format('Y-m-d H:i') }} · QR records: {{ $export->qrTokens->count() }}
                                · {{ $export->file_path ? 'PDF ready' : 'PDF missing' }}
                            </div>
                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Downloaded: {{ $export->downloaded_at?->format('Y-m-d H:i') ?: 'Not yet' }} · Shared: {{ $export->shared_at?->format('Y-m-d H:i') ?: 'Not yet' }}
                            </div>
                            @if ($latestQrToken)
                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    Latest QR: {{ $latestQrToken->revoked_at ? 'Revoked' : 'Active' }} · Expires: {{ $latestQrToken->expires_at?->format('Y-m-d H:i') ?: 'Not configured' }}
                                </div>
                            @endif
                            <div class="mt-2 flex flex-wrap gap-2">
                                @if ($export->file_path)
                                    <a href="{{ route('suchak.exports.download', $export) }}" class="rounded-md bg-gray-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">
                                        Download
                                    </a>
                                @endif
                                <form method="POST" action="{{ route('suchak.exports.mark-shared', $export) }}">
                                    @csrf
                                    <button type="submit" class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                                        Mark shared
                                    </button>
                                </form>
                                @if ($latestQrToken && ! $latestQrToken->revoked_at)
                                    <form method="POST" action="{{ route('suchak.qr-tokens.revoke', $latestQrToken) }}">
                                        @csrf
                                        <button type="submit" class="rounded-md border border-red-300 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-200 dark:hover:bg-red-950/40">
                                            Revoke QR
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-600 dark:text-gray-300">No PDF/QR records yet.</p>
                    @endforelse
                </div>
            </section>

            <section class="{{ $activeDashboardTab !== 'records' ? 'hidden ' : '' }}rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Recent Suggestions</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($recentSuggestions as $suggestion)
                        <div class="text-sm">
                            <div class="font-medium text-gray-900 dark:text-gray-100">{{ ucwords(str_replace('_', ' ', $suggestion->field_key)) }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $suchakText::label($suggestion->suggestion_status) }} · {{ $suggestion->created_at?->format('Y-m-d H:i') }}</div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-600 dark:text-gray-300">No profile update suggestions yet.</p>
                    @endforelse
                </div>
            </section>

            <section class="{{ $activeDashboardTab !== 'records' ? 'hidden ' : '' }}rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Recent Activity</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($activityLogs as $activity)
                        <div class="text-sm">
                            <div class="font-medium text-gray-900 dark:text-gray-100">{{ $activity->action_type }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $activity->occurred_at?->format('Y-m-d H:i') }}</div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-600 dark:text-gray-300">No activity logged yet.</p>
                    @endforelse
                </div>
            </section>
    </div>
    </div>
</div>
<script>
document.addEventListener('click', function (event) {
    const button = event.target.closest('[data-profile-request-template]');
    if (!button) {
        return;
    }

    const targetId = button.getAttribute('data-profile-request-target');
    const textarea = targetId ? document.getElementById(targetId) : null;
    if (!textarea) {
        return;
    }

    textarea.value = button.getAttribute('data-profile-request-template') || '';
    textarea.focus();
});
</script>
@endsection

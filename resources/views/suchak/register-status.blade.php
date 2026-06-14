@extends('layouts.app')

@section('content')
@php
    use App\Models\SuchakAccount;
    use App\Models\SuchakVerificationRecord;

    $mobileVerified = (bool) ($onboarding['mobile_verified'] ?? false);
    $isVerified = (bool) ($onboarding['is_verified'] ?? false);
    $isBlocked = (bool) ($onboarding['is_blocked'] ?? false);
    $steps = collect($onboarding['steps'] ?? []);
    $currentStepKey = (string) ($onboarding['current_step_key'] ?? 'registration');
    $currentStep = $onboarding['current_step'] ?? $steps->first();
    $currentStepState = (string) ($currentStep['state'] ?? 'current');
    $messageKey = (string) ($onboarding['message_key'] ?? 'review_pending');
    $kycRows = collect($onboarding['document_rows'] ?? []);
    $uploadedDocumentCount = (int) ($onboarding['uploaded_document_count'] ?? 0);
    $adminReviewPending = (bool) ($onboarding['admin_review_pending'] ?? false);

    $statusTone = match (true) {
        in_array($suchakAccount->verification_status, [
            SuchakAccount::VERIFICATION_REJECTED,
            SuchakAccount::VERIFICATION_SUSPENDED,
            SuchakAccount::VERIFICATION_ARCHIVED,
        ], true) => 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100',
        $adminReviewPending => 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-100',
        $suchakAccount->verification_status === SuchakAccount::VERIFICATION_VERIFIED => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100',
        default => 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100',
    };

    $businessTypeLabel = __('suchak.business_types.'.$suchakAccount->business_type);
    if ($businessTypeLabel === 'suchak.business_types.'.$suchakAccount->business_type) {
        $businessTypeLabel = ucfirst((string) $suchakAccount->business_type);
    }

    $statusLabel = __('suchak.labels.common.'.$suchakAccount->verification_status);
    if ($statusLabel === 'suchak.labels.common.'.$suchakAccount->verification_status) {
        $statusLabel = ucfirst((string) $suchakAccount->verification_status);
    }
    if ($adminReviewPending && $suchakAccount->verification_status === SuchakAccount::VERIFICATION_VERIFIED) {
        $statusLabel = __('suchak.status.work_allowed_review_pending_badge');
    }

    $publicStatusLabel = __('suchak.labels.common.'.$suchakAccount->public_status);
    if ($publicStatusLabel === 'suchak.labels.common.'.$suchakAccount->public_status) {
        $publicStatusLabel = ucfirst((string) $suchakAccount->public_status);
    }
    $currentMarkerClass = match ($currentStepState) {
        'complete' => 'bg-emerald-400',
        'submitted',
        'in_progress' => 'bg-blue-400',
        'blocked' => 'bg-red-400',
        default => 'bg-gray-300',
    };

@endphp

<div class="mx-auto max-w-6xl px-4 py-8">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <a href="{{ route('suchak.home') }}" class="text-sm font-semibold text-red-700 hover:underline dark:text-red-300">{{ __('suchak.status.back') }}</a>
            <h1 class="mt-2 text-3xl font-bold text-gray-900 dark:text-gray-100">{{ __('suchak.status.title') }}</h1>
            <p class="mt-3 max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-300">
                {{ __('suchak.status.intro') }}
            </p>
        </div>

        @if ($mobileVerified && ! $isBlocked)
            <a href="{{ route('suchak.dashboard', ['dashboard_tab' => 'profile']) }}" class="inline-flex items-center justify-center rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                {{ __('suchak.status.open_dashboard') }}
            </a>
        @endif
    </div>

    @if (session('success') || session('status') || session('info') || session('error'))
        <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
            {{ session('success') ?: session('status') ?: session('info') ?: session('error') }}
        </div>
    @endif

    <section data-suchak-status-pipeline class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="max-w-2xl">
                <p class="text-xs font-semibold uppercase tracking-wide text-red-700 dark:text-red-300">{{ __('suchak.status.pipeline_label') }}</p>
                <h2 class="mt-2 text-2xl font-bold text-gray-950 dark:text-gray-100">{{ __('suchak.status.messages.'.$messageKey.'.title') }}</h2>
                <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ __('suchak.status.messages.'.$messageKey.'.body') }}</p>
            </div>
            <span class="inline-flex w-fit rounded-full border px-3 py-1 text-xs font-semibold {{ $statusTone }}">
                {{ $statusLabel }}
            </span>
        </div>

        <div class="mt-6 rounded-lg bg-gray-950 px-4 py-3 text-white shadow-sm dark:bg-gray-100 dark:text-gray-950 sm:inline-flex sm:items-center sm:gap-3">
            <span class="inline-flex items-center gap-2 text-sm font-semibold">
                <span class="h-2.5 w-2.5 rounded-full {{ $currentMarkerClass }}"></span>
                {{ __('suchak.status.you_are_here') }}
            </span>
            <span class="mt-1 block text-lg font-bold sm:mt-0">{{ $currentStep['label'] }}</span>
            <span class="mt-1 block text-sm text-gray-300 dark:text-gray-600 sm:mt-0">{{ $currentStep['detail'] }}</span>
        </div>

        @include('suchak.partials.onboarding-tracker', [
            'steps' => $steps,
            'currentStepKey' => $currentStepKey,
        ])

        <div class="mt-8 space-y-3">
            @foreach ($steps as $step)
                @php
                    $detailTone = match ($step['state']) {
                        'complete' => 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/30',
                        'submitted' => 'border-blue-200 bg-blue-50 dark:border-blue-900 dark:bg-blue-950/30',
                        'in_progress' => 'border-blue-200 bg-blue-50 dark:border-blue-900 dark:bg-blue-950/30',
                        'current' => 'border-gray-300 bg-gray-50 dark:border-gray-700 dark:bg-gray-900',
                        'blocked' => 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/30',
                        default => 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800',
                    };
                    $detailCircleClass = match ($step['state']) {
                        'complete' => 'bg-emerald-600 text-white',
                        'submitted' => 'bg-blue-600 text-white',
                        'in_progress' => 'bg-blue-600 text-white',
                        'current' => 'bg-gray-950 text-white dark:bg-gray-100 dark:text-gray-950',
                        'blocked' => 'bg-red-600 text-white',
                        default => 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
                    };
                @endphp
                <article class="rounded-lg border p-4 {{ $detailTone }}">
                    <div class="flex items-start gap-3">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold {{ $detailCircleClass }}">
                            @if ($step['state'] === 'complete')
                                ✓
                            @elseif ($step['state'] === 'blocked')
                                !
                            @else
                                {{ $step['index'] }}
                            @endif
                        </span>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-sm font-bold text-gray-950 dark:text-gray-100">{{ $step['label'] }}</h3>
                                <span class="rounded-full bg-white/80 px-2 py-0.5 text-[11px] font-semibold text-gray-600 shadow-sm dark:bg-gray-950/50 dark:text-gray-300">
                                    {{ __('suchak.status.step_states.'.$step['state']) }}
                                </span>
                            </div>
                            <p class="mt-1 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $step['body'] }}</p>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    <div class="mt-6 grid gap-5 lg:grid-cols-[1.1fr_0.9fr]">
        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-xl font-bold text-gray-950 dark:text-gray-100">{{ $suchakAccount->suchak_name }}</h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $suchakAccount->office_name ?: $businessTypeLabel }}</p>
                </div>
                @if (! $mobileVerified)
                    <a href="{{ route('suchak.register.verify') }}" class="inline-flex w-fit rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                        {{ __('suchak.status.verify_otp') }}
                    </a>
                @endif
            </div>

            <dl class="mt-6 grid gap-3 text-sm sm:grid-cols-2">
                <div class="rounded-md bg-gray-50 p-4 dark:bg-gray-900">
                    <dt class="font-semibold text-gray-700 dark:text-gray-300">{{ __('suchak.status.summary.whatsapp_otp') }}</dt>
                    <dd class="mt-1 text-gray-950 dark:text-gray-100">{{ $mobileVerified ? __('suchak.labels.common.verified') : __('suchak.labels.common.pending') }}</dd>
                </div>
                <div class="rounded-md bg-gray-50 p-4 dark:bg-gray-900">
                    <dt class="font-semibold text-gray-700 dark:text-gray-300">{{ __('suchak.status.summary.business_type') }}</dt>
                    <dd class="mt-1 text-gray-950 dark:text-gray-100">{{ $businessTypeLabel }}</dd>
                </div>
                <div class="rounded-md bg-gray-50 p-4 dark:bg-gray-900">
                    <dt class="font-semibold text-gray-700 dark:text-gray-300">{{ __('suchak.status.summary.public_status') }}</dt>
                    <dd class="mt-1 text-gray-950 dark:text-gray-100">{{ $publicStatusLabel }}</dd>
                </div>
                <div class="rounded-md bg-gray-50 p-4 dark:bg-gray-900">
                    <dt class="font-semibold text-gray-700 dark:text-gray-300">{{ __('suchak.status.summary.submitted') }}</dt>
                    <dd class="mt-1 text-gray-950 dark:text-gray-100">{{ optional($suchakAccount->created_at)->format('d M Y, h:i A') }}</dd>
                </div>
            </dl>
        </section>

        <aside class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-6">
            <h2 class="text-lg font-bold text-gray-950 dark:text-gray-100">{{ __('suchak.status.next_title') }}</h2>
            <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ __('suchak.status.messages.'.$messageKey.'.next') }}</p>
            <div class="mt-5 rounded-md border border-gray-200 p-4 text-sm leading-6 text-gray-700 dark:border-gray-700 dark:text-gray-300">
                <p class="font-semibold text-gray-950 dark:text-gray-100">{{ __('suchak.status.next_label') }}</p>
                <p class="mt-1">{{ __('suchak.status.messages.'.$messageKey.'.action') }}</p>
            </div>
            @if ($mobileVerified && ! $isBlocked)
                <a href="{{ route('suchak.dashboard', ['dashboard_tab' => 'profile']) }}" class="mt-5 inline-flex w-full items-center justify-center rounded-md bg-gray-950 px-4 py-3 text-sm font-bold text-white shadow-sm hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-950 dark:hover:bg-white sm:w-auto">
                    {{ __('suchak.status.setup_profile_cta') }}
                </a>
            @endif
        </aside>
    </div>

    <section id="kyc-documents" class="mt-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-xl font-bold text-gray-950 dark:text-gray-100">{{ __('suchak.status.kyc_title') }}</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('suchak.status.kyc_intro') }}</p>
            </div>
            <span class="text-sm font-semibold text-gray-500 dark:text-gray-400">{{ trans_choice('suchak.status.uploaded_count', $uploadedDocumentCount, ['count' => $uploadedDocumentCount]) }}</span>
        </div>

        <div class="mt-5 grid gap-3 md:grid-cols-2">
            @foreach ($kycRows as $row)
                @php
                    $documentTone = match ($row['status']) {
                        SuchakVerificationRecord::STATUS_APPROVED => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100',
                        SuchakVerificationRecord::STATUS_REJECTED => 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100',
                        default => 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100',
                    };
                @endphp
                <article class="rounded-md border border-gray-200 p-4 dark:border-gray-700">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-sm font-bold text-gray-950 dark:text-gray-100">{{ $row['label'] }}</h3>
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-600 dark:bg-gray-900 dark:text-gray-300">
                                    {{ $row['required'] ? __('suchak.status.required') : __('suchak.status.optional') }}
                                </span>
                            </div>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                                {{ $row['uploaded'] ? __('suchak.status.document_uploaded') : __('suchak.status.document_not_uploaded') }}
                            </p>
                            <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $row['help'] }}</p>
                        </div>
                        <span class="inline-flex shrink-0 rounded-full border px-2.5 py-1 text-xs font-semibold {{ $documentTone }}">
                            {{ $row['status_label'] }}
                        </span>
                    </div>
                    @if (filled($row['remarks']))
                        <p class="mt-3 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $row['remarks'] }}</p>
                    @endif
                    @if (! $row['uploaded'] || $row['status'] === SuchakVerificationRecord::STATUS_REJECTED)
                        <form method="POST" action="{{ route('suchak.register.documents.store') }}" enctype="multipart/form-data" class="mt-4 space-y-3">
                            @csrf
                            <input type="hidden" name="verification_type" value="{{ $row['type'] }}">
                            <input name="document" type="file" required accept=".pdf,.jpg,.jpeg,.png" class="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-gray-700 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:file:bg-gray-800 dark:file:text-gray-100">
                            <button type="submit" class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                                {{ $row['uploaded'] ? __('suchak.status.replace_document') : __('suchak.status.upload_document') }}
                            </button>
                        </form>
                    @endif
                </article>
            @endforeach
        </div>
    </section>

</div>
@endsection

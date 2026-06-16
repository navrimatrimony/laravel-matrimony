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
    $profilePhotoUploaded = (bool) ($onboarding['profile_photo_uploaded'] ?? false);
    $adminReviewState = (string) ($onboarding['admin_review_state'] ?? 'upcoming');
    $requiredDocumentsUploaded = (bool) ($onboarding['required_documents_uploaded'] ?? false);
    $panelKey = ! $mobileVerified
        ? 'otp'
        : (! $profilePhotoUploaded
            ? 'profile_photo'
            : ((! $requiredDocumentsUploaded || $currentStepKey === 'documents' || $currentStepState === 'blocked') ? 'documents' : 'ready_work'));
    $visibleKycRows = $kycRows->filter(fn (array $row): bool => (bool) $row['required'] || (bool) $row['uploaded'])->values();

    $adminReviewTone = match ($adminReviewState) {
        'complete' => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100',
        'in_progress', 'submitted' => 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-100',
        'blocked' => 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100',
        default => 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100',
    };
    $adminReviewLabel = __('suchak.status.admin_review_badge', [
        'status' => __('suchak.status.step_states.'.$adminReviewState),
    ]);
@endphp

<div class="mx-auto max-w-7xl px-4 py-4">
    <div class="mb-3 flex items-center justify-between gap-3">
        <a href="{{ route('suchak.home') }}" class="text-sm font-semibold text-red-700 hover:underline dark:text-red-300">{{ __('suchak.status.back') }}</a>
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

    <section data-suchak-status-pipeline class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="max-w-2xl">
                <p class="text-xs font-semibold uppercase tracking-wide text-red-700 dark:text-red-300">{{ __('suchak.status.pipeline_label') }}</p>
                <h2 class="mt-1 text-xl font-bold text-gray-950 dark:text-gray-100">{{ __('suchak.status.messages.'.$messageKey.'.title') }}</h2>
                <p class="mt-1 text-sm leading-5 text-gray-600 dark:text-gray-300">{{ __('suchak.status.messages.'.$messageKey.'.body') }}</p>
            </div>
            <span class="inline-flex w-fit rounded-full border px-3 py-1 text-xs font-semibold {{ $adminReviewTone }}">
                {{ $adminReviewLabel }}
            </span>
        </div>

        <div class="mt-5 rounded-lg border border-gray-100 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
            @include('suchak.partials.onboarding-tracker', [
                'steps' => $steps,
                'currentStepKey' => $currentStepKey,
            ])
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,7fr)] lg:items-stretch">
            <aside class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                <p class="mb-4 text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('suchak.status.pipeline_steps_title') }}</p>
                <ol class="space-y-2">
                    @foreach ($steps as $step)
                        @php
                            $rowTone = match ($step['state']) {
                                'complete' => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100',
                                'submitted' => 'border-amber-300 bg-emerald-50 text-emerald-800 dark:border-amber-900 dark:bg-emerald-950/40 dark:text-emerald-100',
                                'in_progress' => 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-100',
                                'current' => 'border-gray-300 bg-white text-gray-950 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100',
                                'blocked' => 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100',
                                default => 'border-gray-200 bg-white text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300',
                            };
                            $dotTone = match ($step['state']) {
                                'complete' => 'bg-emerald-600 text-white',
                                'submitted' => 'border-2 border-amber-400 bg-emerald-600 text-white',
                                'in_progress' => 'bg-blue-600 text-white',
                                'current' => 'bg-gray-950 text-white dark:bg-gray-100 dark:text-gray-950',
                                'blocked' => 'bg-red-600 text-white',
                                default => 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-200',
                            };
                        @endphp
                        <li class="rounded-md border px-3 py-2 {{ $rowTone }}">
                            <div class="flex items-center gap-3">
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold {{ $dotTone }}">
                                    @if (in_array($step['state'], ['complete', 'submitted'], true))
                                        ✓
                                    @elseif ($step['state'] === 'blocked')
                                        !
                                    @else
                                        {{ $step['index'] }}
                                    @endif
                                </span>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-bold">{{ $step['label'] }}</p>
                                    <p class="text-[11px] font-semibold opacity-80">{{ __('suchak.status.step_states.'.$step['state']) }}</p>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </aside>

            <section class="min-h-full rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900" data-suchak-active-step-panel="{{ $panelKey }}">
                @if ($panelKey === 'otp')
                    <p class="text-xs font-bold uppercase tracking-wide text-red-700 dark:text-red-300">{{ __('suchak.status.active_step') }}</p>
                    <h3 class="mt-1 text-xl font-bold text-gray-950 dark:text-gray-100">{{ __('suchak.status.steps.otp.label') }}</h3>
                    <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ __('suchak.status.messages.otp_pending.action') }}</p>

                    <form id="suchak-status-otp-verify-form" method="POST" action="{{ route('suchak.register.verify.submit') }}" class="mt-5 grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
                        @csrf
                        <div>
                            <label for="status_otp" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('suchak.status.otp_input_label') }}</label>
                            <input id="status_otp" name="otp" required maxlength="6" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" class="mt-1 w-full rounded-md border-gray-300 font-mono text-lg tracking-widest dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100" placeholder="000000">
                        </div>
                        <button type="submit" class="rounded-md bg-red-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-red-700">
                            {{ __('suchak.status.verify_otp') }}
                        </button>
                    </form>

                    <form method="POST" action="{{ route('suchak.register.otp.resend') }}" class="mt-3">
                        @csrf
                        <button type="submit" class="text-sm font-semibold text-red-700 underline underline-offset-4 hover:text-red-900 dark:text-red-300 dark:hover:text-red-200">
                            {{ __('suchak.status.send_new_otp') }}
                        </button>
                    </form>
                @elseif ($panelKey === 'profile_photo')
                    <p class="text-xs font-bold uppercase tracking-wide text-red-700 dark:text-red-300">{{ __('suchak.status.active_step') }}</p>
                    <h3 class="mt-1 text-xl font-bold text-gray-950 dark:text-gray-100">{{ __('suchak.status.steps.profile_photo.label') }}</h3>
                    <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ __('suchak.status.photo_upload_intro') }}</p>

                    <div id="suchak-photo-upload" class="mt-5 rounded-lg border border-dashed border-gray-300 bg-gray-50 p-5 dark:border-gray-700 dark:bg-gray-950">
                        @if (! empty($onboarding['profile_photo_rejected']))
                            <div class="mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">
                                <p class="font-semibold">{{ __('suchak.status.photo_rejected') }}</p>
                                @if (! empty($onboarding['profile_photo_review_remarks']))
                                    <p class="mt-1">{{ $onboarding['profile_photo_review_remarks'] }}</p>
                                @endif
                            </div>
                        @endif
                        <p class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ __('suchak.status.photo_upload_box_label') }}</p>
                        <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ __('suchak.status.photo_upload_help') }}</p>
                        <a href="{{ route('suchak.register.photo') }}" class="mt-4 inline-flex rounded-md bg-red-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-red-700">
                            {{ __('suchak.status.upload_photo') }}
                        </a>
                    </div>
                @elseif ($panelKey === 'documents')
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wide text-red-700 dark:text-red-300">{{ __('suchak.status.active_step') }}</p>
                            <h3 class="mt-1 text-xl font-bold text-gray-950 dark:text-gray-100">{{ __('suchak.status.kyc_title') }}</h3>
                            <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ __('suchak.status.kyc_intro_short') }}</p>
                            <ul class="mt-3 space-y-1 text-sm text-gray-600 dark:text-gray-300">
                                <li>• {{ __('suchak.status.required_identity_proof') }}</li>
                                <li>• {{ __('suchak.status.required_office_proof') }}</li>
                            </ul>
                        </div>
                        <span class="inline-flex w-fit rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                            {{ trans_choice('suchak.status.uploaded_count', $uploadedDocumentCount, ['count' => $uploadedDocumentCount]) }}
                        </span>
                    </div>

                    <div id="kyc-documents" class="mt-5 grid gap-3">
                        @foreach ($visibleKycRows as $row)
                            @php
                                $documentTone = match ($row['status']) {
                                    SuchakVerificationRecord::STATUS_APPROVED => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100',
                                    SuchakVerificationRecord::STATUS_REJECTED => 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100',
                                    default => 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100',
                                };
                                $needsUpload = ! $row['uploaded'] || $row['status'] === SuchakVerificationRecord::STATUS_REJECTED;
                            @endphp
                            <article class="rounded-md border border-gray-200 p-4 dark:border-gray-700">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h4 class="text-sm font-bold text-gray-950 dark:text-gray-100">{{ $row['label'] }}</h4>
                                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                                {{ $row['required'] ? __('suchak.status.required') : __('suchak.status.optional') }}
                                            </span>
                                        </div>
                                        <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $row['help'] }}</p>
                                        @if (filled($row['remarks']))
                                            <p class="mt-2 text-sm leading-6 text-red-700 dark:text-red-300">{{ $row['remarks'] }}</p>
                                        @endif
                                    </div>
                                    <span class="inline-flex w-fit shrink-0 rounded-full border px-2.5 py-1 text-xs font-semibold {{ $documentTone }}">
                                        {{ $row['status_label'] }}
                                    </span>
                                </div>

                                @if ($needsUpload)
                                    <form method="POST" action="{{ route('suchak.register.documents.store') }}" enctype="multipart/form-data" class="mt-4 grid gap-3 sm:grid-cols-[1fr_auto] sm:items-center">
                                        @csrf
                                        <input type="hidden" name="verification_type" value="{{ $row['type'] }}">
                                        <input name="document" type="file" required accept=".pdf,.jpg,.jpeg,.png" class="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-gray-700 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100 dark:file:bg-gray-800 dark:file:text-gray-100">
                                        <button type="submit" class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                                            {{ $row['uploaded'] ? __('suchak.status.replace_document') : __('suchak.status.upload_document') }}
                                        </button>
                                    </form>
                                @else
                                    <p class="mt-3 text-sm font-semibold text-emerald-700 dark:text-emerald-300">{{ __('suchak.status.document_uploaded') }}</p>
                                @endif
                            </article>
                        @endforeach
                    </div>
                @else
                    <p class="text-xs font-bold uppercase tracking-wide text-red-700 dark:text-red-300">{{ __('suchak.status.active_step') }}</p>
                    <h3 class="mt-1 text-xl font-bold text-gray-950 dark:text-gray-100">{{ __('suchak.status.ready_work_title') }}</h3>
                    <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 p-5 text-sm leading-6 text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100">
                        <p>{{ __('suchak.status.ready_work_body') }}</p>
                        <p class="mt-3">{{ __('suchak.status.ready_work_next') }}</p>
                    </div>

                    <div class="mt-5 flex flex-col gap-3 sm:flex-row">
                        <a href="{{ route('suchak.manual-profiles.create') }}" class="inline-flex items-center justify-center rounded-md bg-red-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-red-700">
                            {{ __('suchak.status.add_customer_profile') }}
                        </a>
                        <a href="{{ route('suchak.dashboard', ['dashboard_tab' => 'profiles']) }}" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-5 py-2.5 text-sm font-semibold text-gray-900 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:hover:bg-gray-800">
                            {{ __('suchak.status.open_suchak_dashboard') }}
                        </a>
                    </div>
                @endif
            </section>
        </div>
    </section>

</div>
@endsection

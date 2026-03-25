@extends('layouts.app')

@section('content')
<div class="max-w-lg mx-auto py-10 px-4 sm:px-6">
    @if (session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-100">{{ session('success') }}</div>
    @endif
    @if (session('info'))
        <div class="mb-4 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-100">{{ session('info') }}</div>
    @endif
    <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100 mb-2">{{ __('profile.verification_kyc_title') }}</h1>
    <p class="text-sm text-gray-600 dark:text-gray-400 mb-6">{{ __('profile.verification_kyc_intro') }}</p>

    @if ($kycApproved)
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-100">
            {{ __('profile.verification_kyc_done') }}
        </div>
    @else
        @if ($latestKyc && $latestKyc->status === \App\Models\ProfileKycSubmission::STATUS_PENDING)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100 mb-6">
                {{ __('profile.kyc_review_pending') }}
            </div>
        @elseif ($latestKyc && $latestKyc->status === \App\Models\ProfileKycSubmission::STATUS_REJECTED)
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-800 dark:bg-red-950/40 dark:text-red-100 mb-4">
                <p class="font-medium">{{ __('profile.kyc_rejected_notice') }}</p>
                @if (! empty($latestKyc->admin_note))
                    <p class="mt-2 text-xs opacity-90">{{ $latestKyc->admin_note }}</p>
                @endif
            </div>
        @endif

        @if (! $hasPendingSubmission)
            <form method="POST" action="{{ route('matrimony.profile.verification.kyc.store', $profile->id) }}" enctype="multipart/form-data" class="space-y-4 rounded-lg border border-stone-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                @csrf
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('profile.kyc_upload_hint') }}</p>
                <input type="file" name="id_document" accept="image/jpeg,image/png,image/webp,application/pdf" required class="block w-full text-sm text-gray-600 file:mr-3 file:rounded file:border-0 file:bg-stone-100 file:px-3 file:py-2 dark:file:bg-gray-700 dark:text-gray-300" />
                @error('id_document')
                    <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
                <button type="submit" class="inline-flex w-full sm:w-auto justify-center rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">
                    {{ __('profile.kyc_upload_submit') }}
                </button>
            </form>
        @endif
    @endif

    <div class="mt-8">
        <a href="{{ route('matrimony.profile.show', $profile->id) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
            {{ __('profile.verification_back_profile') }}
        </a>
    </div>
</div>
@endsection

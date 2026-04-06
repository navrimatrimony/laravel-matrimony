@extends('layouts.app')

@section('content')
@php
    $featureUsage = app(\App\Services\FeatureUsageService::class);
    $userId = auth()->id();
    $cu = $contactUsageSnapshot ?? ['used' => 0, 'limit' => 0, 'remaining' => 0, 'is_unlimited' => false];
    $contactUsed = $cu['used'];
    $isUnlimited = (bool) ($cu['is_unlimited'] ?? false);
    $contactLimit = $cu['limit'];
    $contactRemaining = $cu['remaining'];
@endphp
<div class="max-w-6xl mx-auto py-8 px-4 sm:px-6" x-data="{ adminEditMode: @js(auth()->check() && auth()->user()->is_admin === true && request()->has('admin_edit')), openRequestModal: false, showContactUpgradeModal: false }">
    @if ($userId !== null && ! $featureUsage->canUse((int) $userId, 'who_viewed_me_access') && ($isOwnProfile ?? false))
        @php
            $whoViewedTeaserN = \App\Services\ViewTrackingService::countEligibleDistinctViewersForTeaser((int) $profile->id);
            $whoViewedHeadlineN = $whoViewedTeaserN > 0 ? $whoViewedTeaserN : 5;
        @endphp
        <div class="mb-4 rounded-lg bg-gradient-to-r from-pink-500 to-rose-600 p-5 text-center text-white shadow-lg ring-1 ring-white/10">
            <h3 class="text-lg font-semibold">
                👀 {{ $whoViewedHeadlineN }} {{ $whoViewedHeadlineN === 1 ? 'person' : 'people' }} viewed your profile
            </h3>

            <div class="mt-3 flex justify-center gap-2">
                <div class="h-10 w-10 rounded-full bg-white/30 blur-sm" aria-hidden="true"></div>
                <div class="h-10 w-10 rounded-full bg-white/30 blur-sm" aria-hidden="true"></div>
                <div class="h-10 w-10 rounded-full bg-white/30 blur-sm" aria-hidden="true"></div>
            </div>

            <p class="mt-3 text-sm opacity-90">
                Unlock to see who is interested in you
            </p>

            <a href="{{ route('plans.index') }}"
               class="mt-4 inline-block rounded-full bg-white px-5 py-2 font-semibold text-pink-600 shadow">
                Upgrade Now
            </a>
        </div>
    @endif
    <h1 class="text-lg font-semibold tracking-tight text-gray-900 dark:text-gray-100 mb-4 lg:mb-5 lg:text-xl xl:text-2xl">Matrimony Profile</h1>
    @if (($isOwnProfile ?? false) && auth()->check() && auth()->user()->is_admin !== true)
        <div class="mb-6">
            <a href="{{ route('matrimony.profile.edit') }}"
               class="inline-flex items-center px-5 py-2.5 rounded-md bg-red-600 text-white hover:bg-red-700 transition font-medium text-sm">
                {{ __('Edit Profile') }}
            </a>
        </div>
    @endif

    @if (session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-100">{{ session('success') }}</div>
    @endif
    @if (session('info'))
        <div class="mb-4 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-100">{{ session('info') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-800 dark:bg-red-950/40 dark:text-red-100">{{ session('error') }}</div>
    @endif

    @if (($isOwnProfile ?? false) && auth()->check() && $userId !== null && $featureUsage->canUse((int) $userId, 'who_viewed_me_access'))
        <div class="mb-4 rounded-lg border border-stone-200/90 bg-white p-4 text-center shadow-sm dark:border-gray-700 dark:bg-gray-800/60">
            <a href="{{ route('who-viewed.index') }}"
               class="inline-flex items-center justify-center gap-2 text-sm font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300">
                {{ __('nav.who_viewed_me') }}
                <span aria-hidden="true">→</span>
            </a>
        </div>
    @endif

@if (($profile->lifecycle_state ?? null) === 'conflict_pending' && ($hasBlockingConflicts ?? false))
    <div class="mb-4 rounded-lg border-2 border-red-600 dark:border-red-500 overflow-hidden">
        <div class="px-4 py-3 bg-red-100 dark:bg-red-900/50 text-red-900 dark:text-red-100 font-semibold">
            ⚠️ Your profile has unresolved conflicts. Below is what changed (current vs proposed). Admin will resolve; you can contact support if needed.
        </div>
        @if (($conflictRecords ?? collect())->isNotEmpty())
            <div class="px-4 py-4 bg-white dark:bg-gray-800 border-t border-red-200 dark:border-red-800 space-y-4">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300">What changed</h2>
                @foreach($conflictRecords as $record)
                    <div class="rounded-lg border border-gray-200 dark:border-gray-600 overflow-hidden">
                        <p class="px-3 py-2 text-xs font-medium text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-700/50 border-b border-gray-200 dark:border-gray-600">
                            {{ str_replace('_', ' ', ucfirst($record->field_name)) }}
                            @if(!empty($record->field_type)) <span class="text-gray-400">({{ $record->field_type }})</span> @endif
                        </p>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-0">
                            <div class="p-3 border-r border-gray-200 dark:border-gray-600 bg-red-50 dark:bg-red-900/20">
                                <p class="text-xs font-medium text-red-700 dark:text-red-300 uppercase mb-1">Current value</p>
                                <p class="text-sm text-gray-900 dark:text-gray-100 break-words whitespace-pre-wrap">{{ $record->old_value ?? '—' }}</p>
                            </div>
                            <div class="p-3 bg-emerald-50 dark:bg-emerald-900/20">
                                <p class="text-xs font-medium text-emerald-700 dark:text-emerald-300 uppercase mb-1">Proposed value</p>
                                <p class="text-sm text-gray-900 dark:text-gray-100 break-words whitespace-pre-wrap">{{ $record->new_value ?? '—' }}</p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endif

@if ($isOwnProfile && $profile->is_suspended)
    <div style="margin-bottom:1.5rem; padding:1.25rem; background:#fef3c7; border:2px solid #fbbf24; border-radius:8px; color:#92400e;">
        <p style="font-weight:700; margin:0; font-size:1.1rem;">⚠️ {{ __('admin.suspended') }}</p>
    </div>
@endif

{{-- Admin-only moderation actions --}}
@if (auth()->check() && auth()->user()->is_admin === true)
    <div x-data="{ activeAction: null }" class="mb-6 p-6 rounded-lg border-2 border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h3 class="text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">{{ __('admin.moderation') }}</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('admin.moderation_help') }}</p>
            </div>
            <button 
                type="button"
                @click="$parent.adminEditMode = !$parent.adminEditMode"
                class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md font-medium text-sm transition-colors">
                <span x-text="$parent.adminEditMode ? @js(__('admin.cancel_edit')) : @js(__('admin.edit_profile_admin'))"></span>
            </button>
        </div>
        <div class="flex flex-wrap gap-2 mb-4">
            <button 
                type="button"
                @click="activeAction = activeAction === 'suspend' ? null : 'suspend'"
                style="padding:8px 16px; background:#f59e0b; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:500;">
                {{ __('admin.suspend') }}
            </button>

            <button 
                type="button"
                @click="activeAction = activeAction === 'unsuspend' ? null : 'unsuspend'"
                style="padding:8px 16px; background:#10b981; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:500;">
                {{ __('admin.unsuspend') }}
            </button>

            <button 
                type="button"
                @click="activeAction = activeAction === 'soft-delete' ? null : 'soft-delete'"
                style="padding:8px 16px; background:#ef4444; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:500;">
                {{ __('admin.soft_delete') }}
            </button>

            @if ($profile->profile_photo)
            <button 
                type="button"
                @click="activeAction = activeAction === 'approve-image' ? null : 'approve-image'"
                style="padding:8px 16px; background:#3b82f6; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:500;">
                {{ __('admin.approve_image') }}
            </button>

            <button 
                type="button"
                @click="activeAction = activeAction === 'reject-image' ? null : 'reject-image'"
                style="padding:8px 16px; background:#dc2626; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:500;">
                {{ __('admin.reject_image') }}
            </button>
            @endif

            <button 
                type="button"
                @click="activeAction = activeAction === 'override-visibility' ? null : 'override-visibility'"
                style="padding:8px 16px; background:#8b5cf6; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:500;">
                {{ __('admin.override_visibility') }}
            </button>
        </div>

        {{-- Suspend Form --}}
        <div x-show="activeAction === 'suspend'" x-transition style="border:1px solid #ccc; padding:1rem; border-radius:4px; margin-bottom:1rem; background:#fff;">
            <form method="POST" action="{{ route('admin.profiles.suspend', $profile) }}">
                @csrf
                <p style="font-weight:600; margin-bottom:8px;">{{ __('admin.suspend_profile') }}</p>
                <textarea name="reason" rows="3" required minlength="10" placeholder="{{ __('admin.reason_min_10') }}" style="width:100%; margin-bottom:8px; padding:8px; border:1px solid #ddd; border-radius:4px;"></textarea>
                <div style="display:flex; gap:8px;">
                    <button type="submit" style="padding:8px 16px; background:#f59e0b; color:white; border:none; border-radius:4px; cursor:pointer;">{{ __('common.submit') }}</button>
                    <button type="button" @click="activeAction = null" style="padding:8px 16px; background:#6b7280; color:white; border:none; border-radius:4px; cursor:pointer;">{{ __('common.cancel') }}</button>
                </div>
            </form>
        </div>

        {{-- Unsuspend Form --}}
        <div x-show="activeAction === 'unsuspend'" x-transition style="border:1px solid #ccc; padding:1rem; border-radius:4px; margin-bottom:1rem; background:#fff;">
            <form method="POST" action="{{ route('admin.profiles.unsuspend', $profile) }}">
                @csrf
                <p style="font-weight:600; margin-bottom:8px;">{{ __('admin.unsuspend_profile') }}</p>
                <textarea name="reason" rows="3" required minlength="10" placeholder="{{ __('admin.reason_min_10') }}" style="width:100%; margin-bottom:8px; padding:8px; border:1px solid #ddd; border-radius:4px;"></textarea>
                <div style="display:flex; gap:8px;">
                    <button type="submit" style="padding:8px 16px; background:#10b981; color:white; border:none; border-radius:4px; cursor:pointer;">{{ __('common.submit') }}</button>
                    <button type="button" @click="activeAction = null" style="padding:8px 16px; background:#6b7280; color:white; border:none; border-radius:4px; cursor:pointer;">{{ __('common.cancel') }}</button>
                </div>
            </form>
        </div>

        {{-- Soft Delete Form --}}
        <div x-show="activeAction === 'soft-delete'" x-transition style="border:1px solid #ccc; padding:1rem; border-radius:4px; margin-bottom:1rem; background:#fff;">
            <form method="POST" action="{{ route('admin.profiles.soft-delete', $profile) }}">
                @csrf
                <p style="font-weight:600; margin-bottom:8px;">{{ __('admin.soft_delete_profile') }}</p>
                <textarea name="reason" rows="3" required minlength="10" placeholder="{{ __('admin.reason_min_10') }}" style="width:100%; margin-bottom:8px; padding:8px; border:1px solid #ddd; border-radius:4px;"></textarea>
                <div style="display:flex; gap:8px;">
                    <button type="submit" style="padding:8px 16px; background:#ef4444; color:white; border:none; border-radius:4px; cursor:pointer;">{{ __('common.submit') }}</button>
                    <button type="button" @click="activeAction = null" style="padding:8px 16px; background:#6b7280; color:white; border:none; border-radius:4px; cursor:pointer;">{{ __('common.cancel') }}</button>
                </div>
            </form>
        </div>

        @if ($profile->profile_photo)
        {{-- Approve Image Form --}}
        <div x-show="activeAction === 'approve-image'" x-transition style="border:1px solid #ccc; padding:1rem; border-radius:4px; margin-bottom:1rem; background:#fff;">
            <form method="POST" action="{{ route('admin.profiles.approve-image', $profile) }}">
                @csrf
                <p style="font-weight:600; margin-bottom:8px;">{{ __('admin.approve_image') }}</p>
                <textarea name="reason" rows="3" required minlength="10" placeholder="{{ __('admin.reason_min_10') }}" style="width:100%; margin-bottom:8px; padding:8px; border:1px solid #ddd; border-radius:4px;"></textarea>
                <div style="display:flex; gap:8px;">
                    <button type="submit" style="padding:8px 16px; background:#3b82f6; color:white; border:none; border-radius:4px; cursor:pointer;">{{ __('common.submit') }}</button>
                    <button type="button" @click="activeAction = null" style="padding:8px 16px; background:#6b7280; color:white; border:none; border-radius:4px; cursor:pointer;">{{ __('common.cancel') }}</button>
                </div>
            </form>
        </div>

        {{-- Reject Image Form --}}
        <div x-show="activeAction === 'reject-image'" x-transition style="border:1px solid #ccc; padding:1rem; border-radius:4px; margin-bottom:1rem; background:#fff;">
            <form method="POST" action="{{ route('admin.profiles.reject-image', $profile) }}">
                @csrf
                <p style="font-weight:600; margin-bottom:8px;">{{ __('admin.reject_image') }}</p>
                <textarea name="reason" rows="3" required minlength="10" placeholder="{{ __('admin.reason_min_10') }}" style="width:100%; margin-bottom:8px; padding:8px; border:1px solid #ddd; border-radius:4px;"></textarea>
                <div style="display:flex; gap:8px;">
                    <button type="submit" style="padding:8px 16px; background:#dc2626; color:white; border:none; border-radius:4px; cursor:pointer;">{{ __('common.submit') }}</button>
                    <button type="button" @click="activeAction = null" style="padding:8px 16px; background:#6b7280; color:white; border:none; border-radius:4px; cursor:pointer;">{{ __('common.cancel') }}</button>
                </div>
            </form>
        </div>
        @endif

        {{-- Override Visibility Form --}}
        <div x-show="activeAction === 'override-visibility'" x-transition style="border:1px solid #ccc; padding:1rem; border-radius:4px; margin-bottom:1rem; background:#fff;">
            <form method="POST" action="{{ route('admin.profiles.override-visibility', $profile) }}">
                @csrf
                <p style="font-weight:600; margin-bottom:8px;">{{ __('admin.override_visibility_help') }}</p>
                <textarea name="reason" rows="3" required minlength="10" placeholder="{{ __('admin.reason_min_10') }}" style="width:100%; margin-bottom:8px; padding:8px; border:1px solid #ddd; border-radius:4px;"></textarea>
                <div style="display:flex; gap:8px;">
                    <button type="submit" style="padding:8px 16px; background:#8b5cf6; color:white; border:none; border-radius:4px; cursor:pointer;">{{ __('common.submit') }}</button>
                    <button type="button" @click="activeAction = null" style="padding:8px 16px; background:#6b7280; color:white; border:none; border-radius:4px; cursor:pointer;">{{ __('common.cancel') }}</button>
                </div>
            </form>
        </div>
    </div>
@endif

    
{{-- Profile body: no single narrow card wrapper; lg = fixed rail + flex-1 main --}}
<div class="space-y-8">

{{-- Admin Edit Form (visible when adminEditMode is true) --}}
@if (auth()->check() && auth()->user()->is_admin === true)
<div x-show="adminEditMode" x-transition class="mb-6 p-6 bg-yellow-50 dark:bg-yellow-900/20 border-2 border-yellow-300 dark:border-yellow-700 rounded-lg">
    <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-4">Admin Profile Edit Mode</h3>
    <form method="POST" action="{{ route('admin.profiles.update', $profile) }}" id="admin-profile-edit-form">
        @csrf
        @method('PUT')
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Full Name</label>
                <input type="text" name="full_name" value="{{ old('full_name', $profile->full_name) }}" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
            </div>
            
            @if ($dateOfBirthVisible)
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('Date of Birth') }}</label>
                <input type="date" name="date_of_birth" value="{{ old('date_of_birth', $profile->date_of_birth) }}" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
            </div>
            @endif
            
            @if ($maritalStatusVisible)
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('Marital Status') }}</label>
                <select name="marital_status" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                    <option value="">—</option>
                    <option value="single" {{ old('marital_status', $profile->marital_status) === 'single' ? 'selected' : '' }}>Single</option>
                    <option value="divorced" {{ old('marital_status', $profile->marital_status) === 'divorced' ? 'selected' : '' }}>Divorced</option>
                    <option value="widowed" {{ old('marital_status', $profile->marital_status) === 'widowed' ? 'selected' : '' }}>Widowed</option>
                </select>
            </div>
            @endif
            
            @if ($educationVisible)
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('Education') }}</label>
                <input type="text" name="highest_education" value="{{ old('highest_education', $profile->highest_education) }}" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
            </div>
            @endif
            
            @if ($locationVisible)
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('Location') }}</label>
                <p class="font-medium text-base text-gray-900 dark:text-gray-100">{{ implode(', ', array_filter([$profile->city?->name, $profile->taluka?->name, $profile->district?->name, $profile->state?->name, $profile->country?->name])) ?: '—' }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Edit location via profile hierarchy (country/state/city) in full edit.</p>
            </div>
            @endif
            <div class="md:col-span-2">
                <x-physical-engine :profile="$profile" />
            </div>
        </div>
        
        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                Edit Reason <span class="text-red-600">*</span>
            </label>
            <textarea name="edit_reason" rows="3" required minlength="10" placeholder="Explain why you are editing this profile (minimum 10 characters)" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">{{ old('edit_reason') }}</textarea>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">This reason will be logged in the audit log.</p>
        </div>
        
        <div class="flex gap-3">
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md font-medium">Save Changes</button>
            <button type="button" @click="$root.adminEditMode = false" class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-md font-medium">Cancel</button>
        </div>
    </form>
</div>
@endif

{{-- Desktop top fold: one bordered panel (photo rail | summary). Below: full-width detail stack. --}}
<div class="flex w-full flex-col gap-8">
    <div class="flex w-full flex-col gap-6 lg:flex-row lg:items-stretch lg:gap-0 lg:overflow-hidden lg:rounded-2xl lg:border lg:border-stone-200/65 lg:bg-white lg:shadow-[0_4px_28px_-8px_rgba(28,25,23,0.1)] lg:ring-1 lg:ring-stone-200/45 dark:lg:border-gray-700/75 dark:lg:bg-gray-900/95 dark:lg:shadow-[0_4px_32px_-10px_rgba(0,0,0,0.42)] dark:lg:ring-gray-700/55">
    <aside class="w-full space-y-6 lg:sticky lg:top-24 lg:w-[318px] lg:max-w-[330px] lg:shrink-0 lg:space-y-4 lg:border-b lg:border-stone-200/60 lg:bg-gradient-to-b lg:from-stone-50/50 lg:to-stone-50/20 lg:p-4 dark:lg:border-gray-700/70 dark:lg:from-gray-900/50 dark:lg:to-gray-900/30 xl:w-[336px]">
        @php
            $galleryPhotos = $galleryPhotos ?? collect();
            $photoAlbumPresentation = $photoAlbumPresentation ?? ['slots' => [], 'message_key' => null, 'tier' => 'own_profile'];
        @endphp
		
		
		
        <div class="group lg:overflow-hidden lg:rounded-xl lg:ring-1 lg:ring-stone-200/55 lg:transition-[box-shadow,ring-color] lg:duration-300 lg:ease-out dark:lg:ring-gray-600/55 lg:hover:shadow-[0_10px_36px_-14px_rgba(28,25,23,0.14)] lg:hover:ring-stone-300/65 dark:lg:hover:ring-gray-500/60">
        <x-profile.show.hero-card
            class="w-full !shadow-none !ring-0 lg:!rounded-xl"
            :profile="$profile"
            :profilePhotoVisible="$profilePhotoVisible"
            :photoAlbumPresentation="$photoAlbumPresentation"
            :galleryPhotos="$galleryPhotos"
            :photoLocked="$photoLocked"
            :photoLockMode="$photoLockMode ?? 'all'"
            :interestAlreadySent="$interestAlreadySent"
            :contactRequestDisabled="$contactRequestDisabled"
            :contactRequestState="$contactRequestState"
            :dateOfBirthVisible="$dateOfBirthVisible"
            :heightVisible="$heightVisible"
            :locationVisible="$locationVisible"
            :educationVisible="$educationVisible"
        />
        </div>
        @if (! empty($photoAlbumPresentation['message_key'] ?? null))
            <p class="mt-3 text-sm text-stone-700 dark:text-stone-200 bg-stone-100/90 dark:bg-gray-800/90 px-3 py-2 rounded-lg border border-stone-200/80 dark:border-gray-600" role="status">
                {{ __($photoAlbumPresentation['message_key']) }}
            </p>
        @endif
        @if ($profilePhotoVisible && $isOwnProfile && $profile->profile_photo && $profile->photo_approved === false && empty($profile->photo_rejected_at))
            <p class="mt-3 text-sm text-amber-800 dark:text-amber-200 bg-amber-50 dark:bg-amber-900/30 px-3 py-2 rounded-lg border border-amber-200/80 dark:border-amber-800">{{ __('dashboard.photo_under_review') }}</p>
        @endif

        <x-profile.show.verified-sidebar :panel="$verificationPanel ?? ['verified' => [], 'unverified' => []]" />
        {{-- Similar profiles: render when $similarProfiles (or equivalent) is passed from the controller --}}
    </aside>

    <div class="flex min-w-0 w-full lg:w-auto flex-1 flex-col lg:min-w-0 lg:border-l lg:border-stone-200/55 lg:bg-white/40 dark:lg:border-gray-700/65 dark:lg:bg-transparent">
@php
    $incomeService = app(\App\Services\IncomeEngineService::class);
    $profileArr = $profile->toArray();
    $personalIncomeDisplay = $incomeService->formatForDisplay($profileArr, 'income', $profile->incomeCurrency);
    $familyIncomeDisplay = $incomeService->formatForDisplay($profileArr, 'family_income', $profile->familyIncomeCurrency ?? $profile->incomeCurrency);
    $hasPersonalIncome = ($profile->income_value_type ?? null) !== null || ($profile->income_amount ?? null) !== null || ($profile->income_min_amount ?? null) !== null || ($profile->annual_income ?? null) !== null;
    $hasFamilyIncome = ($profile->family_income_value_type ?? null) !== null || ($profile->family_income_amount ?? null) !== null || ($profile->family_income_min_amount ?? null) !== null || ($profile->family_income ?? null) !== null;
    $hasEduCareer = ($educationVisible && ($profile->highest_education ?? '') !== '') || ($profile->highest_education_other ?? '') !== '' || ($profile->specialization ?? '') !== '' || ($profile->occupation_title ?? '') !== '' || ($profile->company_name ?? '') !== '' || $hasPersonalIncome || $hasFamilyIncome || ($profile->annual_income ?? null) !== null || ($profile->family_income ?? null) !== null || $profile->incomeCurrency || ($profile->profession && ($profile->profession->name ?? '') !== '') || ($profile->workingWithType && ($profile->workingWithType->name ?? '') !== '');
@endphp
        @php
            $__nav = $profileNavigation ?? ['prev' => null, 'next' => null];
            $__prev = $__nav['prev'] ?? null;
            $__next = $__nav['next'] ?? null;
        @endphp
        {{-- Top fold (right column): utility strip + overview + integrated actions (single composition) --}}
        @if ($__prev || $__next)
        <div class="mb-4 flex items-center justify-between gap-3 border-b border-stone-200/65 pb-3 dark:border-gray-700/75">
            <span class="text-[10px] font-semibold uppercase tracking-[0.22em] text-stone-400 dark:text-stone-500">{{ __('profile.show_nav_browse_hint') }}</span>
            <div class="flex items-center gap-2">
                @if ($__prev)
                    <a href="{{ route('matrimony.profile.show', $__prev['id']) }}" class="group/pill inline-flex items-center gap-2 rounded-full border border-stone-200/85 bg-white/90 py-1 pl-1 pr-2.5 text-xs font-medium text-stone-700 shadow-sm ring-1 ring-stone-100/60 transition duration-200 ease-out hover:border-stone-300/90 hover:bg-white hover:shadow-md hover:shadow-stone-900/5 active:scale-[0.99] dark:border-gray-600 dark:bg-gray-800/90 dark:text-stone-200 dark:ring-gray-700/50 dark:hover:border-gray-500" title="{{ __('profile.show_nav_prev') }}">
                        <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-stone-50 text-stone-500 shadow-sm ring-1 ring-stone-200/80 transition group-hover/pill:text-rose-600 dark:bg-gray-700 dark:ring-gray-600 dark:group-hover/pill:text-rose-300">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        </span>
                        <img src="{{ $__prev['photo_url'] }}" alt="" class="h-8 w-8 rounded-full object-cover ring-2 ring-white dark:ring-gray-700" width="32" height="32" />
                        <span class="hidden sm:inline">{{ __('profile.show_nav_prev_short') }}</span>
                    </a>
                @endif
                @if ($__next)
                    <a href="{{ route('matrimony.profile.show', $__next['id']) }}" class="group/pill inline-flex items-center gap-2 rounded-full border border-stone-200/85 bg-white/90 py-1 pl-2.5 pr-1 text-xs font-medium text-stone-700 shadow-sm ring-1 ring-stone-100/60 transition duration-200 ease-out hover:border-stone-300/90 hover:bg-white hover:shadow-md hover:shadow-stone-900/5 active:scale-[0.99] dark:border-gray-600 dark:bg-gray-800/90 dark:text-stone-200 dark:ring-gray-700/50 dark:hover:border-gray-500" title="{{ __('profile.show_nav_next') }}">
                        <span class="hidden sm:inline">{{ __('profile.show_nav_next_short') }}</span>
                        <img src="{{ $__next['photo_url'] }}" alt="" class="h-8 w-8 rounded-full object-cover ring-2 ring-white dark:ring-gray-700" width="32" height="32" />
                        <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-stone-50 text-stone-500 shadow-sm ring-1 ring-stone-200/80 transition group-hover/pill:text-rose-600 dark:bg-gray-700 dark:ring-gray-600 dark:group-hover/pill:text-rose-300">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </span>
                    </a>
                @endif
            </div>
        </div>
        @endif

        <div class="lg:flex lg:flex-row lg:items-stretch">
        <div class="min-w-0 flex-1 flex flex-col px-5 lg:px-5 lg:pb-2 lg:pl-6 lg:pr-5 xl:px-6 xl:pl-8">
        <div class="space-y-4">

        @if (($isOwnProfile ?? false))
        @php
            $detailedPct = (int) round((float) ($completenessDetailedPct ?? 0));
            $detailedPct = max(0, min(100, $detailedPct));
        @endphp
        {{-- Profile completeness: use literal Tailwind classes so JIT includes red/orange/green (dynamic vars get purged) --}}
        <div>
            <div class="flex justify-between items-center mb-1">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('profile.profile_completeness') }}</span>
                @if ($detailedPct < 50)
                    <span class="text-sm font-bold tabular-nums text-red-600 dark:text-red-400">{{ $detailedPct }}%</span>
                @elseif ($detailedPct <= 75)
                    <span class="text-sm font-bold tabular-nums text-orange-600 dark:text-orange-400">{{ $detailedPct }}%</span>
                @else
                    <span class="text-sm font-bold tabular-nums text-green-600 dark:text-green-400">{{ $detailedPct }}%</span>
                @endif
            </div>
            <div class="w-full rounded-full bg-gray-200 h-2.5 dark:bg-gray-600">
                @if ($detailedPct < 50)
                    <div class="h-2.5 rounded-full bg-red-600 transition-all duration-300 dark:bg-red-500" style="width: {{ $detailedPct }}%;"></div>
                @elseif ($detailedPct <= 75)
                    <div class="h-2.5 rounded-full bg-orange-500 transition-all duration-300 dark:bg-orange-400" style="width: {{ $detailedPct }}%;"></div>
                @else
                    <div class="h-2.5 rounded-full bg-green-600 transition-all duration-300 dark:bg-green-500" style="width: {{ $detailedPct }}%;"></div>
                @endif
            </div>
        </div>
        @endif

@if ($isOwnProfile && $profile->admin_edited_fields && in_array('full_name', $profile->admin_edited_fields ?? []))
    <p class="mb-3 text-xs text-amber-600 dark:text-amber-400" title="This field was corrected by admin">(Admin corrected)</p>
@endif

@if ($isOwnProfile && $profile->photo_rejection_reason)
    <div style="margin-bottom:1.5rem; padding:1rem; background:#fee2e2; border:1px solid #fca5a5; border-radius:8px; color:#991b1b;">
        <p style="font-weight:600; margin-bottom:0.5rem;">Your profile photo was removed by admin.</p>
        <p style="margin:0;"><strong>Reason:</strong> {{ $profile->photo_rejection_reason }}</p>
    </div>
@endif

        {{-- Scan-first summary + actions integrated in one panel (no detached CTA card) --}}
        <div class="border-b border-stone-200/85 pb-4 dark:border-gray-700 lg:border-0 lg:pb-0">
@php
    $profile->loadMissing(['profession', 'workingWithType']);
    $overviewAge = null;
    if ($dateOfBirthVisible && ($profile->date_of_birth ?? '') !== '') {
        try {
            $overviewAge = \Carbon\Carbon::parse($profile->date_of_birth)->age;
        } catch (\Throwable) {
            $overviewAge = null;
        }
    }
    $locationParts = array_filter([
        $profile->city?->name,
        $profile->taluka?->name,
        $profile->district?->name,
        $profile->state?->name,
        $profile->country?->name,
    ]);
    $locationLine = implode(', ', $locationParts);
    $overviewEduLine = null;
    if ($educationVisible && ($profile->highest_education ?? '') !== '') {
        $overviewEduLine = \App\Support\ProfileDisplayCopy::formatEducationPhrase($profile->highest_education);
    }
    $overviewOccLine = null;
    if (($profile->occupation_title ?? '') !== '') {
        $overviewOccLine = \App\Support\ProfileDisplayCopy::formatOccupationPhrase($profile->occupation_title);
    } elseif ($profile->profession && ($profile->profession->name ?? '') !== '') {
        $overviewOccLine = \App\Support\ProfileDisplayCopy::formatOccupationPhrase($profile->profession->name);
    }
    $scanRow1Parts = [];
    if ($overviewAge !== null && $dateOfBirthVisible) {
        $scanRow1Parts[] = __('profile.show_age_years', ['age' => $overviewAge]);
    }
    if ($maritalStatusVisible && $profile->maritalStatus) {
        $scanRow1Parts[] = $profile->maritalStatus->label ?? '';
    }
    if ($profile->seriousIntent && ($profile->seriousIntent->name ?? '') !== '') {
        $scanRow1Parts[] = $profile->seriousIntent->name;
    }
    $scanRow1Text = implode(' · ', array_filter($scanRow1Parts));
    $scanCommunityParts = array_filter([
        $profile->religion?->label,
        $profile->caste?->label,
        $profile->subCaste?->label,
    ]);
    if (($profile->motherTongue?->label ?? '') !== '') {
        $scanCommunityParts[] = __('Mother tongue').': '.$profile->motherTongue->label;
    }
    $scanCommunityText = implode(', ', array_filter($scanCommunityParts));
    $locCompact = \App\Support\ProfileDisplayCopy::compactLocationLine(
        $profile->city?->name,
        $profile->district?->name,
        $profile->state?->name
    );
    $scanLivesInText = ($locationVisible && $locCompact !== '') ? __('profile.scan_lives_in').' '.$locCompact : '';
    if ($locationVisible && ($profile->address_line ?? '') !== '') {
        $scanLivesInText = $scanLivesInText !== '' ? ($profile->address_line.' · '.$scanLivesInText) : ($profile->address_line);
    }
    $scanEduJobParts = array_filter([$overviewEduLine, $overviewOccLine]);
    $scanEduJobText = implode(' · ', $scanEduJobParts);
    $scanIncomeText = $hasPersonalIncome ? $personalIncomeDisplay : '';
    $scanRow5Parts = array_filter([
        $hasPersonalIncome ? $personalIncomeDisplay : null,
        $hasFamilyIncome ? $familyIncomeDisplay : null,
        ($heightVisible && ($profile->height_cm ?? '') !== '') ? $profile->height_cm.' cm' : null,
        ($profile->birth_time ?? '') !== '' ? $profile->birth_time : null,
    ]);
    $scanRow5Text = implode(' · ', $scanRow5Parts);
    $heroDisplayName = \App\Support\ProfileDisplayCopy::formatPersonName((string) ($profile->full_name ?? ''));
@endphp
            <div class="min-w-0">
            @if ($heroDisplayName !== '')
                <h2 class="mb-4 text-2xl font-extrabold uppercase leading-tight tracking-tight text-stone-900 dark:text-stone-50 sm:text-3xl break-words [word-break:break-word]">{{ $heroDisplayName }}</h2>
                @if (! ($isOwnProfile ?? false))
                    <p class="mt-1 text-xs text-green-600 dark:text-green-400">🟢 Active now • Viewed recently</p>
                @endif
            @endif
            <div class="space-y-3 rounded-xl bg-stone-50/50 px-3 py-3.5 ring-1 ring-stone-100/80 dark:bg-stone-900/25 dark:ring-stone-800/60 sm:px-3.5 @if (!($isOwnProfile ?? false)) lg:grid lg:grid-cols-2 lg:gap-x-8 lg:gap-y-2 lg:space-y-0 @endif">
                @if ($scanRow1Text !== '')
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 inline-flex h-4 w-4 shrink-0 text-rose-500/75 dark:text-rose-400/90" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z"/></svg>
                    </span>
                    <p class="text-[15px] font-semibold leading-snug tracking-tight text-stone-900 dark:text-stone-50">{{ $scanRow1Text }}</p>
                </div>
                @endif
                @if ($scanCommunityText !== '')
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 inline-flex h-4 w-4 shrink-0 text-rose-500/70 dark:text-rose-400/85" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6Z"/></svg>
                    </span>
                    <p class="min-w-0 text-sm leading-relaxed text-stone-800/95 dark:text-stone-100">{{ $scanCommunityText }}</p>
                </div>
                @endif
                @if ($scanLivesInText !== '')
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 inline-flex h-4 w-4 shrink-0 text-rose-500/70 dark:text-rose-400/85" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
                    </span>
                    <p class="min-w-0 text-sm leading-relaxed text-stone-700 dark:text-stone-200/95">{{ $scanLivesInText }}</p>
                </div>
                @endif
                @if ($scanEduJobText !== '')
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 inline-flex h-4 w-4 shrink-0 text-rose-500/70 dark:text-rose-400/85" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5"/></svg>
                    </span>
                    <p class="min-w-0 text-sm leading-relaxed text-stone-800/95 dark:text-stone-100">{{ $scanEduJobText }}</p>
                </div>
                @endif
                @if ($scanRow5Text !== '')
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 inline-flex h-4 w-4 shrink-0 text-emerald-600/85 dark:text-emerald-400/90" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125H18.75v-7.5h-3.75v7.5h-3.75v-7.5H9v7.5H5.25v-7.5H2.25"/></svg>
                    </span>
                    <p class="min-w-0 text-sm leading-relaxed text-stone-800/95 dark:text-stone-100">{{ $scanRow5Text }}</p>
                </div>
                @endif
            </div>
            </div>
        </div>
        </div>
        </div>

        @if (! ($isOwnProfile ?? false) && auth()->check())
            @php
                $crState = 'none';
                $crRequest = null;
                $crGrant = null;
                $cooldownEndsAt = null;
                if (! $contactRequestDisabled && $contactRequestState !== null) {
                    $crState = $contactRequestState['state'] ?? 'none';
                    $crRequest = $contactRequestState['request'] ?? null;
                    $crGrant = $contactRequestState['grant'] ?? null;
                    $cooldownEndsAt = $contactRequestState['cooldown_ends_at'] ?? null;
                }
            @endphp
            <aside
                class="flex w-full shrink-0 flex-col gap-2.5 border-t border-stone-200/80 bg-gradient-to-b from-stone-50/95 to-stone-50/70 px-4 py-4 dark:border-gray-700/70 dark:from-gray-900/80 dark:to-gray-900/60 lg:mt-0 lg:w-[13rem] lg:border-l lg:border-t-0 lg:px-3 lg:py-5 xl:w-[14rem]"
                aria-label="{{ __('profile.decision_zone_label') }}"
            >
                <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-stone-400 dark:text-stone-500">{{ __('profile.hero_actions_rail_title') }}</p>

                <div class="flex items-center gap-2.5 rounded-2xl border border-stone-200/90 bg-white px-3 py-2.5 text-left shadow-sm ring-1 ring-stone-100/80 dark:border-gray-600 dark:bg-gray-800/90 dark:ring-gray-700/50">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300" aria-hidden="true">
                        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z"/></svg>
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ __('profile.interest_rail_heading') }}</p>
                        <p class="truncate text-sm font-semibold text-stone-900 dark:text-stone-100">{{ $interestAlreadySent ? __('Interest Sent') : __('profile.interest_not_sent_yet') }}</p>
                    </div>
                </div>

                @if (! $contactRequestDisabled && $contactRequestState !== null && ($contactAccess['show_contact_request_rail'] ?? false))
                    @if ($crState === 'none' || ($crState === 'expired' && ! $cooldownEndsAt) || $crState === 'cancelled')
                        @if ($canSendContactRequest ?? false)
                            <button
                                type="button"
                                class="inline-flex w-full items-center justify-center gap-2 rounded-2xl border border-emerald-200/90 bg-white px-3 py-2.5 text-sm font-semibold text-emerald-800 shadow-sm ring-1 ring-emerald-100/80 transition hover:bg-emerald-50/90 dark:border-emerald-800/60 dark:bg-gray-800 dark:text-emerald-200 dark:ring-emerald-900/40 dark:hover:bg-emerald-950/30"
                                @click="$root.openRequestModal = true"
                            >
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300" aria-hidden="true">
                                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/></svg>
                                </span>
                                {{ __('Request Contact') }}
                            </button>
                        @else
                            <div class="rounded-2xl border border-stone-200/80 bg-white/90 px-3 py-2.5 text-center text-xs text-stone-500 dark:border-gray-600 dark:bg-gray-800 dark:text-stone-400">
                                {{ __('notifications.mutual_only') }}
                            </div>
                        @endif
                    @elseif ($crState === 'pending')
                        <div class="rounded-2xl border border-amber-200/90 bg-amber-50/90 px-3 py-2.5 text-center text-xs font-semibold text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
                            {{ __('Request Sent (Pending)') }}
                        </div>
                        @if ($crRequest)
                            <form method="POST" action="{{ route('contact-requests.cancel', $crRequest) }}" class="w-full">
                                @csrf
                                <button type="submit" class="w-full rounded-2xl border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-stone-200">{{ __('Cancel request') }}</button>
                            </form>
                        @endif
                    @elseif ($crState === 'accepted' && $crGrant)
                        <a
                            href="{{ route('matrimony.profile.show', $profile) }}#profile-contact-panel"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-2xl border border-emerald-200/90 bg-emerald-600 px-3 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700 dark:border-emerald-700 dark:bg-emerald-700"
                        >
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white/20" aria-hidden="true">
                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z"/></svg>
                            </span>
                            {{ __('View Contact') }}
                        </a>
                    @elseif ($crState === 'rejected')
                        <p class="rounded-2xl border border-red-200/80 bg-red-50 px-3 py-2 text-center text-xs font-medium text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">{{ __('Request Rejected') }}</p>
                        @if ($cooldownEndsAt)
                            <p class="text-center text-[11px] text-stone-500 dark:text-stone-400">{{ __('Cooling period ends') }} {{ $cooldownEndsAt->format('M j, Y') }}</p>
                        @endif
                    @elseif ($crState === 'expired')
                        <p class="rounded-2xl border border-stone-200 bg-stone-100 px-3 py-2 text-center text-xs text-stone-600 dark:border-gray-600 dark:bg-gray-800 dark:text-stone-400">{{ __('Request Expired') }}</p>
                        @if (! $cooldownEndsAt)
                            <button
                                type="button"
                                class="w-full rounded-2xl border border-emerald-200 bg-white px-3 py-2 text-sm font-semibold text-emerald-800 dark:border-emerald-800 dark:bg-gray-800 dark:text-emerald-200"
                                @click="$root.openRequestModal = true"
                            >
                                {{ __('Request again') }}
                            </button>
                        @endif
                    @elseif ($crState === 'revoked')
                        <p class="rounded-2xl border border-stone-200 bg-stone-100 px-3 py-2 text-center text-xs text-stone-600 dark:border-gray-600 dark:bg-gray-800 dark:text-stone-400">{{ __('Contact no longer available') }}</p>
                    @endif
                @endif

                @if (! ($isOwnProfile ?? false) && ($contactAccess['show_mediator_cta'] ?? false))
                    @if ($contactAccess['needs_upgrade_for_mediator'] ?? false)
                        <button
                            type="button"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-2xl border border-amber-200/90 bg-amber-50 px-3 py-2.5 text-xs font-semibold text-amber-900 shadow-sm dark:border-amber-800/60 dark:bg-amber-950/40 dark:text-amber-100"
                            @click="$root.showContactUpgradeModal = true"
                        >
                            {{ __('contact_access.mediator_heading') }} — {{ __('contact_access.upgrade_plans') }}
                        </button>
                    @else
                        <form method="POST" action="{{ route('matrimony.profile.mediator-request', $profile) }}" class="w-full">
                            @csrf
                            <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-2xl border border-violet-200/90 bg-white px-3 py-2.5 text-xs font-semibold text-violet-900 shadow-sm ring-1 ring-violet-100/80 transition hover:bg-violet-50/90 dark:border-violet-800/60 dark:bg-gray-800 dark:text-violet-200 dark:ring-violet-900/40">
                                {{ __('contact_access.mediator_submit') }}
                            </button>
                        </form>
                    @endif
                @endif

                @if ($userId === null || ! $featureUsage->canUse((int) $userId, 'chat_send_limit'))
                    <div class="mt-1 w-full text-center">
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            You’ve reached your chat limit
                        </p>
                        <a href="{{ route('plans.index') }}"
                           class="mt-2 inline-block rounded bg-red-500 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-red-600">
                            Upgrade to continue chatting
                        </a>
                    </div>
                @else
                    <form method="POST" action="{{ route('chat.start', ['matrimony_profile' => $profile->id]) }}" class="w-full">
                        @csrf
                        <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-2xl border border-indigo-200/90 bg-white px-3 py-2.5 text-sm font-semibold text-indigo-800 shadow-sm ring-1 ring-indigo-100/70 transition hover:bg-indigo-50/90 dark:border-indigo-800/60 dark:bg-gray-800 dark:text-indigo-200 dark:ring-indigo-900/35 dark:hover:bg-indigo-950/25">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300" aria-hidden="true">
                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.76c0 1.6 1.123 2.95 2.63 3.217.42.074.797.31 1.046.66l.85 1.19c.34.477.99.596 1.48.272l2.155-1.43c.33-.219.73-.29 1.11-.2 1.04.246 2.17.246 3.21 0 .38-.09.78-.02 1.11.2l2.155 1.43c.49.324 1.14.205 1.48-.272l.85-1.19c.249-.35.626-.586 1.046-.66 1.507-.267 2.63-1.618 2.63-3.217V6.99c0-1.86-1.51-3.37-3.37-3.37H5.62c-1.86 0-3.37 1.51-3.37 3.37v5.77Z"/></svg>
                            </span>
                            {{ __('Chat') }}
                        </button>
                    </form>
                @endif

                @isset($inShortlist)
                    @if ($inShortlist)
                        <form method="POST" action="{{ route('shortlist.destroy', $profile) }}" class="w-full">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-2xl border border-stone-200 bg-white px-3 py-2.5 text-sm font-medium text-stone-700 shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-stone-200">
                                <svg class="h-4 w-4 text-stone-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3v1.5M3 21v-6m0 0 2.25-.75M17.25 3 21 7.5m0 0L17.25 12M21 7.5H3"/></svg>
                                {{ __('Remove from shortlist') }}
                            </button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('shortlist.store', $profile) }}" class="w-full">
                            @csrf
                            <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-2xl border border-sky-200/90 bg-white px-3 py-2.5 text-sm font-medium text-sky-800 shadow-sm dark:border-sky-800 dark:bg-gray-800 dark:text-sky-200">
                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                                {{ __('Add to shortlist') }}
                            </button>
                        </form>
                    @endif
                @endisset

                <form method="POST" action="{{ route('blocks.store', $profile) }}" class="w-full">
                    @csrf
                    <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-2xl border border-stone-200/90 bg-white px-3 py-2 text-xs font-medium text-stone-600 dark:border-gray-600 dark:bg-gray-800 dark:text-stone-400">
                        {{ __('Block') }}
                    </button>
                </form>
            </aside>
        @elseif (! ($isOwnProfile ?? false))
            <aside class="flex w-full shrink-0 flex-col justify-center gap-2 border-t border-stone-200/80 bg-stone-50/60 px-4 py-4 dark:border-gray-700/70 dark:bg-gray-900/50 lg:mt-0 lg:w-36 lg:border-l lg:border-t-0 lg:px-3 lg:py-6" aria-label="{{ __('profile.decision_zone_label') }}">
                <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-stone-400">{{ __('profile.hero_actions_rail_title') }}</p>
                <a href="{{ route('login') }}" class="inline-flex w-full items-center justify-center rounded-2xl border border-rose-200 bg-white px-3 py-2.5 text-sm font-semibold text-rose-800 shadow-sm dark:border-rose-900/50 dark:bg-gray-800 dark:text-rose-200">{{ __('Login') }}</a>
            </aside>
        @endif
        </div>

        @if (! ($isOwnProfile ?? false))
            <div class="border-b border-stone-200/85 bg-gradient-to-r from-rose-50/80 via-white to-white px-5 py-6 dark:border-gray-700/80 dark:from-rose-950/20 dark:via-gray-900 dark:to-gray-900 lg:px-8">
                @if (auth()->check())
                    @if (session('success'))
                        <div class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-100">{{ session('success') }}</div>
                    @endif
                    @if (session('error'))
                        <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900 dark:border-red-800 dark:bg-red-950/40 dark:text-red-100">{{ session('error') }}</div>
                    @endif
                @endif
                <div class="flex flex-col items-stretch gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-rose-600/90 dark:text-rose-400/90">{{ __('profile.hero_interest_prompt') }}</p>
                        <p class="mt-0.5 text-sm text-stone-600 dark:text-stone-400">{{ __('profile.hero_interest_sub') }}</p>
                    </div>
                    <div class="shrink-0 sm:min-w-[12rem]">
                        @if (auth()->check())
                            @if ($interestAlreadySent)
                                <span class="flex w-full items-center justify-center rounded-xl border border-stone-200 bg-stone-100 px-5 py-3 text-sm font-semibold text-stone-600 dark:border-gray-600 dark:bg-gray-800 dark:text-stone-300">{{ __('Interest Sent') }}</span>
                            @else
                                <form method="POST" action="{{ route('interests.send', $profile) }}" class="w-full">
                                    @csrf
                                    <button type="submit" class="w-full rounded-xl bg-rose-600 px-6 py-3 text-sm font-semibold text-white shadow-md shadow-rose-600/25 transition hover:bg-rose-700 focus:outline-none focus:ring-2 focus:ring-rose-400 focus:ring-offset-2 dark:focus:ring-offset-gray-900">{{ __('Send Interest') }}</button>
                                </form>
                            @endif
                        @else
                            <a href="{{ route('login') }}" class="inline-flex w-full items-center justify-center rounded-xl border border-rose-200 bg-white px-5 py-3 text-sm font-semibold text-rose-900 shadow-sm dark:border-rose-900/45 dark:bg-gray-800 dark:text-rose-100">{{ __('Login') }} — {{ __('Send Interest') }}</a>
                        @endif
                    </div>
                </div>

                @if (auth()->check() && ! $contactRequestDisabled && $contactRequestState !== null && ($contactAccess['show_contact_request_rail'] ?? false))
                    @php
                        $reasons = config('communication.request_reasons', []);
                    @endphp
                    <div x-show="$root.openRequestModal" x-cloak x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" style="display: none;" @click.self="$root.openRequestModal = false">
                        <div class="mx-4 max-w-md w-full rounded-lg bg-white p-6 shadow-xl dark:bg-gray-800" @click.stop x-data="{ reason: '{{ old('reason', 'talk_to_family') }}' }">
                            <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Request Contact') }}</h3>
                            <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">{{ __('profile.contact_request_modal_intro') }}</p>
                            <form method="POST" action="{{ route('contact-requests.store', $profile) }}">
                                @csrf
                                <div class="mb-4">
                                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('profile.contact_request_reason_label') }} <span class="text-red-500">*</span></label>
                                    <select name="reason" required x-model="reason" class="w-full rounded-md border border-gray-300 shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                        @foreach ($reasons as $key => $label)
                                            <option value="{{ $key }}" {{ old('reason') === $key ? 'selected' : '' }}>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="mb-4" x-show="reason === 'other'" x-cloak>
                                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('profile.contact_request_other_label') }}</label>
                                    <textarea name="other_reason_text" rows="2" class="w-full rounded-md border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" placeholder="{{ __('profile.contact_request_other_placeholder') }}">{{ old('other_reason_text') }}</textarea>
                                </div>
                                <div class="mb-4">
                                    <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('profile.contact_request_methods_label') }}</label>
                                    @foreach (['email' => 'Email', 'phone' => 'Phone', 'whatsapp' => 'WhatsApp'] as $scope => $label)
                                        <label class="mr-4 inline-flex items-center"><input type="checkbox" name="requested_scopes[]" value="{{ $scope }}" {{ in_array($scope, old('requested_scopes', [])) ? 'checked' : '' }} class="rounded border-gray-300 dark:border-gray-600"> <span class="ml-1">{{ $label }}</span></label>
                                    @endforeach
                                </div>
                                <div class="flex gap-2">
                                    <button type="submit" class="rounded-md bg-green-600 px-4 py-2 font-medium text-white">{{ __('common.submit') }}</button>
                                    <button type="button" @click="$root.openRequestModal = false" class="rounded-md bg-gray-500 px-4 py-2 font-medium text-white">{{ __('common.cancel') }}</button>
                                </div>
                            </form>
                        </div>
                    </div>
                @endif

                @if (! empty($contactGrantReveal))
                    <div id="contact-reveal" class="mt-4 max-w-xl rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-800 dark:bg-green-900/20">
                        <p class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('Contact (shared with you)') }}</p>
                        @if (! empty($contactGrantReveal['email']))
                            <p class="text-sm"><span class="text-gray-500">Email:</span> {{ $contactGrantReveal['email'] }}</p>
                        @endif
                        @if (! empty($contactGrantReveal['phone']))
                            @if ($userId !== null && $featureUsage->canUse((int) $userId, \App\Services\FeatureUsageService::FEATURE_CONTACT_VIEW_LIMIT))
                                <p class="text-sm"><span class="text-gray-500">Phone:</span> {{ $contactGrantReveal['phone'] }}</p>
                            @else
                                @php
                                    $grantPhoneDigits = preg_replace('/\D/', '', (string) $contactGrantReveal['phone']);
                                    $maskedGrantPhone = substr($grantPhoneDigits !== '' ? $grantPhoneDigits : '9876543210', 0, 4) . 'XXXX';
                                @endphp
                                <p class="text-sm"><span class="text-gray-500">Phone:</span> {{ $maskedGrantPhone }} 🔒</p>
                            @endif
                        @endif
                        @if (! empty($contactGrantReveal['whatsapp']))
                            <p class="text-sm"><span class="text-gray-500">WhatsApp:</span> {{ $contactGrantReveal['whatsapp'] }}</p>
                        @endif
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('profile.contact_reveal_footer') }}</p>
                    </div>
                @endif
            </div>
        @endif

        @php
            $detailNarrative = trim((string) ($extendedAttributes->narrative_about_me ?? ''));
            $detailExpectations = trim((string) ($extendedAttributes->narrative_expectations ?? ''));
            $locationLineForAbout = isset($locationLine) ? $locationLine : implode(', ', array_filter([
                $profile->city?->name,
                $profile->taluka?->name,
                $profile->district?->name,
                $profile->state?->name,
                $profile->country?->name,
            ]));
            $detailAboutFallback = '';
            if ($detailNarrative === '') {
                $detailAboutFallback = implode('  |  ', array_filter([
                    ($profile->maritalStatus?->label ?? '') !== '' ? __('Marital status').': '.$profile->maritalStatus->label : null,
                    ($profile->highest_education ?? '') !== '' ? __('Education').': '.$profile->highest_education : null,
                    ($profile->occupation_title ?? '') !== '' ? __('Occupation').': '.$profile->occupation_title : (($profile->profession?->name ?? '') !== '' ? __('Occupation').': '.$profile->profession->name : null),
                    $locationLineForAbout !== '' ? __('Location').': '.$locationLineForAbout : null,
                ]));
            }
            $hasAboutBody = $detailNarrative !== '' || $detailAboutFallback !== '';
        @endphp

        {{-- About me spotlight: directly under “Like this profile” / interest strip (or first fold for own profile) --}}
        @if ($hasAboutBody)
            <div class="px-5 pb-2 pt-2 lg:px-8 lg:pb-3 lg:pt-4">
                <article
                    class="relative overflow-hidden rounded-2xl border border-stone-200/70 bg-gradient-to-br from-white via-rose-50/40 to-amber-50/25 px-5 py-6 shadow-[0_12px_48px_-16px_rgba(190,24,93,0.12)] ring-1 ring-rose-100/50 dark:border-rose-900/25 dark:from-gray-900 dark:via-rose-950/30 dark:to-gray-900 dark:ring-rose-900/20 sm:px-7 sm:py-8"
                    aria-labelledby="profile-about-me-heading"
                >
                    <div class="pointer-events-none absolute -right-16 -top-16 h-48 w-48 rounded-full bg-rose-200/25 blur-3xl dark:bg-rose-600/10" aria-hidden="true"></div>
                    <div class="pointer-events-none absolute -bottom-12 -left-10 h-40 w-40 rounded-full bg-amber-200/20 blur-3xl dark:bg-amber-500/10" aria-hidden="true"></div>
                    <div class="relative">
                        <header class="mb-4 flex flex-wrap items-end justify-between gap-3 border-b border-stone-200/60 pb-3 dark:border-stone-600/50">
                            <div class="flex items-center gap-3">
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-rose-500 to-rose-700 text-white shadow-md shadow-rose-600/25 ring-2 ring-white/30 dark:from-rose-600 dark:to-rose-900 dark:ring-rose-900/40" aria-hidden="true">
                                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" /></svg>
                                </span>
                                <div>
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-rose-600/90 dark:text-rose-400/90">{{ __('profile.about_me_spotlight_kicker') }}</p>
                                    <h2 id="profile-about-me-heading" class="text-xl font-semibold tracking-tight text-stone-900 dark:text-stone-50 sm:text-2xl">{{ __('About me') }}</h2>
                                </div>
                            </div>
                            @if ($detailNarrative === '' && $detailAboutFallback !== '')
                                <span class="inline-flex items-center rounded-full bg-amber-100/90 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-amber-900 dark:bg-amber-950/50 dark:text-amber-200">{{ __('profile.about_me_auto_summary_badge') }}</span>
                            @endif
                        </header>
                        <div class="text-[15px] leading-[1.7] text-stone-800 dark:text-stone-100 sm:text-[15.5px]">
                            <p class="whitespace-pre-wrap font-normal [text-wrap:pretty]">{{ $detailNarrative !== '' ? $extendedAttributes->narrative_about_me : $detailAboutFallback }}</p>
                        </div>
                    </div>
                </article>
            </div>
        @endif

        {{-- Deeper detail: calmer rhythm below the quick-decision band --}}
        @php
            $hasPhysical = ($heightVisible && ($profile->height_cm ?? '') !== '') || ($profile->weight_kg ?? null) !== null || $profile->complexion || $profile->physicalBuild || $profile->bloodGroup;
            $hasEducationBand = ($educationVisible && (($profile->highest_education ?? '') !== '' || ($profile->highest_education_other ?? '') !== '')) || (($profile->specialization ?? '') !== '');
            $hasCareerBand = ($profile->workingWithType && ($profile->workingWithType->name ?? '') !== '')
                || ($profile->profession && ($profile->profession->name ?? '') !== '')
                || (($profile->occupation_title ?? '') !== '')
                || (($profile->company_name ?? '') !== '')
                || $hasPersonalIncome
                || $hasFamilyIncome
                || ($profile->incomeCurrency && ! $hasPersonalIncome && ! $hasFamilyIncome);
            $showProfileEssentials = $hasPhysical || $hasEducationBand || $hasCareerBand;
            $aboutFoldTight = ($hasAboutBody ?? false);
        @endphp
        <div @class([
            'space-y-6 px-5 pb-8 lg:space-y-7 lg:border-t lg:border-stone-200/65 dark:lg:border-gray-700/85 lg:text-[15px] lg:px-8',
            'lg:pt-5' => $aboutFoldTight,
            'lg:pt-8' => ! $aboutFoldTight,
        ])>

        @if ($showProfileEssentials)
            <div class="relative -mt-1 mb-2 lg:mb-3">
                <p class="mb-3 text-[11px] font-semibold uppercase tracking-[0.2em] text-stone-400 dark:text-stone-500">{{ __('profile.essentials_band_kicker') }}</p>
                <div class="relative overflow-hidden rounded-2xl border border-stone-200/75 bg-gradient-to-br from-white via-stone-50/60 to-rose-50/20 shadow-[0_16px_48px_-20px_rgba(190,24,93,0.14)] ring-1 ring-stone-100/70 dark:border-gray-700/80 dark:from-gray-900 dark:via-gray-900/95 dark:to-rose-950/20 dark:ring-gray-700/60">
                    <div class="pointer-events-none absolute -right-20 -top-24 h-56 w-56 rounded-full bg-rose-200/20 blur-3xl dark:bg-rose-600/10" aria-hidden="true"></div>
                    <div class="pointer-events-none absolute -bottom-16 -left-16 h-48 w-48 rounded-full bg-sky-200/15 blur-3xl dark:bg-sky-900/20" aria-hidden="true"></div>
                    <div class="relative divide-y divide-stone-100/90 dark:divide-gray-700/80">
                        @if ($hasPhysical)
                            <section class="px-5 py-6 sm:px-7 sm:py-7" aria-labelledby="profile-physical-heading">
                                <header class="mb-5 flex flex-wrap items-center gap-3">
                                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-rose-500 to-rose-700 text-white shadow-md shadow-rose-600/20 dark:from-rose-600 dark:to-rose-900" aria-hidden="true">
                                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
                                    </span>
                                    <div>
                                        <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-rose-600/85 dark:text-rose-400/90">{{ __('profile.essentials_physical_kicker') }}</p>
                                        <h3 id="profile-physical-heading" class="text-lg font-semibold tracking-tight text-stone-900 dark:text-stone-50 sm:text-xl">{{ __('Physical') }}</h3>
                                    </div>
                                </header>
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    @if ($heightVisible && ($profile->height_cm ?? '') !== '')
                                        <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ __('Height') }}</p>
                                            <p class="mt-1 text-base font-semibold text-stone-900 dark:text-stone-100">{{ $profile->height_cm }} cm</p>
                                        </div>
                                    @endif
                                    @if (($profile->weight_kg ?? null) !== null && $profile->weight_kg !== '')
                                        <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ __('Weight') }}</p>
                                            <p class="mt-1 text-base font-semibold text-stone-900 dark:text-stone-100">{{ $profile->weight_kg }} kg</p>
                                        </div>
                                    @endif
                                    @if ($profile->complexion)
                                        <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ __('Complexion') }}</p>
                                            <p class="mt-1 text-base font-semibold text-stone-900 dark:text-stone-100">{{ $profile->complexion->label ?? '—' }}</p>
                                        </div>
                                    @endif
                                    @if ($profile->physicalBuild)
                                        <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ __('Physical Build') }}</p>
                                            <p class="mt-1 text-base font-semibold text-stone-900 dark:text-stone-100">{{ $profile->physicalBuild->label ?? '—' }}</p>
                                        </div>
                                    @endif
                                    @if ($profile->bloodGroup)
                                        <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55 sm:col-span-2">
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ __('Blood Group') }}</p>
                                            <p class="mt-1 text-base font-semibold text-stone-900 dark:text-stone-100">{{ $profile->bloodGroup->label ?? '—' }}</p>
                                        </div>
                                    @endif
                                </div>
                            </section>
                        @endif

                        @if ($hasEducationBand)
                            <section class="px-5 py-6 sm:px-7 sm:py-7" aria-labelledby="profile-education-heading">
                                <header class="mb-5 flex flex-wrap items-center gap-3">
                                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-sky-500 to-indigo-600 text-white shadow-md shadow-sky-600/20 dark:from-sky-700 dark:to-indigo-900" aria-hidden="true">
                                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5"/></svg>
                                    </span>
                                    <div>
                                        <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-sky-600/90 dark:text-sky-400/90">{{ __('profile.essentials_education_kicker') }}</p>
                                        <h3 id="profile-education-heading" class="text-lg font-semibold tracking-tight text-stone-900 dark:text-stone-50 sm:text-xl">{{ __('Education') }}</h3>
                                    </div>
                                </header>
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    @if ($educationVisible && (($profile->highest_education ?? '') !== '' || ($profile->highest_education_other ?? '') !== ''))
                                        <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55 sm:col-span-2">
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ __('Education') }}</p>
                                            <p class="mt-1 text-base font-semibold leading-snug text-stone-900 dark:text-stone-100">{{ trim(($profile->highest_education ?? '').((($profile->highest_education_other ?? '') !== '') ? ' — '.$profile->highest_education_other : '')) }}</p>
                                        </div>
                                    @endif
                                    @if (($profile->specialization ?? '') !== '')
                                        <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55 sm:col-span-2">
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ __('Specialization') }}</p>
                                            <p class="mt-1 text-base font-semibold text-stone-900 dark:text-stone-100">{{ $profile->specialization }}</p>
                                        </div>
                                    @endif
                                </div>
                            </section>
                        @endif

                        @if ($hasCareerBand)
                            <section class="px-5 py-6 sm:px-7 sm:py-7" aria-labelledby="profile-career-heading">
                                <header class="mb-5 flex flex-wrap items-center gap-3">
                                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-700 text-white shadow-md shadow-emerald-600/20 dark:from-emerald-700 dark:to-teal-900" aria-hidden="true">
                                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.051.34-4.021.51-5.93.51-1.898 0-3.867-.17-5.93-.51-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 0 0 .75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 0 0-3.413-.387m4.5 8.006c-.194.165-.42.296-.677.39L18.75 12.5M20.25 14.15l-.856 1.944M18.75 12.5l-.856 1.944m0 0-2.8-1.336m2.8 1.336-.856 1.944M15.75 12.5l2.8 1.336m0 0 .856 1.944M12.75 12.5l-2.8 1.336M12.75 12.5l-.856 1.944m.856-1.944L12 10.164m0 0-.856-1.944M12 10.164l2.8 1.336m-2.8-1.336L9.2 8.828m5.6 1.336L12 10.164"/></svg>
                                    </span>
                                    <div>
                                        <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-emerald-600/90 dark:text-emerald-400/90">{{ __('profile.essentials_career_kicker') }}</p>
                                        <h3 id="profile-career-heading" class="text-lg font-semibold tracking-tight text-stone-900 dark:text-stone-50 sm:text-xl">{{ __('profile.career_section_title') }}</h3>
                                    </div>
                                </header>
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    @if ($profile->workingWithType && ($profile->workingWithType->name ?? '') !== '')
                                        <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ __('components.education.working_with') }}</p>
                                            <p class="mt-1 text-base font-semibold text-stone-900 dark:text-stone-100">{{ $profile->workingWithType->name }}</p>
                                        </div>
                                    @endif
                                    @if ($profile->profession && ($profile->profession->name ?? '') !== '')
                                        <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ __('components.education.working_as') }}</p>
                                            <p class="mt-1 text-base font-semibold text-stone-900 dark:text-stone-100">{{ $profile->profession->name }}</p>
                                        </div>
                                    @endif
                                    @if (($profile->occupation_title ?? '') !== '')
                                        <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ __('Occupation') }}</p>
                                            <p class="mt-1 text-base font-semibold text-stone-900 dark:text-stone-100">{{ $profile->occupation_title }}</p>
                                        </div>
                                    @endif
                                    @if (($profile->company_name ?? '') !== '')
                                        <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ __('Company') }}</p>
                                            <p class="mt-1 text-base font-semibold text-stone-900 dark:text-stone-100">{{ $profile->company_name }}</p>
                                        </div>
                                    @endif
                                    @if ($hasPersonalIncome)
                                        <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ __('Income') }}</p>
                                            <p class="mt-1 text-base font-semibold text-stone-900 dark:text-stone-100">{{ $personalIncomeDisplay }}</p>
                                        </div>
                                    @endif
                                    @if ($hasFamilyIncome)
                                        <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ __('Family Income') }}</p>
                                            <p class="mt-1 text-base font-semibold text-stone-900 dark:text-stone-100">{{ $familyIncomeDisplay }}</p>
                                        </div>
                                    @endif
                                    @if ($profile->incomeCurrency && ! $hasPersonalIncome && ! $hasFamilyIncome)
                                        <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ __('Income Currency') }}</p>
                                            <p class="mt-1 text-base font-semibold text-stone-900 dark:text-stone-100">{{ trim($profile->incomeCurrency->symbol ?? '') }} {{ $profile->incomeCurrency->code ?? '—' }}</p>
                                        </div>
                                    @endif
                                </div>
                            </section>
                        @endif
                    </div>
                </div>
            </div>
        @endif

@php
    $siblings = $profile->siblings ?? collect();
    $brothersFromEngine = $siblings->where('relation_type', 'brother')->count();
    $sistersFromEngine = $siblings->where('relation_type', 'sister')->count();
    $hasFamily = ($profile->father_name ?? '') !== '' || ($profile->father_occupation ?? '') !== '' || ($profile->mother_name ?? '') !== '' || ($profile->mother_occupation ?? '') !== '' || $brothersFromEngine > 0 || $sistersFromEngine > 0 || $profile->familyType;
    $workCityName = $profile->work_city_id ? \App\Models\City::where('id', $profile->work_city_id)->value('name') : null;
    $workStateName = $profile->work_state_id ? \App\Models\State::where('id', $profile->work_state_id)->value('name') : null;
    $hasWorkLocation = $workCityName || $workStateName;
    $hasBirthPlace = $profile->birth_city_id || $profile->birth_taluka_id || $profile->birth_district_id || $profile->birth_state_id;
    $hasNativePlace = $profile->native_city_id || $profile->native_taluka_id || $profile->native_district_id || $profile->native_state_id;
@endphp

@if ($hasFamily)
<div class="relative overflow-hidden rounded-2xl border border-stone-200/75 bg-gradient-to-br from-white via-amber-50/35 to-orange-50/20 shadow-[0_14px_44px_-20px_rgba(180,83,9,0.14)] ring-1 ring-amber-100/50 dark:border-amber-900/25 dark:from-gray-900 dark:via-amber-950/10 dark:to-gray-900 dark:ring-amber-900/20">
    <div class="pointer-events-none absolute -right-16 -top-20 h-48 w-48 rounded-full bg-amber-200/25 blur-3xl dark:bg-amber-600/10" aria-hidden="true"></div>
    <section class="relative px-5 py-6 sm:px-7 sm:py-7" aria-labelledby="profile-family-heading">
        <header class="mb-5 flex flex-wrap items-center gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-amber-500 to-orange-600 text-white shadow-md shadow-amber-600/25 dark:from-amber-600 dark:to-orange-900" aria-hidden="true">
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/></svg>
            </span>
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-amber-800/90 dark:text-amber-400/90">{{ __('profile.family_section_kicker') }}</p>
                <h3 id="profile-family-heading" class="text-lg font-semibold tracking-tight text-stone-900 dark:text-stone-50 sm:text-xl">{{ __('Family') }}</h3>
            </div>
        </header>
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
            @if (($profile->father_name ?? '') !== '')
                <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ __('Father') }}</p>
                    <p class="mt-1 text-base font-semibold text-stone-900 dark:text-stone-100">{{ $profile->father_name }}{{ ($profile->father_occupation ?? '') !== '' ? ' · ' . $profile->father_occupation : '' }}</p>
                </div>
            @endif
            @if (($profile->mother_name ?? '') !== '')
                <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ __('Mother') }}</p>
                    <p class="mt-1 text-base font-semibold text-stone-900 dark:text-stone-100">{{ $profile->mother_name }}{{ ($profile->mother_occupation ?? '') !== '' ? ' · ' . $profile->mother_occupation : '' }}</p>
                </div>
            @endif
            @if ($brothersFromEngine > 0 || $sistersFromEngine > 0)
                @php
                    $b = $brothersFromEngine > 0 ? $brothersFromEngine.' brother'.($brothersFromEngine !== 1 ? 's' : '') : '';
                    $s = $sistersFromEngine > 0 ? $sistersFromEngine.' sister'.($sistersFromEngine !== 1 ? 's' : '') : '';
                    $siblingsText = trim($b.($b && $s ? ', ' : '').$s);
                @endphp
                <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55 md:col-span-2">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ __('Siblings') }}</p>
                    <p class="mt-1 text-base font-semibold text-stone-900 dark:text-stone-100">{{ $siblingsText }}</p>
                </div>
            @endif
            @if ($profile->familyType)
                <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55 md:col-span-2">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ __('Family Type') }}</p>
                    <p class="mt-1 text-base font-semibold text-stone-900 dark:text-stone-100">{{ $profile->familyType->label ?? '—' }}</p>
                </div>
            @endif
        </div>
    </section>
</div>
@endif

@if ($hasWorkLocation)
<div class="relative overflow-hidden rounded-2xl border border-stone-200/75 bg-gradient-to-br from-white via-cyan-50/30 to-sky-50/25 shadow-[0_14px_44px_-20px_rgba(14,116,144,0.12)] ring-1 ring-cyan-100/50 dark:border-cyan-900/20 dark:from-gray-900 dark:via-cyan-950/15 dark:to-gray-900 dark:ring-cyan-900/20">
    <div class="pointer-events-none absolute -left-10 -bottom-12 h-40 w-40 rounded-full bg-sky-200/20 blur-3xl dark:bg-sky-600/10" aria-hidden="true"></div>
    <section class="relative px-5 py-6 sm:px-7 sm:py-7" aria-labelledby="profile-work-location-heading">
        <header class="mb-5 flex flex-wrap items-center gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-cyan-500 to-sky-600 text-white shadow-md shadow-cyan-600/20 dark:from-cyan-600 dark:to-sky-900" aria-hidden="true">
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/></svg>
            </span>
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-cyan-700/90 dark:text-cyan-400/90">{{ __('profile.work_location_section_kicker') }}</p>
                <h3 id="profile-work-location-heading" class="text-lg font-semibold tracking-tight text-stone-900 dark:text-stone-50 sm:text-xl">{{ __('Work Location') }}</h3>
            </div>
        </header>
        <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
            <p class="text-base font-semibold leading-relaxed text-stone-900 dark:text-stone-100">{{ implode(', ', array_filter([$workCityName, $workStateName])) }}</p>
        </div>
    </section>
</div>
@endif

@if (($profilePropertySummary ?? null) && ($profilePropertySummary->owns_agriculture ?? false) && (($profilePropertySummary->agriculture_type ?? '') !== ''))
<div class="relative overflow-hidden rounded-2xl border border-stone-200/75 bg-gradient-to-br from-white via-emerald-50/35 to-green-50/20 shadow-[0_14px_44px_-20px_rgba(5,150,105,0.12)] ring-1 ring-emerald-100/55 dark:border-emerald-900/20 dark:from-gray-900 dark:via-emerald-950/15 dark:to-gray-900">
    <section class="relative px-5 py-6 sm:px-7 sm:py-7" aria-labelledby="profile-property-heading">
        <header class="mb-5 flex flex-wrap items-center gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-emerald-500 to-green-700 text-white shadow-md dark:from-emerald-600 dark:to-green-900" aria-hidden="true">
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21v-8.25M15.75 21v-4.875c0-.621-.504-1.125-1.125-1.125h-4.5c-.621 0-1.125.504-1.125 1.125V21M3.375 9.75h17.25c.621 0 1.125-.504 1.125-1.125v-2.25c0-1.036-.84-1.875-1.875-1.875H4.125c-1.036 0-1.875.84-1.875 1.875v2.25c0 .621.504 1.125 1.125 1.125z"/></svg>
            </span>
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-emerald-700/90 dark:text-emerald-400/90">{{ __('profile.property_section_kicker') }}</p>
                <h3 id="profile-property-heading" class="text-lg font-semibold tracking-tight text-stone-900 dark:text-stone-50 sm:text-xl">{{ __('Property') }}</h3>
            </div>
        </header>
        <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ __('Agriculture type') }}</p>
            <p class="mt-1 text-base font-semibold text-stone-900 dark:text-stone-100">{{ $profilePropertySummary->agriculture_type }}</p>
        </div>
    </section>
</div>
@endif

@if ($hasBirthPlace)
<div class="relative overflow-hidden rounded-2xl border border-stone-200/75 bg-gradient-to-br from-white via-sky-50/30 to-indigo-50/15 shadow-[0_14px_44px_-20px_rgba(59,130,246,0.12)] ring-1 ring-sky-100/60 dark:border-sky-900/20 dark:from-gray-900 dark:via-sky-950/15 dark:to-gray-900">
    <section class="relative px-5 py-6 sm:px-7 sm:py-7" aria-labelledby="profile-birth-place-heading">
        <header class="mb-5 flex flex-wrap items-center gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-sky-500 to-indigo-600 text-white shadow-md shadow-sky-600/20 dark:from-sky-600 dark:to-indigo-900" aria-hidden="true">
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3"/></svg>
            </span>
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-sky-700/90 dark:text-sky-400/90">{{ __('profile.birth_place_section_kicker') }}</p>
                <h3 id="profile-birth-place-heading" class="text-lg font-semibold tracking-tight text-stone-900 dark:text-stone-50 sm:text-xl">{{ __('Birth Place') }}</h3>
            </div>
        </header>
        <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
            <p class="text-base font-semibold leading-relaxed text-stone-900 dark:text-stone-100">{{ implode(', ', array_filter([$profile->birthCity?->name, $profile->birthTaluka?->name, $profile->birthDistrict?->name, $profile->birthState?->name])) }}</p>
        </div>
    </section>
</div>
@endif

@if ($hasNativePlace)
<div class="relative overflow-hidden rounded-2xl border border-stone-200/75 bg-gradient-to-br from-white via-teal-50/30 to-cyan-50/20 shadow-[0_14px_44px_-20px_rgba(13,148,136,0.12)] ring-1 ring-teal-100/50 dark:border-teal-900/20 dark:from-gray-900 dark:via-teal-950/15 dark:to-gray-900">
    <section class="relative px-5 py-6 sm:px-7 sm:py-7" aria-labelledby="profile-native-place-heading">
        <header class="mb-5 flex flex-wrap items-center gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-teal-500 to-cyan-600 text-white shadow-md dark:from-teal-600 dark:to-cyan-900" aria-hidden="true">
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
            </span>
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-teal-700/90 dark:text-teal-400/90">{{ __('profile.native_place_section_kicker') }}</p>
                <h3 id="profile-native-place-heading" class="text-lg font-semibold tracking-tight text-stone-900 dark:text-stone-50 sm:text-xl">{{ __('Native Place') }}</h3>
            </div>
        </header>
        <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
            <p class="text-base font-semibold leading-relaxed text-stone-900 dark:text-stone-100">{{ implode(', ', array_filter([$profile->nativeCity?->name, $profile->nativeTaluka?->name, $profile->nativeDistrict?->name, $profile->nativeState?->name])) }}</p>
        </div>
    </section>
</div>
@endif

@if ($profile->siblings?->isNotEmpty())
@php
    $siblingsByGender = $profile->siblings->groupBy(function ($s) { return ($s->gender ?? 'other') ?: 'other'; });
@endphp
<div class="relative overflow-hidden rounded-2xl border border-stone-200/75 bg-gradient-to-br from-white via-indigo-50/25 to-violet-50/20 shadow-[0_14px_44px_-20px_rgba(79,70,229,0.12)] ring-1 ring-indigo-100/50 dark:border-indigo-900/25 dark:from-gray-900 dark:via-indigo-950/15 dark:to-gray-900">
    <div class="pointer-events-none absolute -right-12 -top-16 h-44 w-44 rounded-full bg-indigo-200/20 blur-3xl dark:bg-indigo-600/10" aria-hidden="true"></div>
    <section class="relative px-5 py-6 sm:px-7 sm:py-7" aria-labelledby="profile-siblings-detail-heading">
        <header class="mb-5 flex flex-wrap items-center gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-600 text-white shadow-md shadow-indigo-600/20 dark:from-indigo-600 dark:to-violet-900" aria-hidden="true">
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.09 9.09 0 003.741-.479 3 3 0 004.682-2.51V9.75a3 3 0 00-3-3h-5.379c-.133 0-.263.02-.382.059L12 6.75l-2.96 1.059A1.05 1.05 0 018.5 7.5H5.25a3 3 0 00-3 3v6.018a3 3 0 004.682 2.51 9.09 9.09 0 003.741.479m0 0a9.09 9.09 0 01-3.741-.479 3 3 0 01-4.682-2.51V9.75a3 3 0 013-3h3.379c.133 0 .263.02.382.059L12 6.75l2.96-1.059a1.05 1.05 0 01.382-.059H18a3 3 0 013 3v6.018a3 3 0 01-4.682 2.51 9.09 9.09 0 01-3.741.479"/></svg>
            </span>
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-indigo-700/90 dark:text-indigo-400/90">{{ __('profile.siblings_detail_section_kicker') }}</p>
                <h3 id="profile-siblings-detail-heading" class="text-lg font-semibold tracking-tight text-stone-900 dark:text-stone-50 sm:text-xl">{{ __('Siblings') }}</h3>
            </div>
        </header>
        <div class="space-y-5">
            @foreach($siblingsByGender as $gender => $items)
                <div>
                    <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ ucfirst($gender) }}</p>
                    <div class="space-y-2">
                        @foreach($items as $sib)
                            <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                                <p class="text-base font-semibold text-stone-900 dark:text-stone-100">
                                    {{ $sib->occupation ?: '—' }}{{ $sib->marital_status ? ' · '.ucfirst($sib->marital_status) : '' }}{{ $sib->city?->name ? ' · '.$sib->city->name : '' }}{{ $sib->notes ? ' · '.\Illuminate\Support\Str::limit($sib->notes, 80) : '' }}
                                </p>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </section>
</div>
@endif

@if ($profile->children?->isNotEmpty())
<div class="relative overflow-hidden rounded-2xl border border-stone-200/75 bg-gradient-to-br from-white via-rose-50/35 to-pink-50/20 shadow-[0_14px_44px_-20px_rgba(225,29,72,0.12)] ring-1 ring-rose-100/55 dark:border-rose-900/25 dark:from-gray-900 dark:via-rose-950/15 dark:to-gray-900">
    <section class="relative px-5 py-6 sm:px-7 sm:py-7" aria-labelledby="profile-children-heading">
        <header class="mb-5 flex flex-wrap items-center gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-rose-500 to-pink-600 text-white shadow-md shadow-rose-600/20 dark:from-rose-600 dark:to-pink-900" aria-hidden="true">
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.182 15.182a4.5 4.5 0 01-6.364 0M21 12a9 9 0 11-18 0 9 9 0 0118 0zM9.75 9.75c0 .414-.168.75-.375.75S9 10.164 9 9.75 9.168 9 9.375 9s.375.336.375.75zm-.375 0h.008v.015h-.008V9.75zm5.625 0c0 .414-.168.75-.375.75s-.375-.336-.375-.75.168-.75.375-.75.375.336.375.75zm-.375 0h.008v.015h-.008V9.75z"/></svg>
            </span>
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-rose-700/90 dark:text-rose-400/90">{{ __('profile.children_section_kicker') }}</p>
                <h3 id="profile-children-heading" class="text-lg font-semibold tracking-tight text-stone-900 dark:text-stone-50 sm:text-xl">{{ __('Children') }}</h3>
            </div>
        </header>
        <div class="space-y-2">
            @foreach($profile->children as $child)
                @php
                    $parts = [];
                    $parts[] = $child->child_name ?: __('Child');
                    if (!empty($child->age)) {
                        $parts[] = $child->age.' yrs';
                    }
                    if (!empty($child->gender)) {
                        $parts[] = strtolower((string) $child->gender);
                    }
                    if ($child->childLivingWith?->label) {
                        $parts[] = __('Living with').': '.$child->childLivingWith->label;
                    }
                @endphp
                <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                    <p class="text-base font-semibold text-stone-900 dark:text-stone-100">{{ implode(' · ', array_filter($parts)) }}</p>
                </div>
            @endforeach
        </div>
    </section>
</div>
@endif

@php
    $marriageDetailBlocks = [];
    if ($profile->marriages?->isNotEmpty()) {
        foreach ($profile->marriages as $marriageRow) {
            $marriageLines = array_filter([
                ($marriageRow->marriage_year ?? null) !== null && $marriageRow->marriage_year !== '' ? __('Marriage year').': '.$marriageRow->marriage_year : null,
                ($marriageRow->divorce_year ?? null) !== null && $marriageRow->divorce_year !== '' ? __('Divorce year').': '.$marriageRow->divorce_year : null,
                ($marriageRow->separation_year ?? null) !== null && $marriageRow->separation_year !== '' ? __('Separation year').': '.$marriageRow->separation_year : null,
                ($marriageRow->spouse_death_year ?? null) !== null && $marriageRow->spouse_death_year !== '' ? __('Spouse death year').': '.$marriageRow->spouse_death_year : null,
                ($marriageRow->divorce_status ?? '') !== '' ? __('Divorce status').': '.$marriageRow->divorce_status : null,
                ($marriageRow->remarriage_reason ?? '') !== '' ? __('Remarriage reason').': '.$marriageRow->remarriage_reason : null,
                ($marriageRow->notes ?? '') !== '' ? __('Notes').': '.$marriageRow->notes : null,
            ]);
            if (! empty($marriageLines)) {
                $marriageDetailBlocks[] = $marriageLines;
            }
        }
    }
@endphp
@if (! empty($marriageDetailBlocks))
<div class="relative overflow-hidden rounded-2xl border border-stone-200/75 bg-gradient-to-br from-white via-stone-50/80 to-rose-50/25 shadow-[0_14px_44px_-20px_rgba(190,24,93,0.12)] ring-1 ring-stone-100/70 dark:border-stone-700/80 dark:from-gray-900 dark:via-stone-900/90 dark:to-rose-950/20">
    <div class="pointer-events-none absolute -left-8 -top-10 h-36 w-36 rounded-full bg-rose-200/20 blur-3xl dark:bg-rose-600/10" aria-hidden="true"></div>
    <section class="relative px-5 py-6 sm:px-7 sm:py-7" aria-labelledby="profile-marriage-details-heading">
        <header class="mb-5 flex flex-wrap items-center gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-rose-500 to-rose-800 text-white shadow-md shadow-rose-600/20 dark:from-rose-600 dark:to-rose-950" aria-hidden="true">
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z"/></svg>
            </span>
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-rose-700/90 dark:text-rose-400/90">{{ __('profile.marriage_details_section_kicker') }}</p>
                <h3 id="profile-marriage-details-heading" class="text-lg font-semibold tracking-tight text-stone-900 dark:text-stone-50 sm:text-xl">{{ __('Marriage details') }}</h3>
            </div>
        </header>
        <div class="space-y-4">
            @foreach ($marriageDetailBlocks as $blockIndex => $marriageLines)
                <div class="rounded-xl border border-stone-100/95 bg-white/90 p-4 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                    <ul class="space-y-2.5">
                        @foreach ($marriageLines as $line)
                            <li class="flex gap-2 text-sm leading-relaxed text-stone-800 dark:text-stone-100">
                                <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-rose-400/90 dark:bg-rose-500" aria-hidden="true"></span>
                                <span class="font-medium">{{ $line }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                @if ($blockIndex < count($marriageDetailBlocks) - 1)
                    <div class="border-t border-dashed border-stone-200/90 dark:border-gray-600/80" aria-hidden="true"></div>
                @endif
            @endforeach
        </div>
    </section>
</div>
@endif

@if ($profile->educationHistory && $profile->educationHistory->isNotEmpty())
<div class="relative overflow-hidden rounded-2xl border border-stone-200/75 bg-gradient-to-br from-white via-sky-50/25 to-indigo-50/15 shadow-[0_14px_44px_-20px_rgba(59,130,246,0.1)] ring-1 ring-sky-100/55 dark:border-sky-900/20 dark:from-gray-900 dark:via-sky-950/10 dark:to-gray-900">
    <section class="relative px-5 py-6 sm:px-7 sm:py-7" aria-labelledby="profile-education-history-heading">
        <header class="mb-5 flex flex-wrap items-center gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-sky-500 to-indigo-600 text-white shadow-md shadow-sky-600/20 dark:from-sky-600 dark:to-indigo-900" aria-hidden="true">
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/></svg>
            </span>
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-sky-700/90 dark:text-sky-400/90">{{ __('profile.education_history_section_kicker') }}</p>
                <h3 id="profile-education-history-heading" class="text-lg font-semibold tracking-tight text-stone-900 dark:text-stone-50 sm:text-xl">{{ __('Education History') }}</h3>
            </div>
        </header>
        <ol class="relative space-y-3 border-l-2 border-sky-200/80 pl-4 dark:border-sky-800/60">
            @foreach($profile->educationHistory as $edu)
                <li class="relative rounded-xl border border-stone-100/95 bg-white/90 py-3 pl-4 pr-3 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                    <span class="absolute -left-[21px] top-4 flex h-2.5 w-2.5 rounded-full border-2 border-white bg-sky-500 shadow dark:border-gray-900 dark:bg-sky-400" aria-hidden="true"></span>
                    <p class="text-base font-semibold text-stone-900 dark:text-stone-100">{{ $edu->degree ?: '—' }}{{ $edu->specialization ? ' – '.$edu->specialization : '' }}{{ $edu->university ? ' ('.$edu->university.')' : '' }}{{ $edu->year_completed ? ', '.$edu->year_completed : '' }}</p>
                </li>
            @endforeach
        </ol>
    </section>
</div>
@endif

@if ($profile->career?->isNotEmpty())
<div class="relative overflow-hidden rounded-2xl border border-stone-200/75 bg-gradient-to-br from-white via-emerald-50/30 to-teal-50/15 shadow-[0_14px_44px_-20px_rgba(5,150,105,0.12)] ring-1 ring-emerald-100/55 dark:border-emerald-900/20 dark:from-gray-900 dark:via-emerald-950/10 dark:to-gray-900">
    <section class="relative px-5 py-6 sm:px-7 sm:py-7" aria-labelledby="profile-career-history-heading">
        <header class="mb-5 flex flex-wrap items-center gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-700 text-white shadow-md shadow-emerald-600/20 dark:from-emerald-600 dark:to-teal-900" aria-hidden="true">
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5 3v1.5M19 3v1.5m-13 9h3m-3 3h6m-3-6h6m-4 6h4.5M4.5 6.75h15M9.75 9.75c0 .621-.504 1.125-1.125 1.125H7.5m4.5 0c0 .621-.504 1.125-1.125 1.125H7.5m4.5 0c0 .621-.504 1.125-1.125 1.125H7.5"/></svg>
            </span>
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-emerald-700/90 dark:text-emerald-400/90">{{ __('profile.career_history_section_kicker') }}</p>
                <h3 id="profile-career-history-heading" class="text-lg font-semibold tracking-tight text-stone-900 dark:text-stone-50 sm:text-xl">{{ __('Career History') }}</h3>
            </div>
        </header>
        <ol class="relative space-y-3 border-l-2 border-emerald-200/80 pl-4 dark:border-emerald-800/60">
            @foreach($profile->career as $job)
                <li class="relative rounded-xl border border-stone-100/95 bg-white/90 py-3 pl-4 pr-3 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                    <span class="absolute -left-[21px] top-4 flex h-2.5 w-2.5 rounded-full border-2 border-white bg-emerald-500 shadow dark:border-gray-900 dark:bg-emerald-400" aria-hidden="true"></span>
                    <p class="text-base font-semibold text-stone-900 dark:text-stone-100">{{ $job->designation ?: '—' }}{{ $job->company ? ' at '.$job->company : '' }}{{ $job->start_year || $job->end_year ? ' ('.($job->start_year ?? '').'–'.($job->end_year ?? '').')' : '' }}</p>
                </li>
            @endforeach
        </ol>
    </section>
</div>
@endif

@if ($profile->addresses?->isNotEmpty())
<div class="relative overflow-hidden rounded-2xl border border-stone-200/75 bg-gradient-to-br from-white via-stone-50/70 to-stone-100/40 shadow-[0_14px_44px_-20px_rgba(120,113,108,0.12)] ring-1 ring-stone-100/80 dark:border-stone-700/80 dark:from-gray-900 dark:via-stone-900/90 dark:to-gray-900">
    <section class="relative px-5 py-6 sm:px-7 sm:py-7" aria-labelledby="profile-address-heading">
        <header class="mb-5 flex flex-wrap items-center gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-stone-500 to-stone-700 text-white shadow-md dark:from-stone-600 dark:to-stone-900" aria-hidden="true">
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
            </span>
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-600/90 dark:text-stone-400/90">{{ __('profile.address_section_kicker') }}</p>
                <h3 id="profile-address-heading" class="text-lg font-semibold tracking-tight text-stone-900 dark:text-stone-50 sm:text-xl">{{ __('Address') }}</h3>
            </div>
        </header>
        <div class="space-y-2">
            @foreach($profile->addresses as $addr)
                <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                    <p class="text-base font-semibold leading-relaxed text-stone-900 dark:text-stone-100">
                        {{ implode(', ', array_filter([
                            trim($addr->village?->name ?? ''),
                            $addr->city?->name,
                            $addr->taluka?->name,
                            $addr->district?->name,
                            $addr->state?->name,
                            $addr->country?->name,
                        ])) ?: '—' }}{{ trim($addr->postal_code ?? '') ? ' – '.$addr->postal_code : '' }}
                    </p>
                </div>
            @endforeach
        </div>
    </section>
</div>
@endif

@if (!empty($enableRelativesSection) && $profile->relatives?->isNotEmpty())
@php
    $relativesByType = $profile->relatives->groupBy(function ($r) { return $r->relation_type ?: 'Other'; });
    $relativeRelationLabels = [
        'paternal_uncle' => 'Paternal Uncle', 'wife_paternal_uncle' => 'Wife of Paternal Uncle',
        'paternal_aunt' => 'Paternal Aunt', 'husband_paternal_aunt' => 'Husband of Paternal Aunt',
        'maternal_uncle' => 'Maternal Uncle', 'wife_maternal_uncle' => 'Wife of Maternal Uncle',
        'maternal_aunt' => 'Maternal Aunt', 'husband_maternal_aunt' => 'Husband of Maternal Aunt',
        'Cousin' => 'Cousin',
        'paternal_grandfather' => 'Paternal Grandfather', 'paternal_grandmother' => 'Paternal Grandmother',
        'maternal_grandfather' => 'Maternal Grandfather', 'maternal_grandmother' => 'Maternal Grandmother',
        'great_uncle' => 'Great Uncle', 'great_aunt' => 'Great Aunt', 'other_grandparents_family' => 'Other (Grandparents\' family)',
        'maternal_cousin' => 'Cousin (maternal)', 'other_maternal' => 'Other (maternal)',
        'Uncle' => 'Uncle', 'Aunt' => 'Aunt', 'Grandfather' => 'Grandfather', 'Grandmother' => 'Grandmother', 'Other' => 'Other',
    ];
@endphp
<div class="relative overflow-hidden rounded-2xl border border-stone-200/75 bg-gradient-to-br from-white via-violet-50/30 to-fuchsia-50/15 shadow-[0_14px_44px_-20px_rgba(124,58,237,0.12)] ring-1 ring-violet-100/55 dark:border-violet-900/25 dark:from-gray-900 dark:via-violet-950/15 dark:to-gray-900">
    <div class="pointer-events-none absolute -right-10 -bottom-14 h-44 w-44 rounded-full bg-violet-200/20 blur-3xl dark:bg-violet-600/10" aria-hidden="true"></div>
    <section class="relative px-5 py-6 sm:px-7 sm:py-7" aria-labelledby="profile-relatives-heading">
        <header class="mb-5 flex flex-wrap items-center gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-violet-500 to-fuchsia-600 text-white shadow-md shadow-violet-600/20 dark:from-violet-600 dark:to-fuchsia-900" aria-hidden="true">
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.09 9.09 0 003.741-.479 3 3 0 004.682-2.51V9.75a3 3 0 00-3-3h-5.379c-.133 0-.263.02-.382.059L12 6.75l-2.96 1.059A1.05 1.05 0 018.5 7.5H5.25a3 3 0 00-3 3v6.018a3 3 0 004.682 2.51 9.09 9.09 0 003.741.479m0 0a9.09 9.09 0 01-3.741-.479 3 3 0 01-4.682-2.51V9.75a3 3 0 013-3h3.379c.133 0 .263.02.382.059L12 6.75l2.96-1.059a1.05 1.05 0 01.382-.059H18a3 3 0 013 3v6.018a3 3 0 01-4.682 2.51 9.09 9.09 0 01-3.741.479"/></svg>
            </span>
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-violet-700/90 dark:text-violet-400/90">{{ __('profile.relatives_section_kicker') }}</p>
                <h3 id="profile-relatives-heading" class="text-lg font-semibold tracking-tight text-stone-900 dark:text-stone-50 sm:text-xl">{{ __('Relatives & Family Network') }}</h3>
            </div>
        </header>
        <div class="space-y-6">
            @foreach($relativesByType as $relationType => $relatives)
                <div>
                    <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ $relativeRelationLabels[$relationType] ?? \Illuminate\Support\Str::title(str_replace('_', ' ', $relationType)) }}</p>
                    <div class="space-y-2">
                        @foreach($relatives as $rel)
                            <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                                <p class="text-base font-semibold leading-snug text-stone-900 dark:text-stone-100">
                                    {{ $rel->name ?: '—' }}{{ $rel->occupation ? ' · '.$rel->occupation : '' }}{{ ($rel->city?->name || $rel->state?->name) ? ' ('.trim(implode(', ', array_filter([$rel->city?->name, $rel->state?->name]))).')' : '' }}{{ $rel->contact_number ? ' · '.$rel->contact_number : '' }}{{ $rel->notes ? ' · '.\Illuminate\Support\Str::limit($rel->notes, 80) : '' }}
                                </p>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </section>
</div>
@endif

@if ($profile->allianceNetworks?->isNotEmpty())
@php
    $allianceByLocation = $profile->allianceNetworks->groupBy(function ($a) {
        $parts = array_filter([$a->city?->name, $a->taluka?->name, $a->district?->name, $a->state?->name]);
        return implode(', ', $parts) ?: 'Other';
    });
@endphp
<div class="relative overflow-hidden rounded-2xl border border-stone-200/75 bg-gradient-to-br from-white via-cyan-50/25 to-blue-50/20 shadow-[0_14px_44px_-20px_rgba(8,145,178,0.1)] ring-1 ring-cyan-100/50 dark:border-cyan-900/20 dark:from-gray-900 dark:via-cyan-950/15 dark:to-gray-900">
    <section class="relative px-5 py-6 sm:px-7 sm:py-7" aria-labelledby="profile-alliance-heading">
        <header class="mb-5 flex flex-wrap items-center gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-cyan-500 to-blue-600 text-white shadow-md dark:from-cyan-600 dark:to-blue-900" aria-hidden="true">
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3"/></svg>
            </span>
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-cyan-700/90 dark:text-cyan-400/90">{{ __('profile.alliance_section_kicker') }}</p>
                <h3 id="profile-alliance-heading" class="text-lg font-semibold tracking-tight text-stone-900 dark:text-stone-50 sm:text-xl">{{ __('Relatives & Native Network') }}</h3>
            </div>
        </header>
        <div class="space-y-5">
            @foreach($allianceByLocation as $locationLabel => $items)
                <div>
                    <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ $locationLabel }}</p>
                    <div class="space-y-2">
                        @foreach($items as $a)
                            <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                                <p class="text-base font-semibold text-stone-900 dark:text-stone-100">{{ $a->surname ?: '—' }}{{ $a->notes ? ' · '.\Illuminate\Support\Str::limit($a->notes, 80) : '' }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </section>
</div>
@endif

@php
    $hasPrefCriteria = isset($preferenceCriteria) && $preferenceCriteria;
    $hasAnyPrefs = false;
    if ($hasPrefCriteria) {
        $hasAnyPrefs = ($preferenceCriteria->preferred_city_id ?? null) !== null
            || ($preferenceCriteria->preferred_education ?? '') !== ''
            || ($preferenceCriteria->preferred_age_min ?? null) !== null
            || ($preferenceCriteria->preferred_age_max ?? null) !== null
            || ($preferenceCriteria->preferred_height_min_cm ?? null) !== null
            || ($preferenceCriteria->preferred_height_max_cm ?? null) !== null
            || ($preferenceCriteria->preferred_marital_status_id ?? null) !== null
            || ($preferenceCriteria->partner_profile_with_children ?? null) !== null
            || ($preferenceCriteria->preferred_income_min ?? null) !== null
            || ($preferenceCriteria->preferred_income_max ?? null) !== null;
    }
    $hasAnyPrefs = $hasAnyPrefs
        || ! empty($preferredReligionIds ?? [])
        || ! empty($preferredCasteIds ?? [])
        || ! empty($preferredDistrictIds ?? [])
        || ! empty($preferredMasterEducationIds ?? [])
        || ! empty($preferredDietIds ?? [])
        || ! empty($preferredProfessionIds ?? [])
        || ! empty($preferredWorkingWithTypeIds ?? []);
@endphp
@if ($hasAnyPrefs)
<div class="relative overflow-hidden rounded-2xl border border-stone-200/75 bg-gradient-to-br from-white via-rose-50/30 to-amber-50/20 shadow-[0_14px_44px_-20px_rgba(190,24,93,0.11)] ring-1 ring-rose-100/55 dark:border-rose-900/25 dark:from-gray-900 dark:via-rose-950/15 dark:to-gray-900">
    <div class="pointer-events-none absolute -left-12 top-0 h-40 w-40 rounded-full bg-rose-200/20 blur-3xl dark:bg-rose-600/10" aria-hidden="true"></div>
    <section class="relative px-5 py-6 sm:px-7 sm:py-7" aria-labelledby="profile-partner-prefs-heading">
        <header class="mb-5 flex flex-wrap items-center gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-rose-500 to-amber-600 text-white shadow-md shadow-rose-600/20 dark:from-rose-600 dark:to-amber-800" aria-hidden="true">
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z"/></svg>
            </span>
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-rose-700/90 dark:text-rose-400/90">{{ __('profile.partner_prefs_section_kicker') }}</p>
                <h3 id="profile-partner-prefs-heading" class="text-lg font-semibold tracking-tight text-stone-900 dark:text-stone-50 sm:text-xl">{{ __('Partner preferences') }}</h3>
            </div>
        </header>
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
        @if($hasPrefCriteria && ($preferenceCriteria->preferred_age_min ?? null) !== null || ($preferenceCriteria->preferred_age_max ?? null) !== null)
            <p><span class="text-gray-500">{{ __('Age:') }}</span> {{ $preferenceCriteria->preferred_age_min ?? '—' }}–{{ $preferenceCriteria->preferred_age_max ?? '—' }}</p>
        @endif
        @if($hasPrefCriteria && (($preferenceCriteria->preferred_height_min_cm ?? null) !== null || ($preferenceCriteria->preferred_height_max_cm ?? null) !== null))
            @php
                $hMin = (int) ($preferenceCriteria->preferred_height_min_cm ?? 0);
                $hMax = (int) ($preferenceCriteria->preferred_height_max_cm ?? 0);
            @endphp
            <p><span class="text-gray-500">{{ __('wizard.preferred_height_range') }}:</span>
                @if($hMin > 0 && $hMax > 0)
                    {{ \App\Support\HeightDisplay::formatCmRange($hMin, $hMax) }}
                @else
                    {{ $preferenceCriteria->preferred_height_min_cm ?? '—' }}–{{ $preferenceCriteria->preferred_height_max_cm ?? '—' }} cm
                @endif
            </p>
        @endif
        @if($hasPrefCriteria && ($preferenceCriteria->preferred_education ?? '') !== '')
            <p><span class="text-gray-500">{{ __('Education:') }}</span> {{ $preferenceCriteria->preferred_education }}</p>
        @endif
        @if($hasPrefCriteria && ($preferenceCriteria->preferred_city_id ?? null))
            @php $prefCityName = \App\Models\City::where('id', $preferenceCriteria->preferred_city_id)->value('name'); @endphp
            @if($prefCityName)<p><span class="text-gray-500">{{ __('City:') }}</span> {{ $prefCityName }}</p>@endif
        @endif
        @if($hasPrefCriteria && ($preferenceCriteria->preferred_marital_status_id ?? null))
            @php
                $prefMs = \App\Models\MasterMaritalStatus::where('id', $preferenceCriteria->preferred_marital_status_id)->first();
            @endphp
            @if($prefMs)
                <p><span class="text-gray-500">{{ __('wizard.marital_status_preference') }}:</span> {{ $prefMs->label }}</p>
            @endif
        @endif
        @if($hasPrefCriteria && in_array($preferenceCriteria->partner_profile_with_children ?? null, ['no', 'yes_if_live_separate', 'yes'], true))
            @php
                $pwc = $preferenceCriteria->partner_profile_with_children;
                $pwcLabel = match ($pwc) {
                    'no' => __('wizard.partner_children_no'),
                    'yes_if_live_separate' => __('wizard.partner_children_yes_if_live_separate'),
                    'yes' => __('wizard.partner_children_yes'),
                    default => $pwc,
                };
            @endphp
            <p><span class="text-gray-500">{{ __('wizard.profile_with_children_partner') }}:</span> {{ $pwcLabel }}</p>
        @endif
        @if(!empty($preferredReligionIds ?? []))
            @php $prefReligions = \App\Models\Religion::whereIn('id', $preferredReligionIds)->pluck('label')->all(); @endphp
            @if($prefReligions)<p><span class="text-gray-500">{{ __('Religions:') }}</span> {{ implode(', ', $prefReligions) }}</p>@endif
        @endif
        @if(!empty($preferredCasteIds ?? []))
            @php $prefCastes = \App\Models\Caste::whereIn('id', $preferredCasteIds)->pluck('label')->all(); @endphp
            @if($prefCastes)<p><span class="text-gray-500">{{ __('Castes:') }}</span> {{ implode(', ', $prefCastes) }}</p>@endif
        @endif
        @if(!empty($preferredDistrictIds ?? []))
            @php $prefDistricts = \App\Models\District::whereIn('id', $preferredDistrictIds)->pluck('name')->all(); @endphp
            @if($prefDistricts)<p><span class="text-gray-500">{{ __('Districts:') }}</span> {{ implode(', ', $prefDistricts) }}</p>@endif
        @endif
        @if($hasPrefCriteria && (($preferenceCriteria->preferred_income_min ?? null) !== null || ($preferenceCriteria->preferred_income_max ?? null) !== null))
            <p><span class="text-gray-500">{{ __('Preferred income') }}:</span> {{ $preferenceCriteria->preferred_income_min ?? '—' }} – {{ $preferenceCriteria->preferred_income_max ?? '—' }}</p>
        @endif
        @if(!empty($preferredMasterEducationIds ?? []))
            @php $prefMeLabels = \App\Models\MasterEducation::whereIn('id', $preferredMasterEducationIds)->orderBy('sort_order')->pluck('name')->filter()->values()->all(); @endphp
            @if($prefMeLabels)<p><span class="text-gray-500">{{ __('Preferred education level') }}:</span> {{ implode(', ', $prefMeLabels) }}</p>@endif
        @endif
        @if(!empty($preferredDietIds ?? []))
            @php $prefDietLabels = \App\Models\MasterDiet::whereIn('id', $preferredDietIds)->orderBy('sort_order')->pluck('label')->filter()->values()->all(); @endphp
            @if($prefDietLabels)<p><span class="text-gray-500">{{ __('Preferred diet') }}:</span> {{ implode(', ', $prefDietLabels) }}</p>@endif
        @endif
        @if(!empty($preferredProfessionIds ?? []))
            @php $prefProfLabels = \App\Models\Profession::whereIn('id', $preferredProfessionIds)->orderBy('sort_order')->pluck('name')->filter()->values()->all(); @endphp
            @if($prefProfLabels)<p><span class="text-gray-500">{{ __('Preferred profession') }}:</span> {{ implode(', ', $prefProfLabels) }}</p>@endif
        @endif
        @if(!empty($preferredWorkingWithTypeIds ?? []))
            @php $prefWwtLabels = \App\Models\WorkingWithType::whereIn('id', $preferredWorkingWithTypeIds)->orderBy('sort_order')->pluck('name')->filter()->values()->all(); @endphp
            @if($prefWwtLabels)<p><span class="text-gray-500">{{ __('Preferred working with') }}:</span> {{ implode(', ', $prefWwtLabels) }}</p>@endif
        @endif
        </div>
    </section>
</div>
@endif

@if ($detailExpectations !== '')
<div class="relative overflow-hidden rounded-2xl border border-stone-200/75 bg-gradient-to-br from-white via-amber-50/25 to-rose-50/20 shadow-[0_14px_44px_-20px_rgba(190,24,93,0.1)] ring-1 ring-amber-100/50 dark:border-amber-900/20 dark:from-gray-900 dark:via-amber-950/10 dark:to-rose-950/15">
    <section class="relative px-5 py-6 sm:px-7 sm:py-7" aria-labelledby="profile-expectations-only-heading">
        <header class="mb-4 flex flex-wrap items-center gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-amber-500 to-rose-600 text-white shadow-md dark:from-amber-600 dark:to-rose-900" aria-hidden="true">
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z"/></svg>
            </span>
            <h3 id="profile-expectations-only-heading" class="text-lg font-semibold tracking-tight text-stone-900 dark:text-stone-50 sm:text-xl">{{ __('Expectations') }}</h3>
        </header>
        <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-4 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
            <p class="text-[15px] font-medium leading-relaxed whitespace-pre-wrap text-stone-800 dark:text-stone-100">{{ $extendedAttributes->narrative_expectations }}</p>
        </div>
    </section>
</div>
@endif

@if ($profile->horoscope && ($profile->horoscope->rashi_id || $profile->horoscope->nakshatra_id || $profile->horoscope->gan_id || $profile->horoscope->nadi_id || $profile->horoscope->mangal_dosh_type_id || $profile->horoscope->yoni_id || $profile->horoscope->charan || $profile->horoscope->devak || $profile->horoscope->kul || $profile->horoscope->gotra || $profile->horoscope->navras_name || $profile->horoscope->birth_weekday))
<div class="relative overflow-hidden rounded-2xl border border-stone-200/75 bg-gradient-to-br from-white via-indigo-50/35 to-purple-50/25 shadow-[0_14px_44px_-20px_rgba(79,70,229,0.14)] ring-1 ring-indigo-100/55 dark:border-indigo-900/30 dark:from-gray-900 dark:via-indigo-950/20 dark:to-purple-950/20">
    <div class="pointer-events-none absolute -right-16 -top-20 h-52 w-52 rounded-full bg-indigo-200/25 blur-3xl dark:bg-indigo-600/10" aria-hidden="true"></div>
    <div class="pointer-events-none absolute -bottom-10 -left-10 h-40 w-40 rounded-full bg-purple-200/20 blur-3xl dark:bg-purple-600/10" aria-hidden="true"></div>
    <section class="relative px-5 py-6 sm:px-7 sm:py-7" aria-labelledby="profile-horoscope-heading">
        <header class="mb-5 flex flex-wrap items-center gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-700 text-white shadow-md shadow-indigo-600/25 dark:from-indigo-600 dark:to-purple-900" aria-hidden="true">
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z"/></svg>
            </span>
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-indigo-700/90 dark:text-indigo-400/90">{{ __('profile.horoscope_section_kicker') }}</p>
                <h3 id="profile-horoscope-heading" class="text-lg font-semibold tracking-tight text-stone-900 dark:text-stone-50 sm:text-xl">{{ __('Horoscope') }}</h3>
            </div>
        </header>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            @if ($profile->horoscope->rashi)
                <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">Rashi</p>
                    <p class="mt-1 text-base font-semibold text-stone-900 dark:text-stone-100">{{ $profile->horoscope->rashi->label ?? '—' }}</p>
                </div>
            @endif
            @if ($profile->horoscope->nakshatra)
                <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">Nakshatra</p>
                    <p class="mt-1 text-base font-semibold text-stone-900 dark:text-stone-100">{{ $profile->horoscope->nakshatra->label ?? '—' }}</p>
                </div>
            @endif
            @if ($profile->horoscope->gan)
                <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">Gan</p>
                    <p class="mt-1 text-base font-semibold text-stone-900 dark:text-stone-100">{{ $profile->horoscope->gan->label ?? '—' }}</p>
                </div>
            @endif
            @if ($profile->horoscope->nadi)
                <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">Nadi</p>
                    <p class="mt-1 text-base font-semibold text-stone-900 dark:text-stone-100">{{ $profile->horoscope->nadi->label ?? '—' }}</p>
                </div>
            @endif
            @if ($profile->horoscope->mangalDoshType)
                <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">Mangal Dosh</p>
                    <p class="mt-1 text-base font-semibold text-stone-900 dark:text-stone-100">{{ $profile->horoscope->mangalDoshType->label ?? '—' }}</p>
                </div>
            @endif
            @if ($profile->horoscope->yoni)
                <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">Yoni</p>
                    <p class="mt-1 text-base font-semibold text-stone-900 dark:text-stone-100">{{ $profile->horoscope->yoni->label ?? '—' }}</p>
                </div>
            @endif
            @if ($profile->horoscope->charan)
                <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">Charan</p>
                    <p class="mt-1 text-base font-semibold text-stone-900 dark:text-stone-100">{{ $profile->horoscope->charan }}</p>
                </div>
            @endif
            @if ($profile->horoscope->devak)
                <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">Devak</p>
                    <p class="mt-1 text-base font-semibold text-stone-900 dark:text-stone-100">{{ $profile->horoscope->devak }}</p>
                </div>
            @endif
            @if ($profile->horoscope->kul)
                <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">Kul</p>
                    <p class="mt-1 text-base font-semibold text-stone-900 dark:text-stone-100">{{ $profile->horoscope->kul }}</p>
                </div>
            @endif
            @if ($profile->horoscope->gotra)
                <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">Gotra</p>
                    <p class="mt-1 text-base font-semibold text-stone-900 dark:text-stone-100">{{ $profile->horoscope->gotra }}</p>
                </div>
            @endif
            @if ($profile->horoscope->navras_name)
                <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55 sm:col-span-2">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">Navras नाव</p>
                    <p class="mt-1 text-base font-semibold text-stone-900 dark:text-stone-100">{{ $profile->horoscope->navras_name }}</p>
                </div>
            @endif
            @if ($profile->horoscope->birth_weekday)
                <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">जन्मवार</p>
                    <p class="mt-1 text-base font-semibold text-stone-900 dark:text-stone-100">{{ $profile->horoscope->birth_weekday }}</p>
                </div>
            @endif
        </div>
    </section>
</div>
@endif

@if (auth()->check())
    <div id="contact-usage-strip" class="mb-4 rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-600 dark:bg-gray-800/50">
        @if ($isUnlimited)
            <p class="text-sm text-gray-800 dark:text-gray-200">
                {{ __('profile.usage_contacts_used_line', ['used' => $contactUsed, 'limit' => '∞']) }}
            </p>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                {{ __('profile.usage_contacts_remaining_unlimited') }}
            </p>
        @else
            <p class="text-sm {{ (is_numeric($contactLimit) && $contactLimit > 0 && is_numeric($contactRemaining) && $contactRemaining <= 2) ? 'font-semibold text-amber-800 dark:text-amber-200' : 'text-gray-800 dark:text-gray-200' }}">
                {{ __('profile.usage_contacts_used_line', ['used' => $contactUsed, 'limit' => $contactLimit]) }}
            </p>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                {{ __('profile.usage_contacts_remaining_line', ['remaining' => $contactRemaining]) }}
            </p>
            @if (is_numeric($contactLimit) && $contactLimit > 0 && is_numeric($contactRemaining) && $contactRemaining <= 2)
                <p class="mt-2 text-xs text-amber-700 dark:text-amber-300">
                    {{ __('profile.usage_contacts_low_warning') }}
                </p>
            @endif
        @endif
    </div>
@endif

<div id="profile-contact-panel" class="relative overflow-hidden rounded-2xl border border-stone-200/75 bg-gradient-to-br from-white via-stone-50/80 to-emerald-50/20 shadow-[0_14px_44px_-20px_rgba(5,150,105,0.1)] ring-1 ring-stone-100/70 dark:border-stone-700/80 dark:from-gray-900 dark:via-stone-900/90 dark:to-emerald-950/15">
    <section class="relative px-5 py-6 sm:px-7 sm:py-7" aria-labelledby="profile-contact-heading">
        <header class="mb-4 flex flex-wrap items-center gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-700 text-white shadow-md shadow-emerald-600/20 dark:from-emerald-600 dark:to-teal-900" aria-hidden="true">
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg>
            </span>
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-emerald-700/90 dark:text-emerald-400/90">{{ __('profile.contact_section_kicker') }}</p>
                <h3 id="profile-contact-heading" class="text-lg font-semibold tracking-tight text-stone-900 dark:text-stone-50 sm:text-xl">{{ __('Contact Information') }}</h3>
            </div>
        </header>
        <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
            @if ($isOwnProfile)
                <div class="text-base font-semibold tracking-tight text-stone-900 dark:text-stone-100">
                    @if ($primaryContactPhone)
                        {{ $primaryContactPhone }}
                    @else
                        {{ __('No contact number added.') }}
                    @endif
                </div>
            @else
                <div id="profile-contact-reveal-root">
                @php
                    $contactMaskedDigits = preg_replace('/\D/', '', (string) ($profile->primary_contact_number ?? $profile->phone ?? ''));
                    $masked = strlen($contactMaskedDigits) >= 4
                        ? substr($contactMaskedDigits, 0, 4) . 'XXXX'
                        : 'XXXX';
                @endphp

                {{-- CASE 1: Already unlocked (paid reveal / grant) — phone + email per {@see ContactAccessService::resolveViewerContext} --}}
                @if (! empty($contactAccess['paid_contact_phone']) || ! empty($contactAccess['paid_contact_email']))
                    <div class="text-center space-y-2">
                        @if (! empty($contactAccess['paid_contact_phone']))
                            <p class="text-xl font-bold text-stone-900 dark:text-stone-100">
                                {{ $contactAccess['paid_contact_phone'] }}
                            </p>
                        @endif
                        @if (! empty($contactAccess['paid_contact_email']))
                            <p class="text-sm text-stone-800 dark:text-stone-200 break-all">
                                <span class="text-stone-500 dark:text-stone-400">{{ __('Email') }}:</span>
                                {{ $contactAccess['paid_contact_email'] }}
                            </p>
                        @endif
                        <p class="text-green-600 text-sm font-medium dark:text-green-400">
                            {{ __('contact_access.contact_unlocked_banner') }}
                        </p>
                    </div>
                @elseif (($contactAccess['needs_upgrade'] ?? false))
                    <div class="text-center">
                        <p class="text-xl font-bold tracking-wider text-stone-900 dark:text-stone-100">
                            {{ $masked }} <span class="text-stone-400" aria-hidden="true">🔒</span>
                        </p>
                        <p class="text-sm text-gray-500 mt-2 dark:text-gray-400">
                            {{ __('contact_access.unlock_required') }}
                        </p>
                        <a href="{{ route('plans.index') }}"
                            class="mt-3 inline-flex items-center justify-center rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900">
                            {{ __('contact_access.upgrade_plan_button') }}
                        </a>
                    </div>
                @elseif (($contactAccess['show_no_one_copy'] ?? false))
                    <div class="text-center">
                        <p class="text-xl font-bold tracking-wider text-stone-900 dark:text-stone-100">
                            {{ $masked }} <span class="text-stone-400" aria-hidden="true">🔒</span>
                        </p>
                        <p class="text-sm text-gray-600 mt-2 dark:text-gray-400">
                            {{ __('contact_access.owner_restricted_contact') }}
                        </p>
                    </div>
                @elseif (($contactAccess['paid_reveal_blocked_pending_matchmaking'] ?? false))
                    <div class="text-center">
                        <p class="text-xl font-bold tracking-wider text-stone-900 dark:text-stone-100">
                            {{ $masked }} <span class="text-stone-400" aria-hidden="true">🔒</span>
                        </p>
                        <p class="text-sm text-amber-800 mt-2 dark:text-amber-200">
                            {{ __('contact_access.reveal_blocked_matchmaking') }}
                        </p>
                    </div>
                @elseif (($contactAccess['reveal_blocked_reason'] ?? null) === 'interest')
                    <div class="text-center">
                        <p class="text-xl font-bold tracking-wider text-stone-900 dark:text-stone-100">
                            {{ $masked }} <span class="text-stone-400" aria-hidden="true">🔒</span>
                        </p>
                        <p class="text-sm text-gray-600 mt-2 dark:text-gray-400">
                            {{ __('contact_access.reveal_blocked_interest_ui') }}
                        </p>
                    </div>
                @elseif (($contactAccess['reveal_blocked_reason'] ?? null) === 'premium')
                    <div class="text-center">
                        <p class="text-xl font-bold tracking-wider text-stone-900 dark:text-stone-100">
                            {{ $masked }} <span class="text-stone-400" aria-hidden="true">🔒</span>
                        </p>
                        <p class="text-sm text-gray-600 mt-2 dark:text-gray-400">
                            {{ __('contact_access.reveal_blocked_premium_ui') }}
                        </p>
                        <a href="{{ route('plans.index') }}"
                            class="mt-3 inline-flex items-center justify-center rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700">
                            {{ __('contact_access.upgrade_plan_button') }}
                        </a>
                    </div>
                @elseif (($contactAccess['reveal_blocked_reason'] ?? null) === 'no_contact')
                    <div class="text-center text-sm text-gray-600 dark:text-gray-400">
                        {{ __('contact_access.no_contact_on_profile') }}
                    </div>
                {{-- CASE: Paid reveal allowed + quota — same rules as POST {@see ProfileContactActionController::revealContact} --}}
                @elseif (($contactAccess['show_paid_reveal_button'] ?? false) && ($canUseContact ?? false))
                    <form id="contact-reveal-form"
                        method="POST"
                        action="{{ route('matrimony.profile.contact-reveal', $profile) }}"
                        class="text-center"
                        data-label-unlocked-banner="{{ e(__('contact_access.contact_unlocked_banner')) }}"
                        data-label-email-prefix="{{ e(__('Email')) }}">
                        @csrf
                        <p class="text-xl font-bold tracking-wider text-stone-900 dark:text-stone-100">
                            {{ $masked }} <span class="text-stone-400" aria-hidden="true">🔒</span>
                        </p>
                        <button type="submit"
                            class="mt-3 px-5 py-2 bg-pink-500 text-white rounded-lg hover:bg-pink-600 dark:bg-pink-600 dark:hover:bg-pink-500 font-medium disabled:opacity-60 disabled:cursor-not-allowed">
                            {{ __('contact_access.view_contact_button') }}
                        </button>
                    </form>
                @elseif ($canUseContact ?? false)
                    <div class="text-center">
                        <p class="text-xl font-bold tracking-wider text-stone-900 dark:text-stone-100">
                            {{ $masked }} <span class="text-stone-400" aria-hidden="true">🔒</span>
                        </p>
                        <p class="text-sm text-gray-500 mt-2 dark:text-gray-400">
                            {{ __('contact_access.reveal_not_allowed') }}
                        </p>
                    </div>
                @else
                    <div class="text-center">
                        <p class="text-xl font-bold tracking-wider text-stone-900 dark:text-stone-100">
                            {{ $masked }} <span class="text-stone-400" aria-hidden="true">🔒</span>
                        </p>
                        <p class="text-sm text-gray-500 mt-2 dark:text-gray-400">
                            {{ __('contact_access.no_contact_credits_left_ui') }}
                        </p>
                        <a href="{{ route('plans.index') }}"
                            class="mt-3 inline-flex items-center justify-center rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900">
                            {{ __('contact_access.upgrade_plan_button') }}
                        </a>
                    </div>
                @endif
                </div>{{-- #profile-contact-reveal-root --}}
            @endif
        </div>
    </section>
</div>

@if (! $isOwnProfile && auth()->check())
    <div class="mt-4 rounded-xl border border-stone-200/90 bg-white/90 px-4 py-4 shadow-sm dark:border-gray-700 dark:bg-gray-800/60">
        <p class="text-sm font-medium text-stone-800 dark:text-stone-100">{{ __('profile.monetization_send_interest_or_message') }}</p>
        @if ($userId === null || ! $featureUsage->canUse((int) $userId, 'chat_send_limit'))
            <div class="mt-3 text-center">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    You’ve reached your chat limit
                </p>
                <a href="{{ route('plans.index') }}"
                   class="mt-2 inline-block rounded bg-red-500 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-red-600">
                    Upgrade to continue chatting
                </a>
            </div>
        @else
            <form method="POST" action="{{ route('chat.start', ['matrimony_profile' => $profile->id]) }}" class="mt-3 inline-block">
                @csrf
                <button type="submit" class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900">
                    {{ __('profile.monetization_send_message') }}
                </button>
            </form>
        @endif
    </div>
@endif

@if(!empty($extendedValues))
    @php
        $filteredExtended = array_filter($extendedValues, function($v) {
            return $v !== null && $v !== '';
        });
    @endphp

    @if(!empty($filteredExtended))
        <div class="relative overflow-hidden rounded-2xl border border-stone-200/75 bg-gradient-to-br from-white via-stone-50/60 to-rose-50/15 shadow-[0_14px_44px_-20px_rgba(190,24,93,0.08)] ring-1 ring-stone-100/70 dark:border-stone-700/80 dark:from-gray-900 dark:via-stone-900/95 dark:to-rose-950/15">
            <section class="relative px-5 py-6 sm:px-7 sm:py-7" aria-labelledby="profile-additional-heading">
                <header class="mb-5 flex flex-wrap items-center gap-3">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-rose-400 to-rose-700 text-white shadow-md dark:from-rose-600 dark:to-rose-900" aria-hidden="true">
                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
                    </span>
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-rose-700/90 dark:text-rose-400/90">{{ __('profile.additional_section_kicker') }}</p>
                        <h3 id="profile-additional-heading" class="text-lg font-semibold tracking-tight text-stone-900 dark:text-stone-50 sm:text-xl">{{ __('Additional Details') }}</h3>
                    </div>
                </header>
                <div class="space-y-3">
                    @foreach($filteredExtended as $label => $value)
                        @php
                            // Some extended fields store multi-values (arrays). Escaping raw arrays crashes rendering.
                            $displayValue = $value;
                            if (is_array($displayValue)) {
                                $parts = [];
                                foreach ($displayValue as $v) {
                                    if ($v === null || $v === '') {
                                        continue;
                                    }
                                    $parts[] = is_scalar($v) ? (string) $v : json_encode($v);
                                }
                                $displayValue = implode(', ', $parts);
                            } elseif (is_object($displayValue)) {
                                $displayValue = method_exists($displayValue, '__toString') ? (string) $displayValue : json_encode($displayValue);
                            }
                        @endphp
                        <div class="rounded-xl border border-stone-100/95 bg-white/90 px-4 py-3.5 shadow-sm dark:border-gray-700/70 dark:bg-gray-800/55">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ $extendedMeta[$label] ?? $label }}</p>
                            <p class="mt-1 text-base font-semibold text-stone-900 dark:text-stone-100">{{ $displayValue }}</p>
                        </div>
                    @endforeach
                </div>
            </section>
        </div>
    @endif
@endif

{{-- Visual Divider --}}
@if (!$isOwnProfile)
<hr class="my-8 border-gray-300 dark:border-gray-600">
@endif

{{-- Match Explanation Section --}}
@if (!$isOwnProfile)
@php
    $matchData = $matchData ?? null;
    // Ensure matchData exists, create empty structure if not
    if (!$matchData) {
        $matchData = [
            'matches' => [],
            'commonGround' => [],
            'matchedCount' => 0,
            'totalCount' => 0,
            'summaryText' => 'या प्रोफाइलशी काही बाबतीत साम्य आहे',
            'celebrationText' => null,
        ];
    }
    // Get logged-in user's profile photo for comparison
    $viewerProfile = auth()->user()->matrimonyProfile ?? null;
    $viewerPhotoSrc = null;
    if ($viewerProfile && $viewerProfile->profile_photo && $viewerProfile->photo_approved !== false) {
        $viewerPhotoSrc = asset('uploads/matrimony_photos/'.$viewerProfile->profile_photo);
    } else {
        $viewerGender = $viewerProfile->gender ?? auth()->user()->gender ?? null;
        if ($viewerGender === 'male') {
            $viewerPhotoSrc = asset('images/placeholders/male-profile.svg');
        } elseif ($viewerGender === 'female') {
            $viewerPhotoSrc = asset('images/placeholders/female-profile.svg');
        } else {
            $viewerPhotoSrc = asset('images/placeholders/default-profile.svg');
        }
    }
    // Get viewed profile photo for comparison
    $viewedPhotoSrc = null;
    if ($profile->profile_photo && $profile->photo_approved !== false) {
        $viewedPhotoSrc = asset('uploads/matrimony_photos/'.$profile->profile_photo);
    } else {
        $viewedGender = $profile->gender?->key ?? $profile->gender;
        if ($viewedGender === 'male') {
            $viewedPhotoSrc = asset('images/placeholders/male-profile.svg');
        } elseif ($viewedGender === 'female') {
            $viewedPhotoSrc = asset('images/placeholders/female-profile.svg');
        } else {
            $viewedPhotoSrc = asset('images/placeholders/default-profile.svg');
        }
    }
@endphp
<div class="mt-8 mb-8 rounded-xl border border-stone-200/90 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900/95">
    @php
        $viewedGenderKey = strtolower((string) ($profile->gender?->key ?? $profile->gender ?? ''));
        $preferenceSideLabel = $viewedGenderKey === 'female'
            ? 'Her preference'
            : ($viewedGenderKey === 'male' ? 'His preference' : 'Preferred');
        $yourSideLabel = 'Your profile';

        $safeAge = function ($dob): ?int {
            if (empty($dob)) {
                return null;
            }
            try {
                $age = \Carbon\Carbon::parse($dob)->age;
                if (! is_numeric($age)) {
                    return null;
                }
                $age = (int) floor((float) $age);
                return $age >= 0 ? $age : null;
            } catch (\Throwable) {
                return null;
            }
        };

        $viewerAge = $safeAge($viewerProfile?->date_of_birth ?? null);
        $viewedAge = $safeAge($profile->date_of_birth ?? null);
        $viewerLocation = implode(', ', array_filter([$viewerProfile?->city?->name, $viewerProfile?->state?->name]));
        $viewedLocation = implode(', ', array_filter([$profile->city?->name, $profile->state?->name]));
        $rowGroups = [];

        $addRow = function (string $group, string $label, string $their, string $yours, string $status, string $note = '') use (&$rowGroups): void {
            if (! isset($rowGroups[$group])) {
                $rowGroups[$group] = [];
            }
            $rowGroups[$group][] = [
                'label' => $label,
                'their' => $their !== '' ? $their : 'Not specified',
                'yours' => $yours !== '' ? $yours : 'Not specified',
                'status' => $status,
                'note' => $note,
            ];
        };

        // Basic fit
        if ($viewedAge !== null || $viewerAge !== null) {
            $ageDiff = ($viewedAge !== null && $viewerAge !== null) ? abs($viewedAge - $viewerAge) : null;
            $ageStatus = ($ageDiff === null)
                ? 'open'
                : ($ageDiff <= 5 ? 'match' : ($ageDiff <= 8 ? 'close' : 'mismatch'));
            $ageNote = ($viewedAge !== null && $viewerAge !== null) ? "You are {$viewerAge} years; profile age is {$viewedAge} years" : '';
            $addRow('Basic fit', 'Age', $viewedAge !== null ? (string) $viewedAge : '', $viewerAge !== null ? (string) $viewerAge : '', $ageStatus, $ageNote);
        }
        if (($profile->maritalStatus?->label ?? '') !== '' || ($viewerProfile?->maritalStatus?->label ?? '') !== '') {
            $their = (string) ($profile->maritalStatus?->label ?? '');
            $yours = (string) ($viewerProfile?->maritalStatus?->label ?? '');
            $status = ($their !== '' && $yours !== '') ? (strcasecmp($their, $yours) === 0 ? 'match' : 'mismatch') : 'open';
            $addRow('Basic fit', 'Marital status', $their, $yours, $status);
        }
        if (($profile->height_cm ?? null) || ($viewerProfile?->height_cm ?? null)) {
            $their = ($profile->height_cm ?? null) ? ((string) $profile->height_cm.' cm') : '';
            $yours = ($viewerProfile?->height_cm ?? null) ? ((string) $viewerProfile->height_cm.' cm') : '';
            $heightDiff = ($their !== '' && $yours !== '') ? abs((int) $profile->height_cm - (int) $viewerProfile->height_cm) : null;
            $status = ($heightDiff === null) ? 'open' : ($heightDiff <= 8 ? 'match' : ($heightDiff <= 12 ? 'close' : 'open'));
            $addRow('Basic fit', 'Height', $their, $yours, $status);
        }

        // Community & background
        $theirReligion = (string) ($profile->religion?->label ?? '');
        $yourReligion = (string) ($viewerProfile?->religion?->label ?? '');
        if ($theirReligion !== '' || $yourReligion !== '') {
            $status = ($theirReligion !== '' && $yourReligion !== '') ? (strcasecmp($theirReligion, $yourReligion) === 0 ? 'match' : 'mismatch') : 'open';
            $addRow('Community & background', 'Religion', $theirReligion, $yourReligion, $status);
        }
        $theirCaste = (string) ($profile->caste?->label ?? '');
        $yourCaste = (string) ($viewerProfile?->caste?->label ?? '');
        if ($theirCaste !== '' || $yourCaste !== '') {
            $status = ($theirCaste !== '' && $yourCaste !== '') ? (strcasecmp($theirCaste, $yourCaste) === 0 ? 'match' : 'mismatch') : 'open';
            $addRow('Community & background', 'Caste', $theirCaste, $yourCaste, $status);
        }
        $theirSubCaste = (string) ($profile->subCaste?->label ?? '');
        $yourSubCaste = (string) ($viewerProfile?->subCaste?->label ?? '');
        if ($theirSubCaste !== '' || $yourSubCaste !== '') {
            $status = ($theirSubCaste !== '' && $yourSubCaste !== '') ? (strcasecmp($theirSubCaste, $yourSubCaste) === 0 ? 'match' : 'open') : 'open';
            $addRow('Community & background', 'Sub-caste', $theirSubCaste, $yourSubCaste, $status);
        }
        $theirMotherTongue = (string) ($profile->motherTongue?->label ?? '');
        $yourMotherTongue = (string) ($viewerProfile?->motherTongue?->label ?? '');
        if ($theirMotherTongue !== '' || $yourMotherTongue !== '') {
            $status = ($theirMotherTongue !== '' && $yourMotherTongue !== '') ? (strcasecmp($theirMotherTongue, $yourMotherTongue) === 0 ? 'match' : 'open') : 'open';
            $addRow('Community & background', 'Mother tongue', $theirMotherTongue, $yourMotherTongue, $status);
        }

        // Career & location
        $theirEducation = trim((string) ($profile->highest_education ?? ''));
        $yourEducation = trim((string) ($viewerProfile?->highest_education ?? ''));
        if ($theirEducation !== '' || $yourEducation !== '') {
            $status = ($theirEducation !== '' && $yourEducation !== '') ? (strcasecmp($theirEducation, $yourEducation) === 0 ? 'match' : 'mismatch') : 'open';
            $addRow('Career & location', 'Education', $theirEducation, $yourEducation, $status);
        }
        $theirOccupation = trim((string) (($profile->occupation_title ?? '') !== '' ? $profile->occupation_title : ($profile->profession?->name ?? '')));
        $yourOccupation = trim((string) (($viewerProfile?->occupation_title ?? '') !== '' ? $viewerProfile->occupation_title : ($viewerProfile?->profession?->name ?? '')));
        if ($theirOccupation !== '' || $yourOccupation !== '') {
            $status = ($theirOccupation !== '' && $yourOccupation !== '') ? (strcasecmp($theirOccupation, $yourOccupation) === 0 ? 'match' : 'open') : 'open';
            $addRow('Career & location', 'Occupation', $theirOccupation, $yourOccupation, $status);
        }
        if ($viewedLocation !== '' || $viewerLocation !== '') {
            $sameCity = ($profile->city_id && $viewerProfile?->city_id) ? ((int) $profile->city_id === (int) $viewerProfile->city_id) : false;
            $sameState = ($profile->state_id && $viewerProfile?->state_id) ? ((int) $profile->state_id === (int) $viewerProfile->state_id) : false;
            $status = $sameCity ? 'match' : ($sameState ? 'close' : (($viewedLocation !== '' && $viewerLocation !== '') ? 'mismatch' : 'open'));
            $note = $sameCity ? 'Lives in the same city' : ($sameState ? 'Lives in the same state' : '');
            $addRow('Career & location', 'Location', $viewedLocation, $viewerLocation, $status, $note);
        }

        // Lifestyle & family
        $theirDiet = (string) ($profile->diet?->label ?? '');
        $yourDiet = (string) ($viewerProfile?->diet?->label ?? '');
        if ($theirDiet !== '' || $yourDiet !== '') {
            $status = ($theirDiet !== '' && $yourDiet !== '') ? (strcasecmp($theirDiet, $yourDiet) === 0 ? 'match' : 'open') : 'open';
            $addRow('Lifestyle & family', 'Diet', $theirDiet, $yourDiet, $status);
        }
        $theirFamilyType = (string) ($profile->familyType?->label ?? '');
        $yourFamilyType = (string) ($viewerProfile?->familyType?->label ?? '');
        if ($theirFamilyType !== '' || $yourFamilyType !== '') {
            $status = ($theirFamilyType !== '' && $yourFamilyType !== '') ? (strcasecmp($theirFamilyType, $yourFamilyType) === 0 ? 'match' : 'open') : 'open';
            $addRow('Lifestyle & family', 'Family type', $theirFamilyType, $yourFamilyType, $status);
        }

        $allRows = [];
        foreach ($rowGroups as $rows) {
            foreach ($rows as $r) {
                $allRows[] = $r;
            }
        }
        $statusCounts = ['match' => 0, 'close' => 0, 'mismatch' => 0, 'open' => 0];
        foreach ($allRows as $r) {
            $statusCounts[$r['status']]++;
        }
        $chipPriority = ['location', 'age', 'highest_education', 'marital_status_id', 'caste_id'];
        $chipMap = [];
        foreach ($matchData['matches'] as $m) {
            $statusLabel = $m['matched'] ? 'Aligned' : 'Different';
            $chipMap[$m['field']] = [
                'label' => $m['label'],
                'tone' => $m['matched'] ? 'match' : 'mismatch',
                'status' => $statusLabel,
            ];
        }
        $smartChips = [];
        foreach ($chipPriority as $f) {
            if (isset($chipMap[$f])) {
                $smartChips[] = $chipMap[$f];
            }
        }
        $smartChips = array_slice($smartChips, 0, 5);
    @endphp

    <div class="mb-4 flex items-center justify-between gap-4 border-b border-stone-200 pb-4 dark:border-gray-700">
        <div>
            <h3 class="text-lg font-semibold text-stone-900 dark:text-stone-100">{{ __('How does your profile match with theirs?') }}</h3>
            <p class="text-sm text-stone-600 dark:text-stone-400">Comparison based on shared profile information (no score).</p>
        </div>
        <div class="hidden items-center gap-3 sm:flex">
            <div class="text-center">
                <img src="{{ $viewedPhotoSrc }}" alt="{{ __('Viewed Profile') }}" class="mx-auto h-14 w-14 rounded-full object-cover ring-2 ring-stone-300 dark:ring-gray-600" />
                <p class="mt-1 text-[11px] font-medium text-stone-600 dark:text-stone-300">{{ $preferenceSideLabel }}</p>
            </div>
            <span class="text-stone-400">vs</span>
            <div class="text-center">
                <img src="{{ $viewerPhotoSrc }}" alt="{{ __('Your Profile') }}" class="mx-auto h-14 w-14 rounded-full object-cover ring-2 ring-stone-300 dark:ring-gray-600" />
                <p class="mt-1 text-[11px] font-medium text-stone-600 dark:text-stone-300">{{ $yourSideLabel }}</p>
            </div>
        </div>
    </div>

    <div class="mb-4 grid grid-cols-2 gap-2 md:grid-cols-4">
        <div class="rounded-lg border border-stone-200 bg-stone-50 px-3 py-2 dark:border-gray-700 dark:bg-gray-800/70">
            <p class="text-[11px] uppercase tracking-wide text-stone-500 dark:text-stone-400">Matched</p>
            <p class="text-base font-semibold text-stone-900 dark:text-stone-100">{{ $matchData['matchedCount'] ?? 0 }} / {{ $matchData['totalCount'] ?? 0 }}</p>
        </div>
        <div class="rounded-lg border border-stone-200 bg-stone-50 px-3 py-2 dark:border-gray-700 dark:bg-gray-800/70">
            <p class="text-[11px] uppercase tracking-wide text-stone-500 dark:text-stone-400">Needs attention</p>
            <p class="text-base font-semibold text-stone-900 dark:text-stone-100">{{ $statusCounts['mismatch'] }}</p>
        </div>
        <div class="rounded-lg border border-stone-200 bg-stone-50 px-3 py-2 dark:border-gray-700 dark:bg-gray-800/70">
            <p class="text-[11px] uppercase tracking-wide text-stone-500 dark:text-stone-400">Open preferences</p>
            <p class="text-base font-semibold text-stone-900 dark:text-stone-100">{{ $statusCounts['open'] }}</p>
        </div>
        <div class="rounded-lg border border-stone-200 bg-stone-50 px-3 py-2 dark:border-gray-700 dark:bg-gray-800/70">
            <p class="text-[11px] uppercase tracking-wide text-stone-500 dark:text-stone-400">Exact row matches</p>
            <p class="text-base font-semibold text-stone-900 dark:text-stone-100">{{ $statusCounts['match'] }}</p>
        </div>
    </div>

    @if (!empty($smartChips))
    <div class="mb-5 flex flex-wrap gap-2">
        @foreach ($smartChips as $chip)
            @php
                $chipClass = $chip['tone'] === 'match'
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-200'
                    : 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200';
                $chipIcon = $chip['tone'] === 'match' ? '✓' : '⚠';
            @endphp
            <span class="inline-flex items-center gap-1 rounded-full border px-3 py-1.5 text-xs font-medium {{ $chipClass }}">
                <span class="font-semibold">{{ $chipIcon }}</span> {{ $chip['label'] }}
            </span>
        @endforeach
    </div>
    @endif

    @foreach ($rowGroups as $groupName => $rows)
    <div class="mb-4 overflow-hidden rounded-xl border border-stone-200 dark:border-gray-700">
        <div class="bg-stone-50 px-3 py-2 text-sm font-semibold text-stone-800 dark:bg-gray-800/70 dark:text-stone-100">{{ $groupName }}</div>
        <div class="divide-y divide-stone-100 dark:divide-gray-800">
            @foreach ($rows as $row)
                @php
                    $statusText = match ($row['status']) {
                        'match' => 'Match',
                        'close' => 'Close match',
                        'mismatch' => 'Does not match',
                        default => 'Not specified / open',
                    };
                    $statusClass = match ($row['status']) {
                        'match' => 'text-emerald-700 dark:text-emerald-300',
                        'close' => 'text-amber-700 dark:text-amber-300',
                        'mismatch' => 'text-red-700 dark:text-red-300',
                        default => 'text-stone-500 dark:text-stone-400',
                    };
                @endphp
                <div class="grid gap-2 px-3 py-3 md:grid-cols-[1.2fr_1.3fr_1.3fr_1fr] md:items-center">
                    <div class="text-sm font-medium text-stone-800 dark:text-stone-100">{{ $row['label'] }}</div>
                    <div class="text-sm text-stone-600 dark:text-stone-300"><span class="text-xs uppercase tracking-wide text-stone-400">{{ $preferenceSideLabel }}:</span> {{ $row['their'] }}</div>
                    <div class="text-sm text-stone-600 dark:text-stone-300"><span class="text-xs uppercase tracking-wide text-stone-400">{{ $yourSideLabel }}:</span> {{ $row['yours'] }}</div>
                    <div class="text-sm font-semibold {{ $statusClass }}">{{ $statusText }}</div>
                </div>
                @if ($row['note'] !== '')
                    <div class="-mt-1 px-3 pb-3 text-xs text-stone-500 dark:text-stone-400">{{ $row['note'] }}</div>
                @endif
            @endforeach
        </div>
    </div>
    @endforeach

    @php
        $strongest = [];
        foreach ($allRows as $r) {
            if ($r['status'] === 'match') {
                $strongest[] = $r['label'];
            }
        }
        $needsAttention = [];
        foreach ($allRows as $r) {
            if ($r['status'] === 'mismatch') {
                $needsAttention[] = $r['label'];
            }
        }
        $footerLine = ! empty($strongest)
            ? ('This match is strongest in '.implode(', ', array_slice($strongest, 0, 2)).'.')
            : 'Some preferences remain open and can be discussed.';
        if (! empty($needsAttention)) {
            $footerLine .= ' '.implode(', ', array_slice($needsAttention, 0, 2)).' need attention.';
        }
    @endphp
    <div class="mb-6 rounded-lg border border-stone-200 bg-stone-50 px-3 py-2 text-sm text-stone-600 dark:border-gray-700 dark:bg-gray-800/60 dark:text-stone-300">
        {{ $footerLine }}
    </div>
</div>
@endif

{{-- User-side abuse reporting --}}
@if (auth()->check() && !$isOwnProfile)
    <hr style="margin-top:2rem; margin-bottom:1.5rem;">
    
    <div x-data="{ showReportForm: false }">
        @if (session('success'))
            <p style="color:green; margin-bottom:1rem;">{{ session('success') }}</p>
        @endif
        @if (session('error'))
            <p style="color:red; margin-bottom:1rem;">{{ session('error') }}</p>
        @endif
        @if ($errors->any())
            <div style="color:red; margin-bottom:1rem;">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($hasAlreadyReported)
            <div style="border:1px solid #fbbf24; background:#fef3c7; padding:1rem; max-width:500px; border-radius:4px;">
                <p style="color:#92400e; margin:0; font-weight:500;">
                    {{ __('You have already reported this profile. Our team is reviewing it.') }}
                </p>
            </div>
        @else
            <button
                type="button"
                @click="showReportForm = !showReportForm"
                class="bg-transparent p-0 border-0 font-inherit"
                style="color:#6b7280; text-decoration:underline; font-size:0.875rem; cursor:pointer;">
                {{ __('Report profile for abuse') }}
            </button>

            <div x-show="showReportForm" x-transition style="margin-top:1rem; max-width:500px;">
                <form method="POST" action="{{ route('abuse-reports.store', $profile) }}" style="border:1px solid #ccc; padding:1rem;">
                    @csrf
                    <p style="font-weight:600; margin-bottom:8px;">{{ __('Report this profile for abuse') }}</p>
                    <textarea name="reason" rows="4" required minlength="10" placeholder="{{ __('Please provide a reason for reporting this profile (minimum 10 characters)') }}" style="width:100%; margin-bottom:10px; padding:8px; border:1px solid #ddd; border-radius:4px;"></textarea>
                    <div class="flex gap-2">
                        <button type="submit" class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-sm text-white tracking-wide hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition disabled:opacity-50 disabled:cursor-not-allowed">{{ __('Submit Report') }}</button>
                        <button type="button" @click="showReportForm = false" class="inline-flex items-center px-4 py-2 bg-gray-500 border border-transparent rounded-md font-semibold text-sm text-white tracking-wide hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition">{{ __('Cancel') }}</button>
                    </div>
                </form>
            </div>
        @endif
    </div>
@endif

        </div>{{-- detailed sections below summary band --}}

    </div>{{-- main column --}}
    </div>{{-- bordered panel: aside + main --}}
    </div>{{-- gap-8 wrapper around bordered panel --}}

</div>{{-- space-y-8 profile body --}}

    <div x-show="$root.showContactUpgradeModal" x-cloak x-transition.opacity class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" style="display: none;" @click.self="$root.showContactUpgradeModal = false">
        <div class="relative mx-auto w-full max-w-sm rounded-2xl bg-white p-5 pt-6 shadow-2xl dark:bg-gray-900" @click.stop>
            <button type="button" class="absolute right-2.5 top-2.5 rounded-lg p-1.5 text-stone-500 transition hover:bg-stone-100 dark:text-stone-400 dark:hover:bg-gray-800" @click="$root.showContactUpgradeModal = false" aria-label="{{ __('upgrade_nudge.close') }}">✕</button>
            <h3 class="pr-8 text-base font-bold text-gray-900 dark:text-gray-100">{{ __('contact_access.upgrade_modal_title') }}</h3>
            <p class="mt-2 text-sm leading-relaxed text-gray-600 dark:text-gray-400">{{ __('upgrade_nudge.contact') }}</p>
            <a href="{{ route('plans.index') }}" class="mt-5 flex w-full items-center justify-center rounded-xl bg-indigo-600 px-4 py-3 text-sm font-bold text-white shadow-md transition hover:bg-indigo-700">{{ __('subscriptions.pricing_cta_upgrade') }}</a>
            <button type="button" class="mt-3 w-full text-center text-sm font-semibold text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200" @click="$root.showContactUpgradeModal = false">{{ __('upgrade_nudge.close') }}</button>
        </div>
    </div>
</div>{{-- max-w-6xl / x-data root --}}

@if (! ($isOwnProfile ?? false) && auth()->check() && ($contactAccess['show_paid_reveal_button'] ?? false) && ($canUseContact ?? false))
<script>
(function () {
    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }
    var form = document.getElementById('contact-reveal-form');
    if (!form) return;
    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        var btn = form.querySelector('button[type="submit"]');
        var root = document.getElementById('profile-contact-reveal-root');
        var usage = document.getElementById('contact-usage-strip');
        var prevErr = form.querySelector('[data-contact-reveal-err]');
        if (prevErr) prevErr.remove();
        btn.disabled = true;
        try {
            var token = document.querySelector('meta[name="csrf-token"]');
            token = token ? token.getAttribute('content') : '';
            var res = await fetch(form.action, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': token,
                    'X-Contact-Reveal': '1'
                },
                body: new FormData(form),
                credentials: 'same-origin'
            });
            var data = await res.json().catch(function () { return {}; });
            if (!res.ok) {
                var err = document.createElement('div');
                err.setAttribute('data-contact-reveal-err', '1');
                err.className = 'mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900 dark:border-red-800 dark:bg-red-950/40 dark:text-red-100';
                err.textContent = data.message || @json(__('contact_access.reveal_not_allowed'));
                form.insertBefore(err, form.firstChild);
                return;
            }
            var html = '<div class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-100" role="status">' + escHtml(data.message) + '</div>';
            html += '<div class="text-center space-y-2">';
            if (data.phone) {
                html += '<p class="text-xl font-bold text-stone-900 dark:text-stone-100">' + escHtml(data.phone) + '</p>';
            }
            if (data.email) {
                var lp = form.getAttribute('data-label-email-prefix') || 'Email';
                html += '<p class="text-sm text-stone-800 dark:text-stone-200 break-all"><span class="text-stone-500 dark:text-stone-400">' + escHtml(lp) + ':</span> ' + escHtml(data.email) + '</p>';
            }
            var ub = form.getAttribute('data-label-unlocked-banner');
            if (ub) {
                html += '<p class="text-green-600 text-sm font-medium dark:text-green-400">' + escHtml(ub) + '</p>';
            }
            html += '</div>';
            if (root) root.innerHTML = html;
            if (usage && data.contact_usage) {
                var u = data.contact_usage;
                var line1Cls = u.low_warning ? 'text-sm font-semibold text-amber-800 dark:text-amber-200' : 'text-sm text-gray-800 dark:text-gray-200';
                var uhtml = '<p class="' + line1Cls + '">' + escHtml(u.line1) + '</p>';
                uhtml += '<p class="mt-1 text-sm text-gray-600 dark:text-gray-400">' + escHtml(u.line2) + '</p>';
                if (u.low_warning && u.low_warning_text) {
                    uhtml += '<p class="mt-2 text-xs text-amber-700 dark:text-amber-300">' + escHtml(u.low_warning_text) + '</p>';
                }
                usage.innerHTML = uhtml;
            }
        } catch (err) {
            if (root) {
                var net = document.createElement('div');
                net.className = 'rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900';
                net.textContent = @json(__('contact_access.reveal_not_allowed'));
                root.innerHTML = '';
                root.appendChild(net);
            }
        } finally {
            btn.disabled = false;
        }
    });
})();
</script>
@endif
@endsection

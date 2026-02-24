@extends(request()->routeIs('admin.*') ? 'layouts.admin' : 'layouts.app')

@section('content')
<div class="{{ request()->routeIs('admin.*') ? 'bg-white dark:bg-gray-800 shadow rounded-lg p-6' : 'max-w-3xl mx-auto py-8' }}" x-data="{ adminEditMode: @js(auth()->check() && auth()->user()->is_admin === true && request()->has('admin_edit')) }">
    @if (request()->routeIs('admin.*'))
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-1">Admin — Profile #{{ $profile->id }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">{{ $profile->full_name ?? '—' }}@if (!empty($profile->is_demo)) <span class="inline-block ml-2 px-2 py-0.5 text-xs font-semibold bg-sky-100 dark:bg-sky-900/40 text-sky-700 dark:text-sky-300 rounded">Demo</span>@endif</p>
    @else
        <h1 class="text-2xl font-bold mb-6">
            Matrimony Profile
            @if (!empty($profile->is_demo))
                <span class="inline-block ml-2 px-2 py-0.5 text-xs font-semibold bg-sky-100 text-sky-700 rounded">Demo Profile</span>
            @endif
        </h1>
    @endif

@if (($profile->lifecycle_state ?? null) === 'conflict_pending' && ($hasBlockingConflicts ?? false))
    <div class="mb-4 px-4 py-3 rounded-lg bg-red-100 dark:bg-red-900/50 border-2 border-red-600 dark:border-red-500 text-red-900 dark:text-red-100 font-semibold">
        ⚠️ Your profile has unresolved conflicts. Please contact support or wait for admin resolution.
    </div>
@endif

@if ($isOwnProfile && $profile->is_suspended)
    <div style="margin-bottom:1.5rem; padding:1.25rem; background:#fef3c7; border:2px solid #fbbf24; border-radius:8px; color:#92400e;">
        <p style="font-weight:700; margin:0; font-size:1.1rem;">⚠️ Your profile is currently suspended by admin.</p>
    </div>
@endif

{{-- Admin-only moderation actions --}}
@if (auth()->check() && auth()->user()->is_admin === true)
    <div x-data="{ activeAction: null }" class="mb-6 p-6 rounded-lg border-2 border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h3 class="text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">Moderation</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400">Profile suspend, unsuspend, soft delete, image approve/reject, visibility override. All actions require a reason.</p>
            </div>
            <button 
                type="button"
                @click="$parent.adminEditMode = !$parent.adminEditMode"
                class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md font-medium text-sm transition-colors">
                <span x-text="$parent.adminEditMode ? 'Cancel Edit' : 'Edit Profile (Admin)'"></span>
            </button>
        </div>
        <div class="flex flex-wrap gap-2 mb-4">
            <button 
                type="button"
                @click="activeAction = activeAction === 'suspend' ? null : 'suspend'"
                style="padding:8px 16px; background:#f59e0b; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:500;">
                Suspend
            </button>

            <button 
                type="button"
                @click="activeAction = activeAction === 'unsuspend' ? null : 'unsuspend'"
                style="padding:8px 16px; background:#10b981; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:500;">
                Unsuspend
            </button>

            <button 
                type="button"
                @click="activeAction = activeAction === 'soft-delete' ? null : 'soft-delete'"
                style="padding:8px 16px; background:#ef4444; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:500;">
                Soft Delete
            </button>

            @if ($profile->profile_photo)
            <button 
                type="button"
                @click="activeAction = activeAction === 'approve-image' ? null : 'approve-image'"
                style="padding:8px 16px; background:#3b82f6; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:500;">
                Approve Image
            </button>

            <button 
                type="button"
                @click="activeAction = activeAction === 'reject-image' ? null : 'reject-image'"
                style="padding:8px 16px; background:#dc2626; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:500;">
                Reject Image
            </button>
            @endif

            <button 
                type="button"
                @click="activeAction = activeAction === 'override-visibility' ? null : 'override-visibility'"
                style="padding:8px 16px; background:#8b5cf6; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:500;">
                Override Visibility
            </button>
        </div>

        {{-- Suspend Form --}}
        <div x-show="activeAction === 'suspend'" x-transition style="border:1px solid #ccc; padding:1rem; border-radius:4px; margin-bottom:1rem; background:#fff;">
            <form method="POST" action="{{ route('admin.profiles.suspend', $profile) }}">
                @csrf
                <p style="font-weight:600; margin-bottom:8px;">Suspend Profile</p>
                <textarea name="reason" rows="3" required minlength="10" placeholder="Reason (minimum 10 characters)" style="width:100%; margin-bottom:8px; padding:8px; border:1px solid #ddd; border-radius:4px;"></textarea>
                <div style="display:flex; gap:8px;">
                    <button type="submit" style="padding:8px 16px; background:#f59e0b; color:white; border:none; border-radius:4px; cursor:pointer;">Submit</button>
                    <button type="button" @click="activeAction = null" style="padding:8px 16px; background:#6b7280; color:white; border:none; border-radius:4px; cursor:pointer;">Cancel</button>
                </div>
            </form>
        </div>

        {{-- Unsuspend Form --}}
        <div x-show="activeAction === 'unsuspend'" x-transition style="border:1px solid #ccc; padding:1rem; border-radius:4px; margin-bottom:1rem; background:#fff;">
            <form method="POST" action="{{ route('admin.profiles.unsuspend', $profile) }}">
                @csrf
                <p style="font-weight:600; margin-bottom:8px;">Unsuspend Profile</p>
                <textarea name="reason" rows="3" required minlength="10" placeholder="Reason (minimum 10 characters)" style="width:100%; margin-bottom:8px; padding:8px; border:1px solid #ddd; border-radius:4px;"></textarea>
                <div style="display:flex; gap:8px;">
                    <button type="submit" style="padding:8px 16px; background:#10b981; color:white; border:none; border-radius:4px; cursor:pointer;">Submit</button>
                    <button type="button" @click="activeAction = null" style="padding:8px 16px; background:#6b7280; color:white; border:none; border-radius:4px; cursor:pointer;">Cancel</button>
                </div>
            </form>
        </div>

        {{-- Soft Delete Form --}}
        <div x-show="activeAction === 'soft-delete'" x-transition style="border:1px solid #ccc; padding:1rem; border-radius:4px; margin-bottom:1rem; background:#fff;">
            <form method="POST" action="{{ route('admin.profiles.soft-delete', $profile) }}">
                @csrf
                <p style="font-weight:600; margin-bottom:8px;">Soft Delete Profile</p>
                <textarea name="reason" rows="3" required minlength="10" placeholder="Reason (minimum 10 characters)" style="width:100%; margin-bottom:8px; padding:8px; border:1px solid #ddd; border-radius:4px;"></textarea>
                <div style="display:flex; gap:8px;">
                    <button type="submit" style="padding:8px 16px; background:#ef4444; color:white; border:none; border-radius:4px; cursor:pointer;">Submit</button>
                    <button type="button" @click="activeAction = null" style="padding:8px 16px; background:#6b7280; color:white; border:none; border-radius:4px; cursor:pointer;">Cancel</button>
                </div>
            </form>
        </div>

        @if ($profile->profile_photo)
        {{-- Approve Image Form --}}
        <div x-show="activeAction === 'approve-image'" x-transition style="border:1px solid #ccc; padding:1rem; border-radius:4px; margin-bottom:1rem; background:#fff;">
            <form method="POST" action="{{ route('admin.profiles.approve-image', $profile) }}">
                @csrf
                <p style="font-weight:600; margin-bottom:8px;">Approve Image</p>
                <textarea name="reason" rows="3" required minlength="10" placeholder="Reason (minimum 10 characters)" style="width:100%; margin-bottom:8px; padding:8px; border:1px solid #ddd; border-radius:4px;"></textarea>
                <div style="display:flex; gap:8px;">
                    <button type="submit" style="padding:8px 16px; background:#3b82f6; color:white; border:none; border-radius:4px; cursor:pointer;">Submit</button>
                    <button type="button" @click="activeAction = null" style="padding:8px 16px; background:#6b7280; color:white; border:none; border-radius:4px; cursor:pointer;">Cancel</button>
                </div>
            </form>
        </div>

        {{-- Reject Image Form --}}
        <div x-show="activeAction === 'reject-image'" x-transition style="border:1px solid #ccc; padding:1rem; border-radius:4px; margin-bottom:1rem; background:#fff;">
            <form method="POST" action="{{ route('admin.profiles.reject-image', $profile) }}">
                @csrf
                <p style="font-weight:600; margin-bottom:8px;">Reject Image</p>
                <textarea name="reason" rows="3" required minlength="10" placeholder="Reason (minimum 10 characters)" style="width:100%; margin-bottom:8px; padding:8px; border:1px solid #ddd; border-radius:4px;"></textarea>
                <div style="display:flex; gap:8px;">
                    <button type="submit" style="padding:8px 16px; background:#dc2626; color:white; border:none; border-radius:4px; cursor:pointer;">Submit</button>
                    <button type="button" @click="activeAction = null" style="padding:8px 16px; background:#6b7280; color:white; border:none; border-radius:4px; cursor:pointer;">Cancel</button>
                </div>
            </form>
        </div>
        @endif

        {{-- Override Visibility Form --}}
        <div x-show="activeAction === 'override-visibility'" x-transition style="border:1px solid #ccc; padding:1rem; border-radius:4px; margin-bottom:1rem; background:#fff;">
            <form method="POST" action="{{ route('admin.profiles.override-visibility', $profile) }}">
                @csrf
                <p style="font-weight:600; margin-bottom:8px;">Override visibility (force search visible even if &lt;70% complete)</p>
                <textarea name="reason" rows="3" required minlength="10" placeholder="Reason (minimum 10 characters)" style="width:100%; margin-bottom:8px; padding:8px; border:1px solid #ddd; border-radius:4px;"></textarea>
                <div style="display:flex; gap:8px;">
                    <button type="submit" style="padding:8px 16px; background:#8b5cf6; color:white; border:none; border-radius:4px; cursor:pointer;">Submit</button>
                    <button type="button" @click="activeAction = null" style="padding:8px 16px; background:#6b7280; color:white; border:none; border-radius:4px; cursor:pointer;">Cancel</button>
                </div>
            </form>
        </div>
    </div>
@endif

    
<div class="bg-white shadow rounded-lg p-6">

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
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Date of Birth</label>
                <input type="date" name="date_of_birth" value="{{ old('date_of_birth', $profile->date_of_birth) }}" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
            </div>
            @endif
            
            @if ($maritalStatusVisible)
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Marital Status</label>
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
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Education</label>
                <input type="text" name="highest_education" value="{{ old('highest_education', $profile->highest_education) }}" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
            </div>
            @endif
            
            @if ($locationVisible)
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Location</label>
                <p class="font-medium text-base text-gray-900 dark:text-gray-100">{{ implode(', ', array_filter([$profile->city?->name, $profile->taluka?->name, $profile->district?->name, $profile->state?->name, $profile->country?->name])) ?: '—' }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Edit location via profile hierarchy (country/state/city) in full edit.</p>
            </div>
            @endif
            
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

{{-- Profile Completeness --}}
<div class="mb-6">
    <div class="flex justify-between items-center mb-1">
        <span class="text-sm font-medium text-gray-700">Profile Completeness</span>
        <span class="text-sm font-bold text-gray-900">{{ $completenessPct }}%</span>
    </div>
    <div class="w-full bg-gray-200 rounded-full h-2.5">
        <div class="bg-indigo-600 h-2.5 rounded-full transition-all duration-300" style="width: {{ $completenessPct }}%;"></div>
    </div>
</div>

{{-- Profile Photo with Gender-based Fallback --}}
@if ($profilePhotoVisible)
<div class="mb-6 flex flex-col items-center">
    @if ($profile->profile_photo)
        {{-- Real uploaded photo --}}
        <img
            src="{{ asset('uploads/matrimony_photos/'.$profile->profile_photo) }}"
            alt="Profile Photo"
            class="w-40 h-40 rounded-full object-cover border"
        />
    @else
        {{-- Gender-based placeholder fallback (UI only) --}}
        @php
            $genderKey = $profile->gender?->key ?? $profile->gender;
            if ($genderKey === 'male') {
                $placeholderSrc = asset('images/placeholders/male-profile.svg');
            } elseif ($genderKey === 'female') {
                $placeholderSrc = asset('images/placeholders/female-profile.svg');
            } else {
                $placeholderSrc = asset('images/placeholders/default-profile.svg');
            }
        @endphp
        <img
            src="{{ $placeholderSrc }}"
            alt="Profile Placeholder"
            class="w-40 h-40 rounded-full object-cover border"
        />
        @if (!empty($profile->is_demo))
            <span class="text-xs text-gray-500 mt-1">Demo profile</span>
        @endif
    @endif
</div>
@endif

{{-- Name & Gender --}}
<div class="text-center mb-6">
    <h2 class="text-2xl font-semibold">
        {{ $profile->full_name }}
        @if ($isOwnProfile && $profile->admin_edited_fields && in_array('full_name', $profile->admin_edited_fields ?? []))
            <span class="ml-2 text-xs text-amber-600 dark:text-amber-400" title="This field was corrected by admin">(Admin corrected)</span>
        @endif
    </h2>
    <p class="text-gray-500">
        {{ $profile->gender?->label ?? $profile->user?->gender ?? '—' }}
    </p>
</div>

@if ($isOwnProfile && $profile->photo_rejection_reason)
    <div style="margin-bottom:1.5rem; padding:1rem; background:#fee2e2; border:1px solid #fca5a5; border-radius:8px; color:#991b1b;">
        <p style="font-weight:600; margin-bottom:0.5rem;">Your profile photo was removed by admin.</p>
        <p style="margin:0;"><strong>Reason:</strong> {{ $profile->photo_rejection_reason }}</p>
    </div>
@endif

{{-- Basic: DOB, Marital, Religion, Caste, Subcaste, Location --}}
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    @if ($dateOfBirthVisible && ($profile->date_of_birth ?? '') !== '')
    <div>
        <p class="text-gray-500 text-sm">Date of Birth</p>
        <p class="font-medium text-base">{{ $profile->date_of_birth }}</p>
    </div>
    @endif
    @if (($profile->birth_time ?? '') !== '')
    <div>
        <p class="text-gray-500 text-sm">Birth time</p>
        <p class="font-medium text-base">{{ $profile->birth_time }}</p>
    </div>
    @endif
    @if ($maritalStatusVisible && $profile->maritalStatus)
    <div>
        <p class="text-gray-500 text-sm">Marital Status</p>
        <p class="font-medium text-base">{{ $profile->maritalStatus->label ?? '—' }}</p>
    </div>
    @endif
    @if ($profile->religion)
    <div>
        <p class="text-gray-500 text-sm">Religion</p>
        <p class="font-medium text-base">{{ $profile->religion->label ?? '—' }}</p>
    </div>
    @endif
    @if ($profile->caste)
    <div>
        <p class="text-gray-500 text-sm">Caste</p>
        <p class="font-medium text-base">{{ $profile->caste->label ?? '—' }}</p>
    </div>
    @endif
    @if ($profile->subCaste)
    <div>
        <p class="text-gray-500 text-sm">Sub caste</p>
        <p class="font-medium text-base">{{ $profile->subCaste->label ?? '—' }}</p>
    </div>
    @endif
    @php
        $locationParts = array_filter([
            $profile->city?->name,
            $profile->taluka?->name,
            $profile->district?->name,
            $profile->state?->name,
            $profile->country?->name,
        ]);
        $locationLine = implode(', ', $locationParts);
    @endphp
    @if ($locationVisible && ($locationLine !== '' || ($profile->address_line ?? '') !== ''))
    <div>
        <p class="text-gray-500 text-sm">Location</p>
        @if (($profile->address_line ?? '') !== '')
        <p class="font-medium text-base">{{ $profile->address_line }}</p>
        @endif
        @if ($locationLine !== '')
        <p class="font-medium text-base">{{ $locationLine }}</p>
        @endif
    </div>
    @endif
    @if ($profile->seriousIntent)
    <div>
        <p class="text-gray-500 text-sm">Marriage timeline</p>
        <p class="font-medium text-base">{{ $profile->seriousIntent->name ?? '—' }}</p>
    </div>
    @endif
</div>

@php
    $hasPhysical = ($heightVisible && ($profile->height_cm ?? '') !== '') || ($profile->weight_kg ?? null) !== null || $profile->complexion || $profile->physicalBuild || $profile->bloodGroup;
@endphp
@if ($hasPhysical)
<div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Physical</h3>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        @if ($heightVisible && ($profile->height_cm ?? '') !== '')
        <div>
            <p class="text-gray-500 text-sm">Height</p>
            <p class="font-medium text-base">{{ $profile->height_cm }} cm</p>
        </div>
        @endif
        @if (($profile->weight_kg ?? null) !== null && $profile->weight_kg !== '')
        <div>
            <p class="text-gray-500 text-sm">Weight</p>
            <p class="font-medium text-base">{{ $profile->weight_kg }} kg</p>
        </div>
        @endif
        @if ($profile->complexion)
        <div>
            <p class="text-gray-500 text-sm">Complexion</p>
            <p class="font-medium text-base">{{ $profile->complexion->label ?? '—' }}</p>
        </div>
        @endif
        @if ($profile->physicalBuild)
        <div>
            <p class="text-gray-500 text-sm">Physical Build</p>
            <p class="font-medium text-base">{{ $profile->physicalBuild->label ?? '—' }}</p>
        </div>
        @endif
        @if ($profile->bloodGroup)
        <div>
            <p class="text-gray-500 text-sm">Blood Group</p>
            <p class="font-medium text-base">{{ $profile->bloodGroup->label ?? '—' }}</p>
        </div>
        @endif
    </div>
</div>
@endif

@php
    $hasEduCareer = ($educationVisible && ($profile->highest_education ?? '') !== '') || ($profile->specialization ?? '') !== '' || ($profile->occupation_title ?? '') !== '' || ($profile->company_name ?? '') !== '' || ($profile->annual_income ?? null) !== null || ($profile->family_income ?? null) !== null || $profile->incomeCurrency;
@endphp
@if ($hasEduCareer)
<div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Education & Career</h3>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        @if ($educationVisible && ($profile->highest_education ?? '') !== '')
        <div>
            <p class="text-gray-500 text-sm">Education</p>
            <p class="font-medium text-base">{{ $profile->highest_education }}</p>
        </div>
        @endif
        @if (($profile->specialization ?? '') !== '')
        <div>
            <p class="text-gray-500 text-sm">Specialization</p>
            <p class="font-medium text-base">{{ $profile->specialization }}</p>
        </div>
        @endif
        @if (($profile->occupation_title ?? '') !== '')
        <div>
            <p class="text-gray-500 text-sm">Occupation</p>
            <p class="font-medium text-base">{{ $profile->occupation_title }}</p>
        </div>
        @endif
        @if (($profile->company_name ?? '') !== '')
        <div>
            <p class="text-gray-500 text-sm">Company</p>
            <p class="font-medium text-base">{{ $profile->company_name }}</p>
        </div>
        @endif
        @if (($profile->annual_income ?? null) !== null && $profile->annual_income !== '')
        <div>
            <p class="text-gray-500 text-sm">Annual Income</p>
            <p class="font-medium text-base">{{ $profile->annual_income }}{{ $profile->incomeCurrency ? ' ' . ($profile->incomeCurrency->symbol ?? $profile->incomeCurrency->code ?? '') : '' }}</p>
        </div>
        @endif
        @if (($profile->family_income ?? null) !== null && $profile->family_income !== '')
        <div>
            <p class="text-gray-500 text-sm">Family Income</p>
            <p class="font-medium text-base">{{ $profile->family_income }}{{ $profile->incomeCurrency ? ' ' . ($profile->incomeCurrency->symbol ?? $profile->incomeCurrency->code ?? '') : '' }}</p>
        </div>
        @endif
        @if ($profile->incomeCurrency && ($profile->annual_income ?? null) === null && ($profile->family_income ?? null) === null)
        <div>
            <p class="text-gray-500 text-sm">Income Currency</p>
            <p class="font-medium text-base">{{ trim($profile->incomeCurrency->symbol ?? '') }} {{ $profile->incomeCurrency->code ?? '—' }}</p>
        </div>
        @endif
    </div>
</div>
@endif

@php
    $hasFamily = ($profile->father_name ?? '') !== '' || ($profile->father_occupation ?? '') !== '' || ($profile->mother_name ?? '') !== '' || ($profile->mother_occupation ?? '') !== '' || ($profile->brothers_count ?? null) !== null || ($profile->sisters_count ?? null) !== null || $profile->familyType;
@endphp
@if ($hasFamily)
<div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Family</h3>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        @if (($profile->father_name ?? '') !== '')
        <div>
            <p class="text-gray-500 text-sm">Father</p>
            <p class="font-medium text-base">{{ $profile->father_name }}{{ ($profile->father_occupation ?? '') !== '' ? ' · ' . $profile->father_occupation : '' }}</p>
        </div>
        @endif
        @if (($profile->mother_name ?? '') !== '')
        <div>
            <p class="text-gray-500 text-sm">Mother</p>
            <p class="font-medium text-base">{{ $profile->mother_name }}{{ ($profile->mother_occupation ?? '') !== '' ? ' · ' . $profile->mother_occupation : '' }}</p>
        </div>
        @endif
        @if (($profile->brothers_count ?? null) !== null && $profile->brothers_count !== '')
        <div>
            <p class="text-gray-500 text-sm">Brothers</p>
            <p class="font-medium text-base">{{ $profile->brothers_count }}</p>
        </div>
        @endif
        @if (($profile->sisters_count ?? null) !== null && $profile->sisters_count !== '')
        <div>
            <p class="text-gray-500 text-sm">Sisters</p>
            <p class="font-medium text-base">{{ $profile->sisters_count }}</p>
        </div>
        @endif
        @if ($profile->familyType)
        <div>
            <p class="text-gray-500 text-sm">Family Type</p>
            <p class="font-medium text-base">{{ $profile->familyType->label ?? '—' }}</p>
        </div>
        @endif
    </div>
</div>
@endif

@php
    $workCityName = $profile->work_city_id ? \App\Models\City::where('id', $profile->work_city_id)->value('name') : null;
    $workStateName = $profile->work_state_id ? \App\Models\State::where('id', $profile->work_state_id)->value('name') : null;
    $hasWorkLocation = $workCityName || $workStateName;
@endphp
@if ($hasWorkLocation)
<div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Work Location</h3>
    <p class="font-medium text-base">{{ implode(', ', array_filter([$workCityName, $workStateName])) }}</p>
</div>
@endif

@if (($profilePropertySummary ?? null) && ($profilePropertySummary->owns_agriculture ?? false) && (($profilePropertySummary->agriculture_type ?? '') !== ''))
<div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Property</h3>
    <div>
        <p class="text-gray-500 text-sm">Agriculture type</p>
        <p class="font-medium text-base">{{ $profilePropertySummary->agriculture_type }}</p>
    </div>
</div>
@endif

@php
    $hasBirthPlace = $profile->birth_city_id || $profile->birth_taluka_id || $profile->birth_district_id || $profile->birth_state_id;
@endphp
@if ($hasBirthPlace)
<div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Birth Place</h3>
    <p class="font-medium text-base">{{ implode(', ', array_filter([$profile->birthCity?->name, $profile->birthTaluka?->name, $profile->birthDistrict?->name, $profile->birthState?->name])) }}</p>
</div>
@endif

@php
    $hasNativePlace = $profile->native_city_id || $profile->native_taluka_id || $profile->native_district_id || $profile->native_state_id;
@endphp
@if ($hasNativePlace)
<div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Native Place</h3>
    <p class="font-medium text-base">{{ implode(', ', array_filter([$profile->nativeCity?->name, $profile->nativeTaluka?->name, $profile->nativeDistrict?->name, $profile->nativeState?->name])) }}</p>
</div>
@endif

@if ($profile->siblings?->isNotEmpty())
@php
    $siblingsByGender = $profile->siblings->groupBy(function ($s) { return ($s->gender ?? 'other') ?: 'other'; });
@endphp
<div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Siblings</h3>
    @foreach($siblingsByGender as $gender => $items)
        <div class="mb-3">
            <p class="text-gray-500 text-sm font-medium mb-1">{{ ucfirst($gender) }}</p>
            @foreach($items as $sib)
                <p class="font-medium text-base ml-2">
                    {{ $sib->occupation ?: '—' }}{{ $sib->marital_status ? ' · ' . ucfirst($sib->marital_status) : '' }}{{ $sib->city?->name ? ' · ' . $sib->city->name : '' }}{{ $sib->notes ? ' · ' . \Illuminate\Support\Str::limit($sib->notes, 50) : '' }}
                </p>
            @endforeach
        </div>
    @endforeach
</div>
@endif

@if ($profile->children?->isNotEmpty())
<div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
    <p class="text-gray-500 text-sm mb-2">Children</p>
    @foreach($profile->children as $child)
        <p class="font-medium text-base">{{ $child->child_name ?: '—' }}{{ $child->age ? ', ' . $child->age . ' yrs' : '' }}{{ $child->gender ? ' (' . $child->gender . ')' : '' }}</p>
    @endforeach
</div>
@endif

@if ($profile->educationHistory && $profile->educationHistory->isNotEmpty())
<div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
    <p class="text-gray-500 text-sm mb-2">Education History</p>
    @foreach($profile->educationHistory as $edu)
        <p class="font-medium text-base">{{ $edu->degree ?: '—' }}{{ $edu->specialization ? ' – ' . $edu->specialization : '' }}{{ $edu->university ? ' (' . $edu->university . ')' : '' }}{{ $edu->year_completed ? ', ' . $edu->year_completed : '' }}</p>
    @endforeach
</div>
@endif

@if ($profile->career?->isNotEmpty())
<div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
    <p class="text-gray-500 text-sm mb-2">Career History</p>
    @foreach($profile->career as $job)
        <p class="font-medium text-base">{{ $job->designation ?: '—' }}{{ $job->company ? ' at ' . $job->company : '' }}{{ $job->start_year || $job->end_year ? ' (' . ($job->start_year ?? '') . '–' . ($job->end_year ?? '') . ')' : '' }}</p>
    @endforeach
</div>
@endif

@if ($profile->addresses?->isNotEmpty())
<div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
    <p class="text-gray-500 text-sm mb-2">Address</p>
    @foreach($profile->addresses as $addr)
        <p class="font-medium text-base">
            {{ implode(', ', array_filter([
                trim($addr->village?->name ?? ''),
                $addr->city?->name,
                $addr->taluka?->name,
                $addr->district?->name,
                $addr->state?->name,
                $addr->country?->name,
            ])) ?: '—' }}{{ trim($addr->postal_code ?? '') ? ' – ' . $addr->postal_code : '' }}
        </p>
    @endforeach
</div>
@endif

@if (!empty($enableRelativesSection) && $profile->relatives?->isNotEmpty())
@php
    $relativesByType = $profile->relatives->groupBy(function ($r) { return $r->relation_type ?: 'Other'; });
@endphp
<div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Relatives & Family Network</h3>
    @foreach($relativesByType as $relationType => $relatives)
        <div class="mb-3">
            <p class="text-gray-500 text-sm font-medium mb-1">{{ $relationType }}</p>
            @foreach($relatives as $rel)
                <p class="font-medium text-base ml-2">
                    {{ $rel->name ?: '—' }}{{ $rel->occupation ? ' · ' . $rel->occupation : '' }}{{ ($rel->city?->name || $rel->state?->name) ? ' (' . trim(implode(', ', array_filter([$rel->city?->name, $rel->state?->name]))) . ')' : '' }}{{ $rel->contact_number ? ' · ' . $rel->contact_number : '' }}{{ $rel->notes ? ' · ' . \Illuminate\Support\Str::limit($rel->notes, 60) : '' }}
                </p>
            @endforeach
        </div>
    @endforeach
</div>
@endif

@if ($profile->allianceNetworks?->isNotEmpty())
@php
    $allianceByLocation = $profile->allianceNetworks->groupBy(function ($a) {
        $parts = array_filter([$a->city?->name, $a->taluka?->name, $a->district?->name, $a->state?->name]);
        return implode(', ', $parts) ?: 'Other';
    });
@endphp
<div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Alliance & Native Network</h3>
    @foreach($allianceByLocation as $locationLabel => $items)
        <div class="mb-3">
            <p class="text-gray-500 text-sm font-medium mb-1">{{ $locationLabel }}</p>
            @foreach($items as $a)
                <p class="font-medium text-base ml-2">
                    {{ $a->surname ?: '—' }}{{ $a->notes ? ' · ' . \Illuminate\Support\Str::limit($a->notes, 60) : '' }}
                </p>
            @endforeach
        </div>
    @endforeach
</div>
@endif

@if (isset($preferences) && (trim($preferences->preferred_city ?? '') !== '' || trim($preferences->preferred_education ?? '') !== '' || ($preferences->preferred_age_min ?? null) !== null || ($preferences->preferred_age_max ?? null) !== null))
<div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Partner preferences</h3>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm">
        @if (($preferences->preferred_city ?? '') !== '')<p><span class="text-gray-500">City:</span> {{ $preferences->preferred_city }}</p>@endif
        @if (($preferences->preferred_caste ?? '') !== '')<p><span class="text-gray-500">Caste:</span> {{ $preferences->preferred_caste }}</p>@endif
        @if (($preferences->preferred_age_min ?? '') !== '' || ($preferences->preferred_age_max ?? '') !== '')<p><span class="text-gray-500">Age:</span> {{ $preferences->preferred_age_min ?? '—' }}–{{ $preferences->preferred_age_max ?? '—' }}</p>@endif
        @if (($preferences->preferred_education ?? '') !== '')<p><span class="text-gray-500">Education:</span> {{ $preferences->preferred_education }}</p>@endif
    </div>
</div>
@endif

@if (isset($extendedAttributes) && (trim($extendedAttributes->narrative_about_me ?? '') !== '' || trim($extendedAttributes->narrative_expectations ?? '') !== ''))
<div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">About & expectations</h3>
    @if (trim($extendedAttributes->narrative_about_me ?? '') !== '')
        <p class="text-gray-500 text-sm">About me</p>
        <p class="font-medium text-base whitespace-pre-wrap">{{ $extendedAttributes->narrative_about_me }}</p>
    @endif
    @if (trim($extendedAttributes->narrative_expectations ?? '') !== '')
        <p class="text-gray-500 text-sm mt-2">Expectations</p>
        <p class="font-medium text-base whitespace-pre-wrap">{{ $extendedAttributes->narrative_expectations }}</p>
    @endif
</div>
@endif

@if ($profile->horoscope && ($profile->horoscope->rashi_id || $profile->horoscope->nakshatra_id || $profile->horoscope->gan_id || $profile->horoscope->nadi_id || $profile->horoscope->mangal_dosh_type_id || $profile->horoscope->yoni_id || $profile->horoscope->charan || $profile->horoscope->devak || $profile->horoscope->kul || $profile->horoscope->gotra))
<div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Horoscope</h3>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm">
        @if ($profile->horoscope->rashi)<p><span class="text-gray-500">Rashi:</span> {{ $profile->horoscope->rashi->label ?? '—' }}</p>@endif
        @if ($profile->horoscope->nakshatra)<p><span class="text-gray-500">Nakshatra:</span> {{ $profile->horoscope->nakshatra->label ?? '—' }}</p>@endif
        @if ($profile->horoscope->gan)<p><span class="text-gray-500">Gan:</span> {{ $profile->horoscope->gan->label ?? '—' }}</p>@endif
        @if ($profile->horoscope->nadi)<p><span class="text-gray-500">Nadi:</span> {{ $profile->horoscope->nadi->label ?? '—' }}</p>@endif
        @if ($profile->horoscope->mangalDoshType)<p><span class="text-gray-500">Mangal Dosh:</span> {{ $profile->horoscope->mangalDoshType->label ?? '—' }}</p>@endif
        @if ($profile->horoscope->yoni)<p><span class="text-gray-500">Yoni:</span> {{ $profile->horoscope->yoni->label ?? '—' }}</p>@endif
        @if ($profile->horoscope->charan)<p><span class="text-gray-500">Charan:</span> {{ $profile->horoscope->charan }}</p>@endif
        @if ($profile->horoscope->devak)<p><span class="text-gray-500">Devak:</span> {{ $profile->horoscope->devak }}</p>@endif
        @if ($profile->horoscope->kul)<p><span class="text-gray-500">Kul:</span> {{ $profile->horoscope->kul }}</p>@endif
        @if ($profile->horoscope->gotra)<p><span class="text-gray-500">Gotra:</span> {{ $profile->horoscope->gotra }}</p>@endif
    </div>
</div>
@endif

<div class="mt-6">
    <p class="text-gray-500 text-sm">Contact Information</p>
    <p class="font-medium text-base">
        @if ($isOwnProfile)
            @if ($primaryContactPhone)
                {{ $primaryContactPhone }}
            @else
                No contact number added.
            @endif
        @elseif ($canViewContact)
            {{ $primaryContactPhone }}
        @else
            Contact details will be available after interest acceptance.
        @endif
    </p>
</div>

@if(!empty($extendedValues))
    @php
        $filteredExtended = array_filter($extendedValues, function($v) {
            return $v !== null && $v !== '';
        });
    @endphp

    @if(!empty($filteredExtended))
        <div class="mt-8">
            <h3 class="text-lg font-semibold mb-4">Additional Details</h3>

            @foreach($filteredExtended as $label => $value)
                <div class="mb-2">
                    <p class="text-gray-500 text-sm">{{ $extendedMeta[$label] ?? $label }}</p>
                    <p class="font-medium text-base">{{ $value }}</p>
                </div>
            @endforeach
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
<div class="mt-8 mb-8 p-6 bg-gradient-to-br from-indigo-50 to-purple-50 dark:from-indigo-900/20 dark:to-purple-900/20 rounded-lg border border-indigo-200 dark:border-indigo-800" x-data="{ expanded: false }">
    {{-- Photo Comparison --}}
    <div class="flex items-center justify-center gap-4 mb-6">
        <div class="flex flex-col items-center">
            <img
                src="{{ $viewedPhotoSrc }}"
                alt="Viewed Profile"
                class="w-16 h-16 rounded-full object-cover border-2 border-indigo-300 dark:border-indigo-600"
            />
            <span class="text-xs text-gray-600 dark:text-gray-400 mt-1">{{ $profile->full_name }}</span>
        </div>
        <div class="text-2xl">❤️</div>
        <div class="flex flex-col items-center">
            <img
                src="{{ $viewerPhotoSrc }}"
                alt="Your Profile"
                class="w-16 h-16 rounded-full object-cover border-2 border-purple-300 dark:border-purple-600"
            />
            <span class="text-xs text-gray-600 dark:text-gray-400 mt-1">तुमची प्रोफाइल</span>
        </div>
    </div>

    {{-- Section Heading --}}
    <div class="text-center mb-6">
        <h3 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-1">तुमची प्रोफाइल त्यांच्याशी कशी जुळते?</h3>
        <p class="text-sm text-gray-600 dark:text-gray-400">अपेक्षा आणि माहितीवर आधारित तुलना</p>
    </div>

    {{-- Match Summary Line --}}
    <div class="mb-4">
        <p class="text-lg font-semibold text-gray-800 dark:text-gray-200 mb-1">
            {{ $matchData['summaryText'] }}
        </p>
        @if ($matchData['celebrationText'])
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ $matchData['celebrationText'] }}
        </p>
        @endif
    </div>

    {{-- Common Ground Strip --}}
    @if (!empty($matchData['commonGround']))
    <div class="mb-4 pb-4 border-b border-gray-200 dark:border-gray-700">
        <div class="flex flex-wrap gap-3">
            @foreach ($matchData['commonGround'] as $common)
            <div class="flex items-center gap-1.5 px-3 py-1.5 bg-white dark:bg-gray-700 rounded-full border border-gray-200 dark:border-gray-600">
                <span class="text-base">{{ $common['icon'] }}</span>
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $common['label'] }}</span>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Preference Match List --}}
    @if (!empty($matchData['matches']))
    <div class="mb-4">
        <div class="space-y-2">
            @php
                $matchedItems = array_filter($matchData['matches'], fn($m) => $m['matched']);
                $unmatchedItems = array_filter($matchData['matches'], fn($m) => !$m['matched']);
                $displayedItems = array_merge($matchedItems, array_slice($unmatchedItems, 0, max(0, 5 - count($matchedItems))));
                $hasMore = count($matchData['matches']) > count($displayedItems);
            @endphp

            @foreach ($displayedItems as $match)
            <div class="flex items-center justify-between py-2 px-3 bg-white dark:bg-gray-700 rounded-lg">
                <div class="flex items-center gap-2">
                    <span class="text-lg">{{ $match['icon'] }}</span>
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $match['label'] }}</span>
                </div>
                @if ($match['matched'])
                <span class="text-green-600 dark:text-green-400 text-lg">✔️</span>
                @else
                <span class="text-gray-400 text-sm">—</span>
                @endif
            </div>
            @endforeach

            {{-- Hidden items (if any) --}}
            @if ($hasMore)
            <div x-show="expanded" x-transition class="space-y-2">
                @foreach (array_slice($unmatchedItems, max(0, 5 - count($matchedItems))) as $match)
                <div class="flex items-center justify-between py-2 px-3 bg-white dark:bg-gray-700 rounded-lg">
                    <div class="flex items-center gap-2">
                        <span class="text-lg">{{ $match['icon'] }}</span>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $match['label'] }}</span>
                    </div>
                    <span class="text-gray-400 text-sm">—</span>
                </div>
                @endforeach
            </div>
            @endif
        </div>

        {{-- Expand/Collapse Button --}}
        @if ($hasMore)
        <button 
            @click="expanded = !expanded"
            class="mt-3 text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-300 font-medium">
            <span x-show="!expanded">सर्व अपेक्षा पाहा</span>
            <span x-show="expanded">कमी दाखवा</span>
        </button>
        @endif
    </div>
    @endif

    {{-- Flexibility Message --}}
    <div class="pt-4 border-t border-gray-200 dark:border-gray-700 mb-6">
        <p class="text-sm text-gray-600 dark:text-gray-400 italic">
            काही गोष्टी चर्चा करून ठरवता येऊ शकतात.
        </p>
    </div>

    {{-- Send Interest Button (at bottom of matching section) --}}
    @if (auth()->check() && !$isOwnProfile)
        @if (session('success'))
            <p style="color:green; margin-bottom:1rem;">{{ session('success') }}</p>
        @endif
        @if (session('error'))
            <p style="color:red; margin-bottom:1rem;">{{ session('error') }}</p>
        @endif

        <div class="flex flex-wrap gap-3 justify-center">
            @if ($interestAlreadySent)
                <button disabled style="background-color: #9ca3af; color: white; padding: 12px 24px; border-radius: 6px; font-weight: 600; font-size: 16px; border: none; cursor: not-allowed;">
                    Interest Sent
                </button>
            @else
                <form method="POST" action="{{ route('interests.send', $profile) }}" style="display: inline;">
                    @csrf
                    <button type="submit" style="background-color: #ec4899; color: white; padding: 12px 24px; border-radius: 6px; font-weight: 600; font-size: 16px; border: none; cursor: pointer; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                        Send Interest
                    </button>
                </form>
            @endif

            {{-- Block --}}
            <form method="POST" action="{{ route('blocks.store', $profile) }}" style="display: inline;">
                @csrf
                <button type="submit" style="background-color: #6b7280; color: white; padding: 12px 24px; border-radius: 6px; font-weight: 600; font-size: 16px; border: none; cursor: pointer;">Block</button>
            </form>

            {{-- Shortlist add / remove --}}
            @if ($inShortlist)
                <form method="POST" action="{{ route('shortlist.destroy', $profile) }}" style="display: inline;">
                    @csrf
                    @method('DELETE')
                    <button type="submit" style="background-color: #9ca3af; color: white; padding: 12px 24px; border-radius: 6px; font-weight: 600; font-size: 16px; border: none; cursor: pointer;">Remove from shortlist</button>
                </form>
            @else
                <form method="POST" action="{{ route('shortlist.store', $profile) }}" style="display: inline;">
                    @csrf
                    <button type="submit" style="background-color: #3b82f6; color: white; padding: 12px 24px; border-radius: 6px; font-weight: 600; font-size: 16px; border: none; cursor: pointer;">Add to shortlist</button>
                </form>
            @endif
        </div>
    @endif
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
                    You have already reported this profile. Our team is reviewing it.
                </p>
            </div>
        @else
            <a 
                href="#"
                @click.prevent="showReportForm = !showReportForm"
                style="color:#6b7280; text-decoration:underline; font-size:0.875rem; cursor:pointer;">
                Report profile for abuse
            </a>

            <div x-show="showReportForm" x-transition style="margin-top:1rem; max-width:500px;">
                <form method="POST" action="{{ route('abuse-reports.store', $profile) }}" style="border:1px solid #ccc; padding:1rem;">
                    @csrf
                    <p style="font-weight:600; margin-bottom:8px;">Report this profile for abuse</p>
                    <textarea name="reason" rows="4" required minlength="10" placeholder="Please provide a reason for reporting this profile (minimum 10 characters)" style="width:100%; margin-bottom:10px; padding:8px; border:1px solid #ddd; border-radius:4px;"></textarea>
                    <div class="flex gap-2">
                        <button type="submit" class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-sm text-white tracking-wide hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition disabled:opacity-50 disabled:cursor-not-allowed">Submit Report</button>
                        <button type="button" @click="showReportForm = false" class="inline-flex items-center px-4 py-2 bg-gray-500 border border-transparent rounded-md font-semibold text-sm text-white tracking-wide hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition">Cancel</button>
                    </div>
                </form>
            </div>
        @endif
    </div>
@endif

</div>
@endsection

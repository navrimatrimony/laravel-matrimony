@extends(request()->routeIs('admin.*') ? 'layouts.admin' : 'layouts.app')

@section('content')
<div class="{{ request()->routeIs('admin.*') ? 'bg-white dark:bg-gray-800 shadow rounded-lg p-6' : 'max-w-3xl mx-auto py-8' }}" x-data="{ adminEditMode: @js(auth()->check() && auth()->user()->is_admin === true && request()->has('admin_edit')) }">
    @if (request()->routeIs('admin.*'))
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-1">Admin — Profile #{{ $matrimonyProfile->id }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">{{ $matrimonyProfile->full_name ?? '—' }}@if (!empty($matrimonyProfile->is_demo)) <span class="inline-block ml-2 px-2 py-0.5 text-xs font-semibold bg-sky-100 dark:bg-sky-900/40 text-sky-700 dark:text-sky-300 rounded">Demo</span>@endif</p>
    @else
        <h1 class="text-2xl font-bold mb-6">
            Matrimony Profile
            @if (!empty($matrimonyProfile->is_demo))
                <span class="inline-block ml-2 px-2 py-0.5 text-xs font-semibold bg-sky-100 text-sky-700 rounded">Demo Profile</span>
            @endif
        </h1>
    @endif

@if ($isOwnProfile && $matrimonyProfile->is_suspended)
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

            @if ($matrimonyProfile->profile_photo)
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
            <form method="POST" action="{{ route('admin.profiles.suspend', $matrimonyProfile) }}">
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
            <form method="POST" action="{{ route('admin.profiles.unsuspend', $matrimonyProfile) }}">
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
            <form method="POST" action="{{ route('admin.profiles.soft-delete', $matrimonyProfile) }}">
                @csrf
                <p style="font-weight:600; margin-bottom:8px;">Soft Delete Profile</p>
                <textarea name="reason" rows="3" required minlength="10" placeholder="Reason (minimum 10 characters)" style="width:100%; margin-bottom:8px; padding:8px; border:1px solid #ddd; border-radius:4px;"></textarea>
                <div style="display:flex; gap:8px;">
                    <button type="submit" style="padding:8px 16px; background:#ef4444; color:white; border:none; border-radius:4px; cursor:pointer;">Submit</button>
                    <button type="button" @click="activeAction = null" style="padding:8px 16px; background:#6b7280; color:white; border:none; border-radius:4px; cursor:pointer;">Cancel</button>
                </div>
            </form>
        </div>

        @if ($matrimonyProfile->profile_photo)
        {{-- Approve Image Form --}}
        <div x-show="activeAction === 'approve-image'" x-transition style="border:1px solid #ccc; padding:1rem; border-radius:4px; margin-bottom:1rem; background:#fff;">
            <form method="POST" action="{{ route('admin.profiles.approve-image', $matrimonyProfile) }}">
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
            <form method="POST" action="{{ route('admin.profiles.reject-image', $matrimonyProfile) }}">
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
            <form method="POST" action="{{ route('admin.profiles.override-visibility', $matrimonyProfile) }}">
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
    <form method="POST" action="{{ route('admin.profiles.update', $matrimonyProfile) }}" id="admin-profile-edit-form">
        @csrf
        @method('PUT')
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Full Name</label>
                <input type="text" name="full_name" value="{{ old('full_name', $matrimonyProfile->full_name) }}" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
            </div>
            
            @if ($dateOfBirthVisible)
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Date of Birth</label>
                <input type="date" name="date_of_birth" value="{{ old('date_of_birth', $matrimonyProfile->date_of_birth) }}" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
            </div>
            @endif
            
            @if ($maritalStatusVisible)
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Marital Status</label>
                <select name="marital_status" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                    <option value="">—</option>
                    <option value="single" {{ old('marital_status', $matrimonyProfile->marital_status) === 'single' ? 'selected' : '' }}>Single</option>
                    <option value="divorced" {{ old('marital_status', $matrimonyProfile->marital_status) === 'divorced' ? 'selected' : '' }}>Divorced</option>
                    <option value="widowed" {{ old('marital_status', $matrimonyProfile->marital_status) === 'widowed' ? 'selected' : '' }}>Widowed</option>
                </select>
            </div>
            @endif
            
            @if ($educationVisible)
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Education</label>
                <input type="text" name="education" value="{{ old('education', $matrimonyProfile->education) }}" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
            </div>
            @endif
            
            @if ($locationVisible)
            <div class="space-y-3 md:col-span-2">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Country *</label>
                    <select name="country_id" id="admin_country_id" required class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                        <option value="">Select Country</option>
                        @foreach($countries ?? [] as $country)
                            <option value="{{ $country->id }}" {{ old('country_id', $matrimonyProfile->country_id) == $country->id ? 'selected' : '' }}>{{ $country->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">State *</label>
                    <select name="state_id" id="admin_state_id" required class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                        <option value="">Select State</option>
                        @foreach($states ?? [] as $state)
                            <option value="{{ $state->id }}" {{ old('state_id', $matrimonyProfile->state_id) == $state->id ? 'selected' : '' }}>{{ $state->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">District</label>
                    <select name="district_id" id="admin_district_id" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                        <option value="">Select District</option>
                        @foreach($districts ?? [] as $district)
                            <option value="{{ $district->id }}" {{ old('district_id', $matrimonyProfile->district_id) == $district->id ? 'selected' : '' }}>{{ $district->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Taluka</label>
                    <select name="taluka_id" id="admin_taluka_id" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                        <option value="">Select Taluka</option>
                        @foreach($talukas ?? [] as $taluka)
                            <option value="{{ $taluka->id }}" {{ old('taluka_id', $matrimonyProfile->taluka_id) == $taluka->id ? 'selected' : '' }}>{{ $taluka->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">City *</label>
                    <select name="city_id" id="admin_city_id" required class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                        <option value="">Select City</option>
                        @foreach($cities ?? [] as $city)
                            <option value="{{ $city->id }}" {{ old('city_id', $matrimonyProfile->city_id) == $city->id ? 'selected' : '' }}>{{ $city->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            @endif
            
            @if ($casteVisible)
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Caste</label>
                <input type="text" name="caste" value="{{ old('caste', $matrimonyProfile->caste) }}" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
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
    @if ($matrimonyProfile->profile_photo && $matrimonyProfile->photo_approved !== false)
        {{-- Real uploaded photo --}}
        <img
            src="{{ asset('uploads/matrimony_photos/'.$matrimonyProfile->profile_photo) }}"
            alt="Profile Photo"
            class="w-40 h-40 rounded-full object-cover border"
        />
    @else
        {{-- Gender-based placeholder fallback (UI only) --}}
        @php
            $gender = $matrimonyProfile->gender ?? null;
            if ($gender === 'male') {
                $placeholderSrc = asset('images/placeholders/male-profile.svg');
            } elseif ($gender === 'female') {
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
        @if (!empty($matrimonyProfile->is_demo))
            <span class="text-xs text-gray-500 mt-1">Demo profile</span>
        @endif
    @endif
</div>
@endif

{{-- Name & Gender --}}
<div class="text-center mb-6">
    <h2 class="text-2xl font-semibold">
        {{ $matrimonyProfile->full_name }}
        @if ($isOwnProfile && $matrimonyProfile->admin_edited_fields && in_array('full_name', $matrimonyProfile->admin_edited_fields ?? []))
            <span class="ml-2 text-xs text-amber-600 dark:text-amber-400" title="This field was corrected by admin">(Admin corrected)</span>
        @endif
    </h2>
    <p class="text-gray-500">
        {{ ($matrimonyProfile->gender ?? $matrimonyProfile->user?->gender) ? ucfirst($matrimonyProfile->gender ?? $matrimonyProfile->user?->gender) : '—' }}
    </p>
</div>

@if ($isOwnProfile && $matrimonyProfile->photo_rejection_reason)
    <div style="margin-bottom:1.5rem; padding:1rem; background:#fee2e2; border:1px solid #fca5a5; border-radius:8px; color:#991b1b;">
        <p style="font-weight:600; margin-bottom:0.5rem;">Your profile photo was removed by admin.</p>
        <p style="margin:0;"><strong>Reason:</strong> {{ $matrimonyProfile->photo_rejection_reason }}</p>
    </div>
@endif

{{-- Biodata Grid --}}
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">

    @if ($dateOfBirthVisible)
    <div>
        <p class="text-gray-500 text-sm">Date of Birth</p>
        <p class="font-medium text-base">
            {{ $matrimonyProfile->date_of_birth ?? '—' }}
            @if ($isOwnProfile && $matrimonyProfile->admin_edited_fields && in_array('date_of_birth', $matrimonyProfile->admin_edited_fields ?? []))
                <span class="ml-2 text-xs text-amber-600 dark:text-amber-400" title="This field was corrected by admin">(Admin corrected)</span>
            @endif
        </p>
    </div>
    @endif

    @if ($maritalStatusVisible)
    <div>
        <p class="text-gray-500 text-sm">Marital Status</p>
        <p class="font-medium text-base">
            {{ ($matrimonyProfile->marital_status ?? '') ? ucfirst($matrimonyProfile->marital_status) : '—' }}
            @if ($isOwnProfile && $matrimonyProfile->admin_edited_fields && in_array('marital_status', $matrimonyProfile->admin_edited_fields ?? []))
                <span class="ml-2 text-xs text-amber-600 dark:text-amber-400" title="This field was corrected by admin">(Admin corrected)</span>
            @endif
        </p>
    </div>
    @endif

    @if ($educationVisible)
    <div>
        <p class="text-gray-500 text-sm">Education</p>
        <p class="font-medium text-base">
            {{ $matrimonyProfile->education ?? '—' }}
            @if ($isOwnProfile && $matrimonyProfile->admin_edited_fields && in_array('education', $matrimonyProfile->admin_edited_fields ?? []))
                <span class="ml-2 text-xs text-amber-600 dark:text-amber-400" title="This field was corrected by admin">(Admin corrected)</span>
            @endif
        </p>
    </div>
    @endif

    @if ($locationVisible)
    <div>
        <p class="text-gray-500 text-sm">Location</p>
        <p class="font-medium text-base">
            {{ $matrimonyProfile->location ?? '—' }}
            @if ($isOwnProfile && $matrimonyProfile->admin_edited_fields && in_array('location', $matrimonyProfile->admin_edited_fields ?? []))
                <span class="ml-2 text-xs text-amber-600 dark:text-amber-400" title="This field was corrected by admin">(Admin corrected)</span>
            @endif
        </p>
    </div>
    @endif

    @if ($casteVisible && $matrimonyProfile->caste !== null && $matrimonyProfile->caste !== '')
    <div>
        <p class="text-gray-500 text-sm">Caste</p>
        <p class="font-medium text-base">
            {{ $matrimonyProfile->caste }}
            @if ($isOwnProfile && $matrimonyProfile->admin_edited_fields && in_array('caste', $matrimonyProfile->admin_edited_fields ?? []))
                <span class="ml-2 text-xs text-amber-600 dark:text-amber-400" title="This field was corrected by admin">(Admin corrected)</span>
            @endif
        </p>
    </div>
    @endif

</div>

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
    if ($matrimonyProfile->profile_photo && $matrimonyProfile->photo_approved !== false) {
        $viewedPhotoSrc = asset('uploads/matrimony_photos/'.$matrimonyProfile->profile_photo);
    } else {
        $viewedGender = $matrimonyProfile->gender ?? null;
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
            <span class="text-xs text-gray-600 dark:text-gray-400 mt-1">{{ $matrimonyProfile->full_name }}</span>
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
                <form method="POST" action="{{ route('interests.send', $matrimonyProfile) }}" style="display: inline;">
                    @csrf
                    <button type="submit" style="background-color: #ec4899; color: white; padding: 12px 24px; border-radius: 6px; font-weight: 600; font-size: 16px; border: none; cursor: pointer; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                        Send Interest
                    </button>
                </form>
            @endif

            {{-- Block --}}
            <form method="POST" action="{{ route('blocks.store', $matrimonyProfile) }}" style="display: inline;">
                @csrf
                <button type="submit" style="background-color: #6b7280; color: white; padding: 12px 24px; border-radius: 6px; font-weight: 600; font-size: 16px; border: none; cursor: pointer;">Block</button>
            </form>

            {{-- Shortlist add / remove --}}
            @if ($inShortlist)
                <form method="POST" action="{{ route('shortlist.destroy', $matrimonyProfile) }}" style="display: inline;">
                    @csrf
                    @method('DELETE')
                    <button type="submit" style="background-color: #9ca3af; color: white; padding: 12px 24px; border-radius: 6px; font-weight: 600; font-size: 16px; border: none; cursor: pointer;">Remove from shortlist</button>
                </form>
            @else
                <form method="POST" action="{{ route('shortlist.store', $matrimonyProfile) }}" style="display: inline;">
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
                <form method="POST" action="{{ route('abuse-reports.store', $matrimonyProfile) }}" style="border:1px solid #ccc; padding:1rem;">
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

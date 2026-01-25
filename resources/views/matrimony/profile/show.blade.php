@extends(request()->routeIs('admin.*') ? 'layouts.admin' : 'layouts.app')

@section('content')
<div class="{{ request()->routeIs('admin.*') ? 'bg-white dark:bg-gray-800 shadow rounded-lg p-6' : 'max-w-3xl mx-auto py-8' }}">
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
        <h3 class="text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-3">Moderation</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Profile suspend, unsuspend, soft delete, image approve/reject, visibility override. All actions require a reason.</p>
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

@php
    $completenessPct = \App\Services\ProfileCompletenessService::percentage($matrimonyProfile);
@endphp

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

{{-- Name & Gender --}}
<div class="text-center mb-6">
    <h2 class="text-2xl font-semibold">
        {{ $matrimonyProfile->full_name }}
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

    <div>
        <p class="text-gray-500 text-sm">Date of Birth</p>
        <p class="font-medium text-base">{{ $matrimonyProfile->date_of_birth ?? '—' }}</p>
    </div>

    <div>
        <p class="text-gray-500 text-sm">Marital Status</p>
        <p class="font-medium text-base">{{ ($matrimonyProfile->marital_status ?? '') ? ucfirst($matrimonyProfile->marital_status) : '—' }}</p>
    </div>

    <div>
        <p class="text-gray-500 text-sm">Education</p>
        <p class="font-medium text-base">{{ $matrimonyProfile->education ?? '—' }}</p>
    </div>

    <div>
        <p class="text-gray-500 text-sm">Location</p>
        <p class="font-medium text-base">{{ $matrimonyProfile->location ?? '—' }}</p>
    </div>

    @if ($matrimonyProfile->caste !== null && $matrimonyProfile->caste !== '')
    <div>
        <p class="text-gray-500 text-sm">Caste</p>
        <p class="font-medium text-base">{{ $matrimonyProfile->caste }}</p>
    </div>
    @endif

</div>


</div>

	
	<hr>



<hr>

	
{{-- 🔒 Interest button hidden on own profile --}}

   

@if (auth()->check() && !$isOwnProfile)
    @if (session('success'))
        <p style="color:green; margin-bottom:1rem;">{{ session('success') }}</p>
    @endif
    @if (session('error'))
        <p style="color:red; margin-bottom:1rem;">{{ session('error') }}</p>
    @endif

    @if ($interestAlreadySent)
        <button disabled style="background-color: #9ca3af; color: white; padding: 10px 16px; border-radius: 6px; font-weight: 600; font-size: 14px; border: none; cursor: not-allowed; margin-top: 16px;">
            Interest Sent
        </button>
    @else
        <form method="POST" action="{{ route('interests.send', $matrimonyProfile) }}" style="display: inline;">
            @csrf
            <button type="submit" style="background-color: #ec4899; color: white; padding: 10px 16px; border-radius: 6px; font-weight: 600; font-size: 14px; border: none; cursor: pointer; margin-top: 16px;">
                Send Interest
            </button>
        </form>
    @endif

    {{-- Block --}}
    <form method="POST" action="{{ route('blocks.store', $matrimonyProfile) }}" style="display: inline; margin-left: 8px;">
        @csrf
        <button type="submit" style="background-color: #6b7280; color: white; padding: 10px 16px; border-radius: 6px; font-weight: 600; font-size: 14px; border: none; cursor: pointer;">Block</button>
    </form>

    {{-- Shortlist add / remove --}}
    @if ($inShortlist)
        <form method="POST" action="{{ route('shortlist.destroy', $matrimonyProfile) }}" style="display: inline; margin-left: 8px;">
            @csrf
            @method('DELETE')
            <button type="submit" style="background-color: #9ca3af; color: white; padding: 10px 16px; border-radius: 6px; font-weight: 600; font-size: 14px; border: none; cursor: pointer;">Remove from shortlist</button>
        </form>
    @else
        <form method="POST" action="{{ route('shortlist.store', $matrimonyProfile) }}" style="display: inline; margin-left: 8px;">
            @csrf
            <button type="submit" style="background-color: #3b82f6; color: white; padding: 10px 16px; border-radius: 6px; font-weight: 600; font-size: 14px; border: none; cursor: pointer;">Add to shortlist</button>
        </form>
    @endif

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

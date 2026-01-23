@extends('layouts.app')

@section('content')



<div class="max-w-3xl mx-auto py-8">
    <h1 class="text-2xl font-bold mb-6">
        Matrimony Profile
    </h1>

@if ($isOwnProfile && $matrimonyProfile->is_suspended)
    <div style="margin-bottom:1.5rem; padding:1.25rem; background:#fef3c7; border:2px solid #fbbf24; border-radius:8px; color:#92400e;">
        <p style="font-weight:700; margin:0; font-size:1.1rem;">⚠️ Your profile is currently suspended by admin.</p>
    </div>
@endif

{{-- Admin-only moderation actions --}}
@if (auth()->check() && auth()->user()->is_admin === true)
    <div x-data="{ activeAction: null }" style="margin-bottom:2rem; padding:1rem; background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px;">
        <h3 class="text-lg font-semibold mb-3">Admin Actions</h3>
        {{-- Action Buttons --}}
        <div style="display:flex; flex-wrap:wrap; gap:0.5rem; margin-bottom:1rem;">
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
    </div>
@endif

    
<div class="bg-white shadow rounded-lg p-6">

{{-- Profile Photo --}}
<div class="mb-6 flex justify-center">

    @if ($matrimonyProfile->profile_photo && $matrimonyProfile->photo_approved !== false)
        {{-- Uploaded and approved profile photo --}}
        <img
            src="{{ asset('uploads/matrimony_photos/'.$matrimonyProfile->profile_photo) }}"
            alt="Profile Photo"
            class="w-40 h-40 rounded-full object-cover border"
        />
    @else
        {{-- Default placeholder photo (no upload yet or rejected) --}}
        <img
            src="{{ asset('images/default-profile.png') }}"
            alt="Default Profile Photo"
            class="w-40 h-40 rounded-full object-cover border opacity-70"
        />
    @endif

</div>



{{-- Name & Gender --}}
<div class="text-center mb-6">
    <h2 class="text-2xl font-semibold">
        {{ $matrimonyProfile->full_name }}
    </h2>
    <p class="text-gray-500">
        {{ ucfirst($matrimonyProfile->gender) }}
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
        <p class="font-medium text-base">{{ $matrimonyProfile->date_of_birth }}</p>
    </div>

    <div>
        <p class="text-gray-500 text-sm">Education</p>
        <p class="font-medium text-base">{{ $matrimonyProfile->education }}</p>
    </div>

    <div>
        <p class="text-gray-500 text-sm">Location</p>
        <p class="font-medium text-base">{{ $matrimonyProfile->location }}</p>
    </div>

    <div>
        <p class="text-gray-500 text-sm">Caste</p>
        <p class="font-medium text-base">{{ $matrimonyProfile->caste }}</p>
    </div>

</div>


</div>

	
	<hr>



<hr>

	
{{-- 🔒 Interest button hidden on own profile --}}

   

@if (auth()->check() && !$isOwnProfile)

    @if ($interestAlreadySent)
        <button disabled
            style="margin-top:15px; padding:10px; background:#9ca3af; color:white; border:none;">
            Interest Sent
        </button>
    @else
        <form method="POST" action="{{ route('interests.send', $matrimonyProfile) }}">

            @csrf
            <button type="submit"
                style="margin-top:15px; padding:10px; background:#ec4899; color:white; border:none;">
                Send Interest
            </button>
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
                    <div style="display:flex; gap:8px;">
                        <button type="submit" style="padding:8px 16px; background:#dc2626; color:white; border:none; border-radius:4px; cursor:pointer;">Submit Report</button>
                        <button type="button" @click="showReportForm = false" style="padding:8px 16px; background:#6b7280; color:white; border:none; border-radius:4px; cursor:pointer;">Cancel</button>
                    </div>
                </form>
            </div>
        @endif
    </div>
@endif

</div>
@endsection

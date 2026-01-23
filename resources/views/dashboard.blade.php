@extends('layouts.app')

@section('content')

<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

        {{-- No Profile Case --}}
        @if (!auth()->user()->matrimonyProfile)

            <div class="bg-white shadow-sm rounded-lg p-6">
                <h3 class="text-xl font-semibold mb-4">
                    Welcome! Let's create your Matrimony Profile
                </h3>

                <p class="mb-6 text-gray-600">
                    You haven't created your matrimony profile yet.
                </p>

                <a href="{{ route('matrimony.profile.create') }}"
                   class="inline-block px-6 py-3 bg-red-600 text-white rounded hover:bg-red-700 transition">
                    Create Matrimony Profile
                </a>
            </div>

        @else

            @php
                $profile = auth()->user()->matrimonyProfile;
                $myProfileId = $profile->id;

                // Statistics
                $sentInterestsCount = \App\Models\Interest::where('sender_profile_id', $myProfileId)->count();
                $receivedPendingCount = \App\Models\Interest::where('receiver_profile_id', $myProfileId)->where('status', 'pending')->count();
                $acceptedInterestsCount = \App\Models\Interest::where('receiver_profile_id', $myProfileId)->where('status', 'accepted')->count();
                $rejectedInterestsCount = \App\Models\Interest::where('receiver_profile_id', $myProfileId)->where('status', 'rejected')->count();
                $totalProfilesCount = \App\Models\MatrimonyProfile::where('id', '!=', $myProfileId)->count();

                // Profile Completeness Calculation
                $fields = [
                    'full_name' => $profile->full_name,
                    'date_of_birth' => $profile->date_of_birth,
                    'gender' => $profile->gender,
                    'caste' => $profile->caste,
                    'education' => $profile->education,
                    'location' => $profile->location,
                    'profile_photo' => $profile->profile_photo,
                ];
                $filledFields = count(array_filter($fields));
                $totalFields = count($fields);
                $completenessPercentage = round(($filledFields / $totalFields) * 100);

                // Recent Interests (Last 3 received)
                $recentReceivedInterests = \App\Models\Interest::with('senderProfile')
                    ->where('receiver_profile_id', $myProfileId)
                    ->latest()
                    ->limit(3)
                    ->get();

                // Recent Sent Interests (Last 3)
                $recentSentInterests = \App\Models\Interest::with('receiverProfile')
                    ->where('sender_profile_id', $myProfileId)
                    ->latest()
                    ->limit(3)
                    ->get();
            @endphp

            {{-- Welcome Section --}}
            <div class="mb-6">
                <h2 class="text-3xl font-bold text-gray-900 mb-2">
                    Welcome back, {{ $profile->full_name }}!
                </h2>
                <p class="text-gray-600">
                    Here's your matrimony dashboard overview.
                </p>
            </div>

            {{-- Photo Rejection Alert --}}
            @if ($profile->photo_rejection_reason)
                <div style="margin-bottom:1.5rem; padding:1.25rem; background:#fee2e2; border:2px solid #fca5a5; border-radius:8px; color:#991b1b;">
                    <p style="font-weight:700; margin-bottom:0.5rem; font-size:1.1rem;">Your profile photo was removed by admin.</p>
                    <p style="margin:0; font-size:0.95rem;"><strong>Reason:</strong> {{ $profile->photo_rejection_reason }}</p>
                    <p style="margin-top:0.75rem; margin-bottom:0; font-size:0.875rem; color:#7f1d1d;">
                        Please upload a new photo that meets our guidelines.
                    </p>
                </div>
            @endif

            {{-- Profile Summary Card with Photo --}}
            <div class="bg-white rounded-lg shadow-lg p-6 mb-6 border-l-4 border-red-600">
                <div class="flex flex-col md:flex-row items-center md:items-start gap-6">
                    
                    {{-- Profile Photo --}}
                    <div class="flex-shrink-0">
                        @if ($profile->profile_photo && $profile->photo_approved !== false)
                            <img src="{{ asset('uploads/matrimony_photos/'.$profile->profile_photo) }}" 
                                 alt="Profile Photo" 
                                 class="w-24 h-24 rounded-full object-cover border-4 border-red-200 shadow-md">
                        @else
                            <img src="{{ asset('images/default-profile.png') }}" 
                                 alt="Default Photo" 
                                 class="w-24 h-24 rounded-full object-cover border-4 border-red-200 shadow-md opacity-70">
                        @endif
                    </div>

                    {{-- Profile Info --}}
                    <div class="flex-1 text-center md:text-left">
                        <h3 class="text-2xl font-bold text-gray-900 mb-2">{{ $profile->full_name }}</h3>
                        <div class="flex flex-wrap gap-3 text-sm text-gray-600 mb-4 justify-center md:justify-start">
                            @if ($profile->gender)
                                <span class="bg-red-50 text-red-700 px-3 py-1 rounded-full">{{ ucfirst($profile->gender) }}</span>
                            @endif
                            @if ($profile->date_of_birth)
                                <span class="bg-red-50 text-red-700 px-3 py-1 rounded-full">{{ \Carbon\Carbon::parse($profile->date_of_birth)->age }} yrs</span>
                            @endif
                            @if ($profile->location)
                                <span class="bg-red-50 text-red-700 px-3 py-1 rounded-full">{{ ucfirst($profile->location) }}</span>
                            @endif
                            @if ($profile->education)
                                <span class="bg-red-50 text-red-700 px-3 py-1 rounded-full">{{ ucfirst($profile->education) }}</span>
                            @endif
                        </div>

                        {{-- Profile Completeness Progress Bar --}}
                        <div class="mb-4">
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-sm font-medium text-gray-700">Profile Completeness</span>
                                <span class="text-sm font-bold text-red-600">{{ $completenessPercentage }}%</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-3">
                                <div class="bg-red-600 h-3 rounded-full transition-all duration-300" 
                                     style="width: {{ $completenessPercentage }}%"></div>
                            </div>
                            @if ($completenessPercentage < 100)
                                <p class="text-xs text-gray-500 mt-1">
                                    Complete your profile to get better matches
                                </p>
                            @endif
                        </div>

                        {{-- Quick Action Buttons --}}
                        <div class="flex flex-wrap gap-3 justify-center md:justify-start">
                            <a href="{{ route('matrimony.profile.show', $profile->id) }}"
                               class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition text-sm font-medium">
                                View My Profile
                            </a>
                            <a href="{{ route('matrimony.profile.edit') }}"
                               class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition text-sm font-medium">
                                Edit Profile
                            </a>
                            @if (!$profile->profile_photo)
                                <a href="{{ route('matrimony.profile.upload-photo') }}"
                                   class="px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 transition text-sm font-medium">
                                    Upload Photo
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            {{-- Statistics Cards with SVG Icons --}}
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                
                {{-- Interests Sent --}}
                <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-red-500 hover:shadow-lg transition">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600 mb-1">Interests Sent</p>
                            <p class="text-3xl font-bold text-gray-900">{{ $sentInterestsCount }}</p>
                        </div>
                        <div class="bg-red-100 p-3 rounded-full">
                            <svg class="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                            </svg>
                        </div>
                    </div>
                    <a href="{{ route('interests.sent') }}" class="text-sm text-red-600 hover:underline mt-3 block">
                        View all →
                    </a>
                </div>

                {{-- Pending Received --}}
                <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-yellow-500 hover:shadow-lg transition">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600 mb-1">Pending Interests</p>
                            <p class="text-3xl font-bold text-yellow-600">{{ $receivedPendingCount }}</p>
                        </div>
                        <div class="bg-yellow-100 p-3 rounded-full">
                            <svg class="w-8 h-8 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                            </svg>
                        </div>
                    </div>
                    <a href="{{ route('interests.received') }}" class="text-sm text-yellow-600 hover:underline mt-3 block">
                        View all →
                    </a>
                </div>

                {{-- Accepted Interests --}}
                <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-green-500 hover:shadow-lg transition">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600 mb-1">Accepted</p>
                            <p class="text-3xl font-bold text-green-600">{{ $acceptedInterestsCount }}</p>
                        </div>
                        <div class="bg-green-100 p-3 rounded-full">
                            <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                    </div>
                </div>

                {{-- Total Profiles --}}
                <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-purple-500 hover:shadow-lg transition">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600 mb-1">Total Profiles</p>
                            <p class="text-3xl font-bold text-purple-600">{{ $totalProfilesCount }}</p>
                        </div>
                        <div class="bg-purple-100 p-3 rounded-full">
                            <svg class="w-8 h-8 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                            </svg>
                        </div>
                    </div>
                    <a href="{{ route('matrimony.profiles.index') }}" class="text-sm text-purple-600 hover:underline mt-3 block">
                        Search profiles →
                    </a>
                </div>

            </div>

            {{-- Recent Interests Preview --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                
                {{-- Recent Received Interests --}}
                <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-red-500">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold text-gray-900">Recent Received Interests</h3>
                        <a href="{{ route('interests.received') }}" class="text-sm text-red-600 hover:underline">
                            View all →
                        </a>
                    </div>

                    @forelse ($recentReceivedInterests as $interest)
                        <div class="border-b border-gray-200 pb-3 mb-3 last:border-0 last:mb-0 last:pb-0">
                            <div class="flex items-center gap-3">
                                {{-- Sender Photo - Smaller and Round --}}
                                <div class="flex-shrink-0">
                                    @if ($interest->senderProfile && $interest->senderProfile->profile_photo && $interest->senderProfile->photo_approved !== false)
                                        <img src="{{ asset('uploads/matrimony_photos/'.$interest->senderProfile->profile_photo) }}" 
                                             alt="Profile" 
                                             class="w-14 h-14 rounded-full object-cover border-2 border-red-200">
                                    @else
                                        <img src="{{ asset('images/default-profile.png') }}" 
                                             alt="Default" 
                                             class="w-14 h-14 rounded-full object-cover border-2 border-red-200 opacity-70">
                                    @endif
                                </div>

                                {{-- Sender Info - Better alignment --}}
                                <div class="flex-1 min-w-0">
                                    <p class="font-semibold text-gray-900 truncate">
                                        {{ $interest->senderProfile->full_name ?? 'Profile Deleted' }}
                                    </p>
                                    <div class="flex items-center gap-2 mt-1 flex-wrap">
                                        @if ($interest->status === 'pending')
                                            <span class="text-xs bg-yellow-100 text-yellow-700 px-2 py-1 rounded">Pending</span>
                                        @elseif ($interest->status === 'accepted')
                                            <span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded">Accepted</span>
                                        @elseif ($interest->status === 'rejected')
                                            <span class="text-xs bg-red-100 text-red-700 px-2 py-1 rounded">Rejected</span>
                                        @endif
                                        <span class="text-xs text-gray-500 whitespace-nowrap">{{ $interest->created_at->diffForHumans() }}</span>
                                    </div>
                                </div>

                                {{-- View Profile Link --}}
                                @if ($interest->senderProfile)
                                    <div class="flex-shrink-0">
                                        <a href="{{ route('matrimony.profile.show', $interest->senderProfile->id) }}"
                                           class="text-sm text-red-600 hover:underline whitespace-nowrap">
                                            View →
                                        </a>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-gray-500 text-sm">No received interests yet.</p>
                    @endforelse
                </div>

                {{-- Recent Sent Interests --}}
                <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-red-500">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold text-gray-900">Recent Sent Interests</h3>
                        <a href="{{ route('interests.sent') }}" class="text-sm text-red-600 hover:underline">
                            View all →
                        </a>
                    </div>

                    @forelse ($recentSentInterests as $interest)
                        <div class="border-b border-gray-200 pb-3 mb-3 last:border-0 last:mb-0 last:pb-0">
                            <div class="flex items-center gap-3">
                                {{-- Receiver Photo - Smaller and Round --}}
                                <div class="flex-shrink-0">
                                    @if ($interest->receiverProfile && $interest->receiverProfile->profile_photo && $interest->receiverProfile->photo_approved !== false)
                                        <img src="{{ asset('uploads/matrimony_photos/'.$interest->receiverProfile->profile_photo) }}" 
                                             alt="Profile" 
                                             class="w-14 h-14 rounded-full object-cover border-2 border-red-200">
                                    @else
                                        <img src="{{ asset('images/default-profile.png') }}" 
                                             alt="Default" 
                                             class="w-14 h-14 rounded-full object-cover border-2 border-red-200 opacity-70">
                                    @endif
                                </div>

                                {{-- Receiver Info - Better alignment --}}
                                <div class="flex-1 min-w-0">
                                    <p class="font-semibold text-gray-900 truncate">
                                        {{ $interest->receiverProfile->full_name ?? 'Profile Deleted' }}
                                    </p>
                                    <div class="flex items-center gap-2 mt-1 flex-wrap">
                                        @if ($interest->status === 'pending')
                                            <span class="text-xs bg-yellow-100 text-yellow-700 px-2 py-1 rounded">Pending</span>
                                        @elseif ($interest->status === 'accepted')
                                            <span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded">Accepted</span>
                                        @elseif ($interest->status === 'rejected')
                                            <span class="text-xs bg-red-100 text-red-700 px-2 py-1 rounded">Rejected</span>
                                        @endif
                                        <span class="text-xs text-gray-500 whitespace-nowrap">{{ $interest->created_at->diffForHumans() }}</span>
                                    </div>
                                </div>

                                {{-- View Profile Link --}}
                                @if ($interest->receiverProfile)
                                    <div class="flex-shrink-0">
                                        <a href="{{ route('matrimony.profile.show', $interest->receiverProfile->id) }}"
                                           class="text-sm text-red-600 hover:underline whitespace-nowrap">
                                            View →
                                        </a>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-gray-500 text-sm">No sent interests yet.</p>
                    @endforelse
                </div>

            </div>

            {{-- Quick Actions - Horizontal Single Line --}}
            <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-red-500">
                <h3 class="text-lg font-bold text-gray-900 mb-4">Quick Actions</h3>
                <div class="flex flex-row gap-4 overflow-x-auto">
                    <a href="{{ route('matrimony.profiles.index') }}"
                       class="flex-shrink-0 p-4 border-2 border-red-200 rounded-lg hover:bg-red-50 hover:border-red-400 transition text-center min-w-[200px]">
                        <svg class="w-8 h-8 text-red-600 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        <p class="font-medium text-gray-900">Search Profiles</p>
                        <p class="text-sm text-gray-600">Find your perfect match</p>
                    </a>
                    
                    <a href="{{ route('interests.received') }}"
                       class="flex-shrink-0 p-4 border-2 border-red-200 rounded-lg hover:bg-red-50 hover:border-red-400 transition text-center min-w-[200px]">
                        <svg class="w-8 h-8 text-red-600 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                        </svg>
                        <p class="font-medium text-gray-900">Received Interests</p>
                        <p class="text-sm text-gray-600">
                            @if ($receivedPendingCount > 0)
                                {{ $receivedPendingCount }} pending
                            @else
                                No pending interests
                            @endif
                        </p>
                    </a>
                    
                    <a href="{{ route('interests.sent') }}"
                       class="flex-shrink-0 p-4 border-2 border-red-200 rounded-lg hover:bg-red-50 hover:border-red-400 transition text-center min-w-[200px]">
                        <svg class="w-8 h-8 text-red-600 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                        </svg>
                        <p class="font-medium text-gray-900">Sent Interests</p>
                        <p class="text-sm text-gray-600">{{ $sentInterestsCount }} total sent</p>
                    </a>
                </div>
            </div>

        @endif

    </div>
</div>

@endsection
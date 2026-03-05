@extends('layouts.app')

@section('content')

<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

        {{-- No Profile Case --}}
            @if (!$hasProfile)

            <div class="bg-white dark:bg-gray-800 shadow-sm rounded-lg p-6 border border-gray-200 dark:border-gray-700">
                <h3 class="text-xl font-semibold mb-4 text-gray-900 dark:text-gray-100">
                    Welcome! Let's create your Matrimony Profile
                </h3>

                <p class="mb-6 text-gray-600 dark:text-gray-400">
                    You haven't created your matrimony profile yet.
                </p>

                <div class="flex flex-wrap gap-3">
                    <a href="{{ route('matrimony.profile.wizard', 'basic-info') }}"
                       class="inline-block px-6 py-3 bg-red-600 text-white rounded hover:bg-red-700 transition">
                        Create Matrimony Profile
                    </a>
                    <a href="{{ route('intake.index') }}"
                       class="inline-block px-6 py-3 bg-gray-600 text-white rounded hover:bg-gray-700 transition">
                        My biodata uploads
                    </a>
                </div>
            </div>

        @else

            {{-- Welcome Section --}}
            <div class="mb-6">
                <h2 class="text-3xl font-bold text-gray-900 dark:text-gray-100 mb-2">
                    Welcome back, {{ $profile->full_name }}!
                </h2>
                <p class="text-gray-600 dark:text-gray-400">
                    Here's your matrimony dashboard overview.
                </p>
            </div>

            {{-- Photo Rejection Alert --}}
            @if ($profile->photo_rejection_reason)
                <div class="mb-6 p-5 bg-red-50 dark:bg-red-900/20 border-2 border-red-200 dark:border-red-800 rounded-lg text-red-900 dark:text-red-200">
                    <p class="font-bold mb-1 text-lg">Your profile photo was removed by admin.</p>
                    <p class="text-sm"><strong>Reason:</strong> {{ $profile->photo_rejection_reason }}</p>
                    <p class="mt-2 text-sm text-red-800 dark:text-red-300">Please upload a new photo that meets our guidelines.</p>
                </div>
            @endif

            {{-- Draft / Conflict pending banner --}}
            @if (($profile->lifecycle_state ?? null) === 'draft')
                <div class="mb-6 p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-lg text-amber-900 dark:text-amber-200">
                    <p class="font-semibold">Complete your profile so it can go live.</p>
                    <a href="{{ route('matrimony.profile.wizard.section', ['section' => 'full']) }}" class="text-sm text-amber-700 dark:text-amber-300 underline mt-1 inline-block">Edit profile →</a>
                </div>
            @endif
            @if (($profile->lifecycle_state ?? null) === 'conflict_pending')
                <div class="mb-6 p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-lg text-amber-900 dark:text-amber-200">
                    <p class="font-semibold">Your profile has pending changes. Admin will resolve shortly.</p>
                    <a href="{{ route('matrimony.profile.show', $profile->id) }}" class="text-sm text-amber-700 dark:text-amber-300 underline mt-1 inline-block">View profile →</a>
                </div>
            @endif

            {{-- Profile Summary Card with Photo --}}
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-6 mb-6 border-l-4 border-red-600 border border-gray-200 dark:border-gray-700">
                <div class="flex flex-col md:flex-row items-center md:items-start gap-6">
                    
                    {{-- Profile Photo with Gender-based Fallback --}}
                    <div class="flex-shrink-0">
                        @if ($profile->profile_photo && $profile->photo_approved !== false)
                            <img src="{{ asset('uploads/matrimony_photos/'.$profile->profile_photo) }}" 
                                 alt="Profile Photo" 
                                 class="w-24 h-24 rounded-full object-cover border-4 border-red-200 shadow-md">
                        @else
                            @php
                                $gender = $profile->gender?->key ?? null;
                                if ($gender === 'male') {
                                    $placeholderSrc = asset('images/placeholders/male-profile.svg');
                                } elseif ($gender === 'female') {
                                    $placeholderSrc = asset('images/placeholders/female-profile.svg');
                                } else {
                                    $placeholderSrc = asset('images/placeholders/default-profile.svg');
                                }
                            @endphp
                            <img src="{{ $placeholderSrc }}" 
                                 alt="Profile Placeholder" 
                                 class="w-24 h-24 rounded-full object-cover border-4 border-red-200 shadow-md">
                        @endif
                        @if ($profile->profile_photo && $profile->photo_approved === false && empty($profile->photo_rejected_at))
                            <p class="text-xs text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/30 mt-2 px-2 py-1 rounded">Your photo is under review.</p>
                        @endif
                    </div>

                    {{-- Profile Info --}}
                    <div class="flex-1 text-center md:text-left">
                        <h3 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-2">{{ $profile->full_name }}</h3>
                        <div class="flex flex-wrap gap-3 text-sm text-gray-600 dark:text-gray-400 mb-4 justify-center md:justify-start">
                            @if ($profile->gender?->label ?? $profile->gender?->key)
                                <span class="bg-red-50 text-red-700 px-3 py-1 rounded-full">{{ $profile->gender?->label ?? ucfirst($profile->gender?->key ?? '') }}</span>
                            @endif
                            @if ($profile->date_of_birth)
                                <span class="bg-red-50 text-red-700 px-3 py-1 rounded-full">{{ \Carbon\Carbon::parse($profile->date_of_birth)->age }} yrs</span>
                            @endif
                            @if ($profile->city?->name ?? $profile->state?->name)
                                <span class="bg-red-50 text-red-700 px-3 py-1 rounded-full">{{ $profile->city?->name ?? $profile->state?->name }}</span>
                            @endif
                            @if ($profile->highest_education)
                                <span class="bg-red-50 text-red-700 px-3 py-1 rounded-full">{{ ucfirst($profile->highest_education) }}</span>
                            @endif
                        </div>

                        {{-- Profile Completeness Progress Bar --}}
                        <div class="mb-4">
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Profile Completeness</span>
                                <span class="text-sm font-bold text-red-600 dark:text-red-400">{{ $completenessPercentage }}%</span>
                            </div>
                            <div class="w-full bg-gray-200 dark:bg-gray-600 rounded-full h-3">
                                <div class="bg-red-600 dark:bg-red-500 h-3 rounded-full transition-all duration-300" 
                                     style="width: {{ $completenessPercentage }}%"></div>
                            </div>
                            @if ($completenessPercentage < 100)
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                    <a href="{{ route('matrimony.profile.wizard.section', ['section' => 'full']) }}" class="text-red-600 dark:text-red-400 hover:underline">Complete your profile</a> to get better matches.
                                </p>
                            @endif
                        </div>

                        {{-- Quick Action Buttons --}}
                        <div class="flex flex-wrap gap-3 justify-center md:justify-start">
                            <a href="{{ route('matrimony.profile.show', $profile->id) }}"
                               class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition text-sm font-medium">
                                View My Profile
                            </a>
                            <a href="{{ route('matrimony.profile.wizard.section', ['section' => 'full']) }}"
                               class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition text-sm font-medium">
                                Edit Profile
                            </a>
                            <a href="{{ route('matrimony.profile.upload-photo') }}"
                               class="px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 transition text-sm font-medium">
                                {{ $profile->profile_photo ? 'Change Photo' : 'Upload Photo' }}
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Statistics Cards with SVG Icons --}}
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4 mb-6">
                
                {{-- Interests Sent --}}
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 border-l-4 border-red-500 hover:shadow-lg transition border border-gray-200 dark:border-gray-700">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600 dark:text-gray-400 mb-1">Interests Sent</p>
                            <p class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ $sentInterestsCount }}</p>
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
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 border-l-4 border-yellow-500 hover:shadow-lg transition border border-gray-200 dark:border-gray-700">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600 dark:text-gray-400 mb-1">Pending Interests</p>
                            <p class="text-3xl font-bold text-yellow-600 dark:text-yellow-400">{{ $receivedPendingCount }}</p>
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
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 border-l-4 border-green-500 hover:shadow-lg transition border border-gray-200 dark:border-gray-700">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600 dark:text-gray-400 mb-1">Accepted</p>
                            <p class="text-3xl font-bold text-green-600 dark:text-green-400">{{ $acceptedInterestsCount }}</p>
                        </div>
                        <div class="bg-green-100 p-3 rounded-full">
                            <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                    </div>
                    <a href="{{ route('interests.received') }}" class="text-sm text-green-600 hover:underline mt-3 block">View all →</a>
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

                {{-- Shortlist --}}
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 border-l-4 border-indigo-500 hover:shadow-lg transition border border-gray-200 dark:border-gray-700">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600 dark:text-gray-400 mb-1">Shortlist</p>
                            <p class="text-3xl font-bold text-indigo-600 dark:text-indigo-400">{{ $shortlistCount }}</p>
                        </div>
                        <div class="bg-indigo-100 p-3 rounded-full">
                            <svg class="w-8 h-8 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                            </svg>
                        </div>
                    </div>
                    <a href="{{ route('shortlist.index') }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline mt-3 block">
                        View shortlist →
                    </a>
                </div>

            </div>

            {{-- Recent Interests Preview --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                
                {{-- Recent Received Interests --}}
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 border-l-4 border-red-500 border border-gray-200 dark:border-gray-700">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">Recent Received Interests</h3>
                        <a href="{{ route('interests.received') }}" class="text-sm text-red-600 dark:text-red-400 hover:underline">
                            View all →
                        </a>
                    </div>

                    @forelse ($recentReceivedInterests as $interest)
                        <div class="border-b border-gray-200 dark:border-gray-600 pb-3 mb-3 last:border-0 last:mb-0 last:pb-0">
                            <div class="flex items-center gap-3">
                                {{-- Sender Photo with Gender-based Fallback --}}
                                <div class="flex-shrink-0">
                                    @if ($interest->senderProfile && $interest->senderProfile->profile_photo && $interest->senderProfile->photo_approved !== false)
                                        <img src="{{ asset('uploads/matrimony_photos/'.$interest->senderProfile->profile_photo) }}" 
                                             alt="Profile" 
                                             class="w-14 h-14 rounded-full object-cover border-2 border-red-200">
                                    @else
                                        @php
                                            $sG = $interest->senderProfile->gender?->key ?? null;
                                            $sP = $sG === 'male' ? asset('images/placeholders/male-profile.svg') : ($sG === 'female' ? asset('images/placeholders/female-profile.svg') : asset('images/placeholders/default-profile.svg'));
                                        @endphp
                                        <img src="{{ $sP }}" 
                                             alt="Placeholder" 
                                             class="w-14 h-14 rounded-full object-cover border-2 border-red-200">
                                    @endif
                                </div>

                                {{-- Sender Info - Better alignment --}}
                                <div class="flex-1 min-w-0">
                                    <p class="font-semibold text-gray-900 dark:text-gray-100 truncate">
                                        {{ $interest->senderProfile->full_name ?? 'Profile Deleted' }}
                                    </p>
                                    <div class="flex items-center gap-2 mt-1 flex-wrap">
                                        @if ($interest->status === 'pending')
                                            <span class="text-xs bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-300 px-2 py-1 rounded">Pending</span>
                                        @elseif ($interest->status === 'accepted')
                                            <span class="text-xs bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300 px-2 py-1 rounded">Accepted</span>
                                        @elseif ($interest->status === 'rejected')
                                            <span class="text-xs bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 px-2 py-1 rounded">Rejected</span>
                                        @endif
                                        <span class="text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">{{ $interest->created_at->diffForHumans() }}</span>
                                    </div>
                                </div>

                                {{-- View Profile Link --}}
                                @if ($interest->senderProfile)
                                    <div class="flex-shrink-0">
                                        <a href="{{ route('matrimony.profile.show', $interest->senderProfile->id) }}"
                                           class="text-sm text-red-600 dark:text-red-400 hover:underline whitespace-nowrap">
                                            View →
                                        </a>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-gray-500 dark:text-gray-400 text-sm">No received interests yet.</p>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Complete your profile and search to get more visibility.</p>
                    @endforelse
                </div>

                {{-- Recent Sent Interests --}}
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 border-l-4 border-red-500 border border-gray-200 dark:border-gray-700">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">Recent Sent Interests</h3>
                        <a href="{{ route('interests.sent') }}" class="text-sm text-red-600 dark:text-red-400 hover:underline">
                            View all →
                        </a>
                    </div>

                    @forelse ($recentSentInterests as $interest)
                        <div class="border-b border-gray-200 dark:border-gray-600 pb-3 mb-3 last:border-0 last:mb-0 last:pb-0">
                            <div class="flex items-center gap-3">
                                {{-- Receiver Photo with Gender-based Fallback --}}
                                <div class="flex-shrink-0">
                                    @if ($interest->receiverProfile && $interest->receiverProfile->profile_photo && $interest->receiverProfile->photo_approved !== false)
                                        <img src="{{ asset('uploads/matrimony_photos/'.$interest->receiverProfile->profile_photo) }}" 
                                             alt="Profile" 
                                             class="w-14 h-14 rounded-full object-cover border-2 border-red-200">
                                    @else
                                        @php
                                            $rG = $interest->receiverProfile->gender?->key ?? null;
                                            $rP = $rG === 'male' ? asset('images/placeholders/male-profile.svg') : ($rG === 'female' ? asset('images/placeholders/female-profile.svg') : asset('images/placeholders/default-profile.svg'));
                                        @endphp
                                        <img src="{{ $rP }}" 
                                             alt="Placeholder" 
                                             class="w-14 h-14 rounded-full object-cover border-2 border-red-200">
                                    @endif
                                </div>

                                {{-- Receiver Info - Better alignment --}}
                                <div class="flex-1 min-w-0">
                                    <p class="font-semibold text-gray-900 dark:text-gray-100 truncate">
                                        {{ $interest->receiverProfile->full_name ?? 'Profile Deleted' }}
                                    </p>
                                    <div class="flex items-center gap-2 mt-1 flex-wrap">
                                        @if ($interest->status === 'pending')
                                            <span class="text-xs bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-300 px-2 py-1 rounded">Pending</span>
                                        @elseif ($interest->status === 'accepted')
                                            <span class="text-xs bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300 px-2 py-1 rounded">Accepted</span>
                                        @elseif ($interest->status === 'rejected')
                                            <span class="text-xs bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 px-2 py-1 rounded">Rejected</span>
                                        @endif
                                        <span class="text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">{{ $interest->created_at->diffForHumans() }}</span>
                                    </div>
                                </div>

                                {{-- View Profile Link --}}
                                @if ($interest->receiverProfile)
                                    <div class="flex-shrink-0">
                                        <a href="{{ route('matrimony.profile.show', $interest->receiverProfile->id) }}"
                                           class="text-sm text-red-600 dark:text-red-400 hover:underline whitespace-nowrap">
                                            View →
                                        </a>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-gray-500 dark:text-gray-400 text-sm">No sent interests yet.</p>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Search profiles to send interest.</p>
                    @endforelse
                </div>

            </div>

            {{-- Quick Actions - Horizontal Single Line --}}
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 border-l-4 border-red-500 border border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100 mb-4">Quick Actions</h3>
                <div class="flex flex-row gap-4 overflow-x-auto">
                    <a href="{{ route('intake.index') }}"
                       class="flex-shrink-0 p-4 border-2 border-red-200 dark:border-red-800 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20 hover:border-red-400 dark:hover:border-red-600 transition text-center min-w-[200px]">
                        <svg class="w-8 h-8 text-red-600 dark:text-red-400 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <p class="font-medium text-gray-900 dark:text-gray-100">My biodata uploads</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Intake history &amp; status</p>
                    </a>
                    <a href="{{ route('matrimony.profiles.index') }}"
                       class="flex-shrink-0 p-4 border-2 border-red-200 dark:border-red-800 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20 hover:border-red-400 dark:hover:border-red-600 transition text-center min-w-[200px]">
                        <svg class="w-8 h-8 text-red-600 dark:text-red-400 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        <p class="font-medium text-gray-900 dark:text-gray-100">Search Profiles</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Find your perfect match</p>
                    </a>
                    
                    <a href="{{ route('interests.received') }}"
                       class="flex-shrink-0 p-4 border-2 border-red-200 dark:border-red-800 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20 hover:border-red-400 dark:hover:border-red-600 transition text-center min-w-[200px]">
                        <svg class="w-8 h-8 text-red-600 dark:text-red-400 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                        </svg>
                        <p class="font-medium text-gray-900 dark:text-gray-100">Received Interests</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            @if ($receivedPendingCount > 0)
                                {{ $receivedPendingCount }} pending
                            @else
                                No pending interests
                            @endif
                        </p>
                    </a>
                    
                    <a href="{{ route('interests.sent') }}"
                       class="flex-shrink-0 p-4 border-2 border-red-200 dark:border-red-800 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20 hover:border-red-400 dark:hover:border-red-600 transition text-center min-w-[200px]">
                        <svg class="w-8 h-8 text-red-600 dark:text-red-400 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                        </svg>
                        <p class="font-medium text-gray-900 dark:text-gray-100">Sent Interests</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400">{{ $sentInterestsCount }} total sent</p>
                    </a>
                    <a href="{{ route('shortlist.index') }}"
                       class="flex-shrink-0 p-4 border-2 border-red-200 dark:border-red-800 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20 hover:border-red-400 transition text-center min-w-[200px]">
                        <svg class="w-8 h-8 text-red-600 dark:text-red-400 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                        </svg>
                        <p class="font-medium text-gray-900 dark:text-gray-100">Shortlist</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400">{{ $shortlistCount }} {{ $shortlistCount === 1 ? 'profile' : 'profiles' }}</p>
                    </a>
                    @if ($mobileVerified)
                    <div class="flex-shrink-0 p-4 border-2 border-green-200 dark:border-green-700 rounded-lg bg-green-50 dark:bg-green-900/20 text-center min-w-[200px]">
                        <svg class="w-8 h-8 text-green-600 dark:text-green-400 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <p class="font-medium text-gray-900 dark:text-gray-100">Mobile verified</p>
                        <p class="text-sm text-green-600 dark:text-green-400">Verified</p>
                    </div>
                    @else
                    <a href="{{ route('mobile.verify') }}"
                       class="flex-shrink-0 p-4 border-2 border-red-200 dark:border-red-800 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20 hover:border-red-400 transition text-center min-w-[200px]">
                        <svg class="w-8 h-8 text-red-600 dark:text-red-400 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                        </svg>
                        <p class="font-medium text-gray-900 dark:text-gray-100">Verify mobile</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400">OTP verification</p>
                    </a>
                    @endif
                </div>
            </div>

        @endif

    </div>
</div>

@endsection

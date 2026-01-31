@extends('layouts.app')

@section('content')
<div class="max-w-3xl mx-auto py-8">
    <h1 class="text-2xl font-bold mb-6">
        Matrimony Profile
        @if (!empty($matrimonyProfile->is_demo))
            <span class="inline-block ml-2 px-2 py-0.5 text-xs font-semibold bg-sky-100 text-sky-700 rounded">Demo Profile</span>
        @endif
    </h1>

@if ($isOwnProfile && $matrimonyProfile->is_suspended)
    <div style="margin-bottom:1.5rem; padding:1.25rem; background:#fef3c7; border:2px solid #fbbf24; border-radius:8px; color:#92400e;">
        <p style="font-weight:700; margin:0; font-size:1.1rem;">⚠️ Your profile is currently suspended by admin.</p>
    </div>
@endif

<div class="bg-white shadow rounded-lg p-6">

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
@if (isset($profilePhotoVisible) && $profilePhotoVisible)
<div class="mb-6 flex flex-col items-center">
    @if ($matrimonyProfile->profile_photo && $matrimonyProfile->photo_approved !== false)
        <img
            src="{{ asset('uploads/matrimony_photos/'.$matrimonyProfile->profile_photo) }}"
            alt="Profile Photo"
            class="w-40 h-40 rounded-full object-cover border"
        />
    @else
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

    @if (isset($dateOfBirthVisible) && $dateOfBirthVisible)
    <div>
        <p class="text-gray-500 text-sm">Date of Birth</p>
        <p class="font-medium text-base">{{ $matrimonyProfile->date_of_birth ?? '—' }}</p>
    </div>
    @endif

    @if (isset($maritalStatusVisible) && $maritalStatusVisible)
    <div>
        <p class="text-gray-500 text-sm">Marital Status</p>
        <p class="font-medium text-base">
            {{ ($matrimonyProfile->marital_status ?? '') ? ucfirst($matrimonyProfile->marital_status) : '—' }}
        </p>
    </div>
    @endif

    @if (isset($educationVisible) && $educationVisible)
    <div>
        <p class="text-gray-500 text-sm">Education</p>
        <p class="font-medium text-base">{{ $matrimonyProfile->education ?? '—' }}</p>
    </div>
    @endif

    @if (isset($locationVisible) && $locationVisible)
    <div>
        <p class="text-gray-500 text-sm">Location</p>
        <p class="font-medium text-base">{{ $matrimonyProfile->location ?? '—' }}</p>
    </div>
    @endif

    @if (isset($casteVisible) && $casteVisible && $matrimonyProfile->caste !== null && $matrimonyProfile->caste !== '')
    <div>
        <p class="text-gray-500 text-sm">Caste</p>
        <p class="font-medium text-base">{{ $matrimonyProfile->caste }}</p>
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

    <div class="text-center mb-6">
        <h3 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-1">तुमची प्रोफाइल त्यांच्याशी कशी जुळते?</h3>
        <p class="text-sm text-gray-600 dark:text-gray-400">अपेक्षा आणि माहितीवर आधारित तुलना</p>
    </div>

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

    <div class="pt-4 border-t border-gray-200 dark:border-gray-700 mb-6">
        <p class="text-sm text-gray-600 dark:text-gray-400 italic">
            काही गोष्टी चर्चा करून ठरवता येऊ शकतात.
        </p>
    </div>

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

            <form method="POST" action="{{ route('blocks.store', $matrimonyProfile) }}" style="display: inline;">
                @csrf
                <button type="submit" style="background-color: #6b7280; color: white; padding: 12px 24px; border-radius: 6px; font-weight: 600; font-size: 16px; border: none; cursor: pointer;">Block</button>
            </form>

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
</div>
@endsection

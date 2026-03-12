{{-- Photo section: uses centralized photo upload engine (cropper, dedicated page). No direct file input here. --}}
<div class="space-y-4">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">Profile photo</h2>

    @php
        $profile = $profile ?? (auth()->user()->matrimonyProfile ?? null);
        $gender = $profile?->gender?->key ?? null;
        if ($gender === 'male') {
            $placeholderSrc = asset('images/placeholders/male-profile.svg');
        } elseif ($gender === 'female') {
            $placeholderSrc = asset('images/placeholders/female-profile.svg');
        } else {
            $placeholderSrc = asset('images/placeholders/default-profile.svg');
        }
    @endphp

    <p class="text-sm text-gray-600 dark:text-gray-400">
        Photo upload is handled by the dedicated photo engine so that you get better cropping and quality.
        Use the button below to open the photo upload screen.
    </p>

    <div class="flex items-center gap-4">
        <div class="flex-shrink-0">
            @if ($profile && isset($profile->profile_photo) && $profile->profile_photo !== '' && (!isset($profile->photo_approved) || $profile->photo_approved !== false))
                <img src="{{ asset('uploads/matrimony_photos/'.$profile->profile_photo) }}"
                     alt="Profile photo"
                     class="w-20 h-20 rounded-full object-cover border-4 border-indigo-200 shadow-sm">
            @else
                <img src="{{ $placeholderSrc }}"
                     alt="Profile placeholder"
                     class="w-20 h-20 rounded-full object-cover border-4 border-indigo-200 shadow-sm">
            @endif
        </div>
        <div class="flex-1">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">
                Tip: A clear face photo gets you more relevant matches.
            </p>
            <a href="{{ route('matrimony.profile.upload-photo') }}"
               class="inline-flex items-center px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg shadow-sm">
                Open photo upload engine →
            </a>
        </div>
    </div>
</div>

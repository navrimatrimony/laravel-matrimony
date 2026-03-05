{{-- Phase-5B: Photo section — reuses unified photo upload engine. --}}
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

    {{-- When in full wizard/edit, delegate photo changes to dedicated engine --}}
    @if(($currentSection ?? '') === 'full')
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Photo upload is handled by the dedicated photo engine so that you get better cropping and quality.
            Use the button below to open the photo upload screen without losing your other changes.
        </p>

        <div class="flex items-center gap-4">
            <div class="flex-shrink-0">
                @if ($profile && $profile->profile_photo && $profile->photo_approved !== false)
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
    @else
        {{-- Standalone "photo" step: keep simple file input for now (uses same backend engine) --}}
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Upload a clear photo. It will be saved via MutationService. If photo verification is enabled, your photo will be visible to others after admin approval.
        </p>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                Photo <span class="text-red-500">*</span>
            </label>
            <input type="file"
                   name="profile_photo"
                   accept="image/*"
                   required
                   class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
        </div>
    @endif
</div>

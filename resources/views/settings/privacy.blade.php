@extends('layouts.app')

@section('content')
<div class="py-12">
    <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('Privacy & Visibility') }}</h1>
            <p class="mt-2 text-gray-600 dark:text-gray-400">
                {{ __('Control who can see your photo and contact details.') }}
            </p>
        </div>

        @if (session('status') === 'privacy-updated')
            <div class="mb-6 p-4 bg-green-50 border border-green-200 text-green-800 rounded-lg">
                {{ __('Saved successfully.') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-6 p-4 bg-red-50 border border-red-200 text-red-800 rounded-lg">
                <div class="font-semibold mb-2">{{ __('Please fix the following:') }}</div>
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (!empty($profile->profile_visibility_mode))
            <div class="mb-6 p-4 bg-amber-50 border border-amber-200 text-amber-900 rounded-lg">
                <div class="font-semibold mb-1">{{ __('Legacy mode (read-only)') }}</div>
                <div class="text-sm">
                    {{ __('Current profile_visibility_mode:') }} <span class="font-medium">{{ $profile->profile_visibility_mode }}</span>
                </div>
                <div class="text-sm mt-2">
                    {{ __('These new controls will eventually unify with this legacy setting.') }}
                </div>
            </div>
        @endif

        @php
            $visibilityScope = old('visibility_scope', $visibilitySettings?->visibility_scope ?? null);
            $showPhotoTo = old('show_photo_to', $visibilitySettings?->show_photo_to ?? null);
            $cvR = $contactVisibilityResolved ?? [];
            $cvRule = old('contact_visibility_rule', $cvR['rule'] ?? 'anyone');
            $cvStrict = old('contact_visibility_strictness', $cvR['strictness'] ?? 'balanced');
            $strictnessSliderMap = ['relaxed' => 0, 'balanced' => 1, 'strict' => 2];
            $cvStrictSlider = (int) ($strictnessSliderMap[$cvStrict] ?? 1);
            $idVerified = (bool) ($cvR['filters']['id_verified_only'] ?? $cvR['filters']['verified_only'] ?? false);
            $cvIdVerified = old('contact_visibility_id_verified_only', $idVerified ? '1' : '0');
            $cvPhoto = old('contact_visibility_photo_only', ($cvR['filters']['photo_only'] ?? false) ? '1' : '0');
            $cvRequireRequest = old('contact_visibility_require_contact_request', ($cvR['require_contact_request'] ?? false) ? '1' : '0');
            $cvApproval = old('contact_visibility_approval_required', ($cvR['approval_required'] ?? false) ? '1' : '0');
        @endphp

        <form method="POST" action="{{ route('user.settings.privacy.update') }}" class="space-y-6">
            @csrf

            <div class="bg-white dark:bg-gray-800 shadow-sm border border-gray-200 dark:border-gray-700 rounded-lg p-6">
                <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Photo visibility scope') }}</div>
                <div class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    {{ __('This controls the general visibility of your profile in relation to your photo.') }}
                </div>

                <div class="mt-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ __('Visibility scope') }}
                    </label>
                    <select name="visibility_scope"
                            class="mt-2 w-full rounded-md border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100"
                            required>
                        <option value="" {{ $visibilityScope === null ? 'selected' : '' }}>{{ __('Choose...') }}</option>
                        <option value="public" {{ $visibilityScope === 'public' ? 'selected' : '' }}>{{ __('Public') }}</option>
                        <option value="premium_only" {{ $visibilityScope === 'premium_only' ? 'selected' : '' }}>{{ __('Premium only') }}</option>
                        <option value="hidden" {{ $visibilityScope === 'hidden' ? 'selected' : '' }}>{{ __('Hidden') }}</option>
                    </select>
                </div>

                <div class="mt-6">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ __('Show photo to') }}
                    </label>
                    <select name="show_photo_to"
                            class="mt-2 w-full rounded-md border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100"
                            required>
                        <option value="" {{ $showPhotoTo === null ? 'selected' : '' }}>{{ __('Choose...') }}</option>
                        <option value="all" {{ $showPhotoTo === 'all' ? 'selected' : '' }}>{{ __('All viewers') }}</option>
                        <option value="premium" {{ $showPhotoTo === 'premium' ? 'selected' : '' }}>{{ __('Premium viewers') }}</option>
                        <option value="accepted_interest" {{ $showPhotoTo === 'accepted_interest' ? 'selected' : '' }}>{{ __('After interest accepted') }}</option>
                    </select>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm border border-gray-200 dark:border-gray-700 rounded-lg p-6">
                <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('settings_privacy.contact_details_visibility_title') }}</div>
                <div class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    {{ __('settings_privacy.contact_details_visibility_intro') }}
                </div>

                <input type="hidden" name="hide_from_blocked_users" value="1">

                <div class="mt-6">
                    <div class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('settings_privacy.who_can_see_contact_heading') }}</div>

                    <div class="mt-4 space-y-3">
                        @foreach ([
                            'anyone' => __('settings_privacy.contact_rule_anyone'),
                            'interest' => __('settings_privacy.contact_rule_interest'),
                            'matching' => __('settings_privacy.contact_rule_matching'),
                            'none' => __('settings_privacy.contact_rule_none'),
                        ] as $val => $label)
                            <label class="flex items-center gap-2 text-sm text-gray-800 dark:text-gray-200">
                                <input type="radio" name="contact_visibility_rule" value="{{ $val }}" class="rounded-full border-gray-400 text-red-600" @checked($cvRule === $val) required>
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>

                    <div class="mt-6">
                        <span class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('settings_privacy.match_preference_heading') }}</span>
                        <input type="hidden" name="contact_visibility_strictness" id="contact_visibility_strictness" value="{{ $cvStrict }}">
                        <input type="range"
                               id="contact_strictness_slider"
                               min="0"
                               max="2"
                               step="1"
                               value="{{ $cvStrictSlider }}"
                               class="w-full accent-red-600">
                        <div class="mt-2 flex justify-between text-xs text-gray-500 dark:text-gray-400">
                            <span>{{ __('settings_privacy.strictness_relaxed') }}</span>
                            <span>{{ __('settings_privacy.strictness_balanced') }}</span>
                            <span>{{ __('settings_privacy.strictness_strict') }}</span>
                        </div>
                    </div>

                    <div class="mt-6 text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ __('settings_privacy.show_contact_details_only_to_heading') }}
                    </div>
                    <div class="mt-2 space-y-3">
                        <label class="flex items-start gap-3">
                            <input type="hidden" name="contact_visibility_id_verified_only" value="0">
                            <input type="checkbox" name="contact_visibility_id_verified_only" value="1" class="mt-1" @checked($cvIdVerified === '1' || $cvIdVerified === true || $cvIdVerified === 1)>
                            <span class="text-sm text-gray-700 dark:text-gray-200">{{ __('settings_privacy.filter_id_verified') }}</span>
                        </label>
                        <label class="flex items-start gap-3">
                            <input type="hidden" name="contact_visibility_photo_only" value="0">
                            <input type="checkbox" name="contact_visibility_photo_only" value="1" class="mt-1" @checked($cvPhoto === '1' || $cvPhoto === true || $cvPhoto === 1)>
                            <span class="text-sm text-gray-700 dark:text-gray-200">{{ __('settings_privacy.filter_photo_only') }}</span>
                        </label>
                    </div>

                    <div class="mt-6 space-y-3">
                        <label class="flex items-start gap-3">
                            <input type="hidden" name="contact_visibility_require_contact_request" value="0">
                            <input type="checkbox" name="contact_visibility_require_contact_request" value="1" class="mt-1" @checked($cvRequireRequest === '1' || $cvRequireRequest === true || $cvRequireRequest === 1)>
                            <span class="text-sm text-gray-700 dark:text-gray-200">{{ __('settings_privacy.require_contact_request_label') }}</span>
                        </label>
                        <label class="flex items-start gap-3">
                            <input type="hidden" name="contact_visibility_approval_required" value="0">
                            <input type="checkbox" name="contact_visibility_approval_required" value="1" class="mt-1" @checked($cvApproval === '1' || $cvApproval === true || $cvApproval === 1)>
                            <span class="text-sm text-gray-700 dark:text-gray-200">{{ __('settings_privacy.ask_permission_before_sharing') }}</span>
                        </label>
                    </div>
                </div>

                <div class="mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
                    <label class="flex items-start gap-3 opacity-90 cursor-not-allowed">
                        <input type="checkbox" class="mt-1" checked disabled>
                        <span class="text-sm text-gray-700 dark:text-gray-200">
                            {{ __('settings_privacy.blocked_users_never_see_contact') }}
                        </span>
                    </label>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3">
                <a href="{{ route('user.settings.index') }}"
                   class="px-4 py-2 rounded-md border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-900 transition">
                    {{ __('Back') }}
                </a>
                <button type="submit"
                        class="px-5 py-2 rounded-md bg-red-600 text-white hover:bg-red-700 transition font-medium">
                    {{ __('Save') }}
                </button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    var map = ['relaxed', 'balanced', 'strict'];
    var slider = document.getElementById('contact_strictness_slider');
    var hidden = document.getElementById('contact_visibility_strictness');
    if (!slider || !hidden) return;
    function syncFromSlider() {
        var i = parseInt(slider.value, 10);
        if (isNaN(i) || i < 0 || i > 2) i = 1;
        hidden.value = map[i] || 'balanced';
    }
    slider.addEventListener('input', syncFromSlider);
    slider.addEventListener('change', syncFromSlider);
    syncFromSlider();
})();
</script>
@endsection


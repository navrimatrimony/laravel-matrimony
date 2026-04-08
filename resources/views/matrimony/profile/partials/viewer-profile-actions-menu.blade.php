{{--
    Actions menu (block, hide, report profile / photo) for another member's profile.
    Used on search cards and profile show. Expects $matrimonyProfile, $isListingOwnProfile (bool).
    Optional: $reportablePhotoSummary (overrides model attribute when set).
--}}
@php
    $photoReportSummary = $reportablePhotoSummary ?? ($matrimonyProfile->reportable_photo_summary ?? null);
@endphp
<details class="relative z-10 shrink-0 open:z-[100]" data-profile-card-actions>
    <summary class="flex cursor-pointer list-none items-center justify-center rounded-full border border-gray-200/90 bg-white/95 p-1.5 text-gray-600 shadow-sm ring-1 ring-black/5 backdrop-blur-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-900/95 dark:text-gray-200 dark:ring-white/10 dark:hover:bg-gray-800 [&::-webkit-details-marker]:hidden" aria-label="{{ __('search.card_actions_menu') }}">
        <span class="flex flex-col gap-0.5" aria-hidden="true">
            <span class="block h-1 w-1 rounded-full bg-current"></span>
            <span class="block h-1 w-1 rounded-full bg-current"></span>
            <span class="block h-1 w-1 rounded-full bg-current"></span>
        </span>
    </summary>
    <div class="absolute right-0 z-[100] mt-1 w-64 max-w-[calc(100vw-2rem)] rounded-xl border border-gray-200 bg-white p-2 shadow-lg dark:border-gray-700 dark:bg-gray-900">
        @if (auth()->user()->matrimonyProfile)
            <form method="POST" action="{{ route('blocks.store', $matrimonyProfile) }}" onsubmit="return confirm(@json(__('search.confirm_block_profile')));">
                @csrf
                <button type="submit" class="w-full rounded-lg px-3 py-2 text-left text-sm text-gray-800 hover:bg-gray-100 dark:text-gray-100 dark:hover:bg-gray-800">{{ __('search.action_block_profile') }}</button>
            </form>
            <form method="POST" action="{{ route('hidden-profiles.store', $matrimonyProfile) }}" class="mt-2 border-t border-gray-200 pt-2 dark:border-gray-700" onsubmit="return confirm(@json(__('search.confirm_hide_profile')));">
                @csrf
                <button type="submit" class="w-full rounded-lg px-3 py-2 text-left text-sm text-gray-800 hover:bg-gray-100 dark:text-gray-100 dark:hover:bg-gray-800">{{ __('search.action_hide_profile') }}</button>
            </form>
        @endif
        <div class="@if(auth()->user()->matrimonyProfile) mt-2 border-t border-gray-200 pt-2 dark:border-gray-700 @endif">
            <p class="mb-1 px-1 text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('search.action_report_profile') }}</p>
            <form method="POST" action="{{ route('abuse-reports.store', $matrimonyProfile) }}" class="space-y-2">
                @csrf
                <textarea
                    name="reason"
                    rows="2"
                    required
                    minlength="10"
                    maxlength="2000"
                    placeholder="{{ __('search.report_reason_placeholder') }}"
                    class="w-full resize-y rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-xs text-gray-900 placeholder:text-gray-400 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100"
                ></textarea>
                <button type="submit" class="w-full rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-rose-700">{{ __('search.submit_report') }}</button>
            </form>
        </div>
        @if (! $isListingOwnProfile && $photoReportSummary)
            <div class="mt-2 border-t border-gray-200 pt-2 dark:border-gray-700">
                <p class="mb-1 px-1 text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('search.action_report_photo') }}</p>
                <form method="POST" action="{{ route('profile-photo-reports.store', $matrimonyProfile) }}" class="space-y-2">
                    @csrf
                    <input type="hidden" name="profile_photo_id" value="{{ $photoReportSummary['profile_photo_id'] }}">
                    <textarea
                        name="reason"
                        rows="2"
                        required
                        minlength="10"
                        maxlength="2000"
                        placeholder="{{ __('search.photo_report_reason_placeholder') }}"
                        class="w-full resize-y rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-xs text-gray-900 placeholder:text-gray-400 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100"
                    ></textarea>
                    <button type="submit" class="w-full rounded-lg bg-amber-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-800">{{ __('search.submit_photo_report') }}</button>
                </form>
            </div>
        @endif
    </div>
</details>

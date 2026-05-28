@php
    /** @var \App\Models\ProfileView $view */
    $viewerProfile = $view->viewerProfile;
    $viewerUser = $viewerProfile?->user;
    $photoService = app(\App\Services\Image\ProfilePhotoUrlService::class);
    $photoSrc = ($viewerProfile && $viewerProfile->profile_photo && $viewerProfile->photo_approved !== false)
        ? $photoService->publicUrl($viewerProfile->profile_photo)
        : asset('images/placeholders/default-profile.svg');
    $profileUrl = $viewerProfile ? route('matrimony.profile.show', $viewerProfile->id) : '#';
@endphp
<div class="flex w-full flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:min-h-[10rem] sm:flex-row">
    <div class="relative h-44 w-full shrink-0 bg-gray-100 dark:bg-gray-900 sm:h-full sm:w-32">
        <img src="{{ $photoSrc }}" alt="" class="absolute inset-0 h-full w-full object-cover" loading="lazy">
    </div>
    <div class="flex min-h-0 min-w-0 flex-1 flex-col justify-center border-t border-gray-100 px-3 py-3 dark:border-gray-700 sm:border-l sm:border-t-0 sm:py-2">
        <p class="font-semibold text-gray-900 dark:text-gray-100">
            {{ $viewerProfile?->full_name ?? $viewerUser?->name ?? 'Member' }}
        </p>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            {{ __('who_viewed.viewed_at', ['time' => $view->created_at->diffForHumans()]) }}
        </p>
    </div>
    <div class="flex w-full shrink-0 flex-col justify-center gap-2 border-t border-gray-100 bg-gray-50/95 px-3 py-3 dark:border-gray-700 dark:bg-gray-900/50 sm:h-full sm:w-36 sm:border-l sm:border-t-0 sm:px-2.5 sm:py-2">
        <p class="text-[10px] font-medium leading-snug text-gray-600 dark:text-gray-400">
            {{ __('who_viewed.full_card_cta_hint') }}
        </p>
        <a href="{{ $profileUrl }}" @class([
            'inline-flex w-full items-center justify-center rounded-lg px-2 py-2.5 text-center text-xs font-semibold shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900 sm:text-sm',
            'bg-indigo-600 text-white hover:bg-indigo-700' => $viewerProfile,
            'pointer-events-none cursor-not-allowed bg-gray-400 text-gray-200' => ! $viewerProfile,
        ])>
            {{ __('who_viewed.view_profile') }}
        </a>
    </div>
</div>

@extends('layouts.app')

@section('content')
<div class="py-8 max-w-4xl mx-auto px-4">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-4">{{ __('who_viewed.title') }}</h1>

    @if ($whoViewedLocked ?? false)
        @if (($teaserUniqueCount ?? 0) > 0)
            <p class="text-gray-700 dark:text-gray-300 mb-3">
                {{ trans_choice('who_viewed.teaser_headline', $teaserUniqueCount, ['count' => $teaserUniqueCount]) }}
            </p>
            <div class="relative mb-6 overflow-hidden rounded-xl border border-gray-200 bg-gray-50/90 dark:border-gray-700 dark:bg-gray-800/50">
                <div class="pointer-events-none select-none space-y-3 p-4 blur-md opacity-80" aria-hidden="true">
                    @for ($i = 0; $i < min(4, max(1, $teaserUniqueCount)); $i++)
                        <div class="flex items-center gap-3">
                            <div class="h-14 w-14 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></div>
                            <div class="min-w-0 flex-1 space-y-2">
                                <div class="h-4 w-40 rounded bg-gray-300 dark:bg-gray-600"></div>
                                <div class="h-3 w-28 rounded bg-gray-200 dark:bg-gray-700"></div>
                            </div>
                        </div>
                    @endfor
                </div>
                <div class="pointer-events-none absolute inset-0 bg-gradient-to-b from-white/30 via-white/70 to-white dark:from-gray-900/20 dark:via-gray-900/75 dark:to-gray-900"></div>
            </div>
            <a href="{{ $plansUrl ?? route('plans.index') }}" class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900">
                {{ __('who_viewed.upgrade_cta') }}
            </a>
            <p class="mt-5 text-sm text-gray-600 dark:text-gray-400">{{ __('who_viewed.locked_html') }}</p>
        @else
            <p class="text-gray-600 dark:text-gray-400 mb-6">{{ __('who_viewed.locked_html') }}</p>
        @endif
    @elseif ($uniqueCount === 0)
        <p class="text-gray-600 dark:text-gray-400 mb-6">
            @if ($whoViewedEmptyUsesMonth ?? false)
                {{ __('who_viewed.none_this_month') }}
            @elseif (($windowDays ?? null) === null)
                {{ __('who_viewed.none_all_time') }}
            @else
                {{ __('who_viewed.none_in_window', ['days' => $windowDays]) }}
            @endif
        </p>
    @else
        <p class="text-gray-700 dark:text-gray-300 mb-6">
            @if (! ($hasFullWhoViewedAccess ?? true))
                {{ trans_choice('who_viewed.people_viewed_this_month', $uniqueCount, ['count' => $uniqueCount]) }}
            @elseif (($windowDays ?? null) === null)
                {{ trans_choice('who_viewed.people_viewed_all_time', $uniqueCount, ['count' => $uniqueCount]) }}
            @else
                {{ trans_choice('who_viewed.people_viewed_in_window', $uniqueCount, ['count' => $uniqueCount, 'days' => $windowDays]) }}
            @endif
        </p>

        <div class="space-y-4">
            @foreach ($recentViewers as $view)
                @php
                    $viewerProfile = $view->viewerProfile;
                    $viewerUser = $viewerProfile?->user;
                @endphp
                <div class="flex items-center gap-4 border border-gray-200 dark:border-gray-700 rounded-lg p-3 bg-white dark:bg-gray-800">
                    <div class="w-16 h-16 rounded-full overflow-hidden border flex-shrink-0">
                        @if ($viewerProfile && $viewerProfile->profile_photo && $viewerProfile->photo_approved !== false)
                            <img src="{{ app(\App\Services\Image\ProfilePhotoUrlService::class)->publicUrl($viewerProfile->profile_photo) }}" class="w-full h-full object-cover" alt="">
                        @else
                            <img src="{{ asset('images/placeholders/default-profile.svg') }}" class="w-full h-full object-cover" alt="">
                        @endif
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="font-semibold text-gray-900 dark:text-gray-100">
                            {{ $viewerProfile->full_name ?? $viewerUser->name ?? 'Member' }}
                        </p>
                        @if ($viewerProfile)
                            <a href="{{ route('matrimony.profile.show', $viewerProfile->id) }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">
                                {{ __('who_viewed.view_profile') }}
                            </a>
                        @endif
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            {{ __('who_viewed.viewed_at', ['time' => $view->created_at->diffForHumans()]) }}
                        </p>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($whoViewedPartial ?? false)
            <div class="relative mt-6 overflow-hidden rounded-xl border border-indigo-200 bg-indigo-50/80 p-4 dark:border-indigo-800 dark:bg-indigo-950/30">
                <div class="pointer-events-none select-none space-y-3 blur-md opacity-80" aria-hidden="true">
                    @for ($i = 0; $i < min(3, max(1, (int) ($lockedOverflowCount ?? 0))); $i++)
                        <div class="flex items-center gap-3">
                            <div class="h-14 w-14 shrink-0 rounded-full bg-indigo-200 dark:bg-indigo-800"></div>
                            <div class="min-w-0 flex-1 space-y-2">
                                <div class="h-4 w-40 rounded bg-indigo-200 dark:bg-indigo-800"></div>
                                <div class="h-3 w-28 rounded bg-indigo-100 dark:bg-indigo-900"></div>
                            </div>
                        </div>
                    @endfor
                </div>
                <div class="pointer-events-none absolute inset-0 bg-gradient-to-b from-white/20 via-white/60 to-white dark:from-gray-900/10 dark:via-gray-900/70 dark:to-gray-900"></div>
                <div class="relative z-10 text-center">
                    <p class="text-sm font-medium text-indigo-950 dark:text-indigo-100">
                        {{ trans_choice('who_viewed.partial_hidden_count', (int) ($lockedOverflowCount ?? 0), ['count' => (int) ($lockedOverflowCount ?? 0)]) }}
                    </p>
                    <a href="{{ $plansUrl ?? route('plans.index') }}" class="mt-3 inline-flex items-center justify-center rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900">
                        {{ __('who_viewed.upgrade_cta') }}
                    </a>
                </div>
            </div>
        @elseif (! ($hasFullWhoViewedAccess ?? true))
            <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">
                <a href="{{ $plansUrl ?? route('plans.index') }}" class="font-medium text-indigo-600 underline hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300">{{ __('who_viewed.partial_plan_upgrade_hint') }}</a>
            </p>
        @endif
    @endif
</div>
@endsection

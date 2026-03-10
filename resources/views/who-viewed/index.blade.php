@extends('layouts.app')

@section('content')
<div class="py-8 max-w-4xl mx-auto px-4">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-4">{{ __('who_viewed.title') }}</h1>

    @if ($uniqueCount === 0)
        <p class="text-gray-600 dark:text-gray-400 mb-6">{{ __('who_viewed.none_last_30_days') }}</p>
    @else
        <p class="text-gray-700 dark:text-gray-300 mb-6">
            <span class="font-semibold">{{ $uniqueCount }}</span>
            {{ trans_choice('who_viewed.people_viewed_last_30_days', $uniqueCount, ['count' => $uniqueCount]) }}
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
                            <img src="{{ asset('uploads/matrimony_photos/'.$viewerProfile->profile_photo) }}" class="w-full h-full object-cover" alt="">
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
    @endif
</div>
@endsection


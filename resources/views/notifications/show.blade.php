@extends('layouts.app')

@section('content')
<div class="max-w-2xl mx-auto py-8 px-4">
    <div class="border rounded-lg p-6 bg-white">
        @php
            $data = is_array($notification->data) ? $notification->data : [];
            $message = \App\Support\NotificationLocalization::displayMessage(
                $data,
                \App\Support\NotificationLocalization::preferredLocaleForUser(auth()->user()),
            );
            $notificationType = (string) ($data['type'] ?? '');
            $hasApprovedPhoto = isset($actorProfile) && $actorProfile && $actorProfile->profile_photo && $actorProfile->photo_approved !== false;
            $photoSrc = (isset($actorProfile) && $actorProfile)
                ? ($hasApprovedPhoto ? app(\App\Services\Image\ProfilePhotoUrlService::class)->publicUrl($actorProfile->profile_photo) : $actorProfile->profile_photo_url)
                : null;
            $teaserPayload = $localizedTeaser ?? ($data['teaser'] ?? null);
            $contextLabel = $notificationType === 'interest_sent'
                ? __('notifications.teaser_open_received_interests')
                : __('notifications.teaser_open_who_viewed');
        @endphp

        @if (!empty($actorProfile))
            <div class="flex items-center gap-3 mb-4">
                <a href="{{ route('matrimony.profile.show', $actorProfile->id) }}" class="shrink-0" aria-label="Open profile">
                    <img
                        src="{{ $photoSrc }}"
                        class="w-14 h-14 rounded-full object-cover border bg-white"
                        alt=""
                        loading="lazy"
                    />
                </a>
                <div class="min-w-0">
                    <a href="{{ route('matrimony.profile.show', $actorProfile->id) }}" class="block font-semibold text-indigo-700 hover:underline truncate">
                        {{ $actorProfile->full_name ?? __('notifications.view_profile') }}
                    </a>
                    <p class="text-sm text-gray-500">{{ $notification->created_at->format('M j, Y g:i A') }}</p>
                </div>
            </div>
        @endif

        @if (is_array($teaserPayload) && (($notification->data['revealed'] ?? true) === false))
            <div class="mb-4">
                @include('who-viewed.partials.viewer-row-teaser', [
                    'teaser' => $teaserPayload,
                    'plansUrl' => $data['teaser_plans_url'] ?? route('plans.index'),
                    'cardLayout' => 'horizontal',
                    'hideTeaserCtaColumn' => true,
                ])
            </div>
            <p class="mt-3 flex flex-wrap gap-3 text-sm">
                <a href="{{ $data['teaser_plans_url'] ?? route('plans.index') }}" class="text-indigo-600 hover:underline">{{ __('interests.upgrade_for_more_reveals') }}</a>
                <a href="{{ $data['teaser_context_url'] ?? route('notifications.index') }}" class="text-indigo-600 hover:underline">{{ $contextLabel }}</a>
            </p>
        @else
            <p class="text-gray-900 font-medium">{{ $message }}</p>
        @endif
        <p class="text-sm text-gray-500 mt-2">{{ $notification->created_at->format('M j, Y g:i A') }}</p>
        <p class="mt-4">
            <a href="{{ route('notifications.index') }}" class="text-indigo-600 hover:underline">← {{ __('notifications.back_to_notifications') }}</a>
        </p>
    </div>
</div>
@endsection

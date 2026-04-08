@extends('layouts.app')

@section('content')
<div class="max-w-2xl mx-auto py-8 px-4">
    <div class="border rounded-lg p-6 bg-white">
        @php
            $hasApprovedPhoto = isset($actorProfile) && $actorProfile && $actorProfile->profile_photo && $actorProfile->photo_approved !== false;
            $photoSrc = (isset($actorProfile) && $actorProfile)
                ? ($hasApprovedPhoto ? asset('uploads/matrimony_photos/'.$actorProfile->profile_photo) : $actorProfile->profile_photo_url)
                : null;
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
                        {{ $actorProfile->full_name ?? 'View profile' }}
                    </a>
                    <p class="text-sm text-gray-500">{{ $notification->created_at->format('M j, Y g:i A') }}</p>
                </div>
            </div>
        @endif

        <p class="text-gray-900 font-medium">{{ $notification->data['message'] ?? 'Notification' }}</p>
        <p class="text-sm text-gray-500 mt-2">{{ $notification->created_at->format('M j, Y g:i A') }}</p>
        <p class="mt-4">
            <a href="{{ route('notifications.index') }}" class="text-indigo-600 hover:underline">← Back to notifications</a>
        </p>
    </div>
</div>
@endsection

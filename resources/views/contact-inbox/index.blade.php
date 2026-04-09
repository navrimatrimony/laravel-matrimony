@extends('layouts.app')

@section('content')
<div class="py-8 max-w-4xl mx-auto px-4">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-6">{{ __('notifications.contact_requests_access') }}</h1>

    @if (session('success'))
        <p class="text-green-600 dark:text-green-400 mb-4">{{ session('success') }}</p>
    @endif
    @if (session('error'))
        <p class="text-red-600 dark:text-red-400 mb-4">{{ session('error') }}</p>
    @endif
    @if ($errors->any())
        <ul class="text-red-600 dark:text-red-400 mb-4 list-disc list-inside">
            @foreach ($errors->all() as $e)
                <li>{{ $e }}</li>
            @endforeach
        </ul>
    @endif

    <div x-data="{ tab: 'pending' }" class="space-y-6">
        <div class="flex gap-2 border-b border-gray-200 dark:border-gray-700">
            <button type="button" @click="tab = 'pending'" :class="tab === 'pending' ? 'border-b-2 border-indigo-600 text-indigo-600 font-medium' : 'text-gray-600 dark:text-gray-400'" class="pb-2 px-2">
                {{ __('notifications.requests_pending') }} @if($pending->count() > 0)<span class="bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200 text-xs px-2 py-0.5 rounded">{{ $pending->count() }}</span>@endif
            </button>
            <button type="button" @click="tab = 'granted'" :class="tab === 'granted' ? 'border-b-2 border-indigo-600 text-indigo-600 font-medium' : 'text-gray-600 dark:text-gray-400'" class="pb-2 px-2">
                {{ __('notifications.access_granted_active') }}
            </button>
        </div>

        {{-- Pending --}}
        <div x-show="tab === 'pending'" x-cloak>
            @forelse ($pending as $req)
                @php
                    $sender = $req->sender;
                    $senderProfile = $sender->matrimonyProfile ?? null;
                    $reasonLabel = $reasons[$req->reason] ?? $req->reason;
                @endphp
                <div class="border border-gray-200 dark:border-gray-600 rounded-lg p-4 mb-4 bg-white dark:bg-gray-800">
                    <div class="flex flex-wrap items-start gap-4">
                        @if ($senderProfile && $senderProfile->profile_photo && $senderProfile->photo_approved !== false)
                            <img src="{{ app(\App\Services\Image\ProfilePhotoUrlService::class)->publicUrl($senderProfile->profile_photo) }}" class="w-16 h-16 rounded-full object-cover border" alt="">
                        @else
                            <img src="{{ asset('images/placeholders/default-profile.svg') }}" class="w-16 h-16 rounded-full object-cover border" alt="">
                        @endif
                        <div class="flex-1 min-w-0">
                            <p class="font-semibold text-gray-900 dark:text-gray-100">{{ $senderProfile->full_name ?? $sender->name ?? 'User' }}</p>
                            @if ($senderProfile)
                                <a href="{{ route('matrimony.profile.show', $senderProfile->id) }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('notifications.view_profile') }}</a>
                            @endif
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400"><strong>{{ __('notifications.reason') }}:</strong> {{ $reasonLabel }}{{ $req->reason === 'other' && $req->other_reason_text ? ' — ' . $req->other_reason_text : '' }}</p>
                            <p class="text-sm text-gray-600 dark:text-gray-400"><strong>{{ __('notifications.requested') }}:</strong> {{ implode(', ', $req->requested_scopes ?? []) }}</p>
                        </div>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('contact-requests.approve', $req) }}" class="inline" x-data="{ open: false }">
                            @csrf
                            <button type="button" @click="open = true" class="px-4 py-2 bg-green-600 text-white rounded-md font-medium text-sm">{{ __('notifications.approve') }}</button>
                            <div x-show="open" x-cloak class="mt-2 p-3 border border-gray-200 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700/50">
                                <p class="text-sm font-medium mb-2">{{ __('notifications.grant_access_select_scopes_duration') }}</p>
                                @foreach(['email' => __('notifications.email'), 'phone' => __('notifications.phone'), 'whatsapp' => __('notifications.whatsapp')] as $s => $l)
                                    <label class="inline-flex items-center mr-4"><input type="checkbox" name="granted_scopes[]" value="{{ $s }}" {{ in_array($s, $req->requested_scopes ?? []) ? 'checked' : '' }} class="rounded border-gray-300 dark:border-gray-600"> <span class="ml-1">{{ $l }}</span></label>
                                @endforeach
                                <div class="mt-2">
                                    <select name="duration" required class="border border-gray-300 dark:border-gray-600 rounded dark:bg-gray-700 dark:text-gray-100">
                                        @foreach($durationOptions as $k => $v)
                                            <option value="{{ $k }}">{{ $v }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="mt-2 flex gap-2">
                                    <button type="submit" class="px-3 py-1 bg-green-600 text-white rounded text-sm">{{ __('notifications.grant') }}</button>
                                    <button type="button" @click="open = false" class="px-3 py-1 bg-gray-500 text-white rounded text-sm">{{ __('common.cancel') }}</button>
                                </div>
                            </div>
                        </form>
                        <form method="POST" action="{{ route('contact-requests.reject', $req) }}" class="inline" onsubmit="return confirm(@js(__('notifications.confirm_reject_request')));">
                            @csrf
                            <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-md font-medium text-sm">{{ __('notifications.reject') }}</button>
                        </form>
                    </div>
                </div>
            @empty
                <p class="text-gray-500 dark:text-gray-400">{{ __('notifications.no_pending_contact_requests') }}</p>
            @endforelse
        </div>

        {{-- Access Granted --}}
        <div x-show="tab === 'granted'" x-cloak>
            @forelse ($activeGrants as $grant)
                @php
                    $req = $grant->contactRequest;
                    $sender = $req->sender;
                    $senderProfile = $sender->matrimonyProfile ?? null;
                @endphp
                <div class="border border-gray-200 dark:border-gray-600 rounded-lg p-4 mb-4 bg-white dark:bg-gray-800">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <p class="font-semibold text-gray-900 dark:text-gray-100">{{ $senderProfile->full_name ?? $sender->name ?? 'User' }}</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('notifications.scopes') }}: {{ implode(', ', $grant->granted_scopes ?? []) }} · {{ __('notifications.valid_until') }} {{ $grant->valid_until->format('M j, Y') }}</p>
                        </div>
                        <form method="POST" action="{{ route('contact-grants.revoke', $grant) }}" class="inline" onsubmit="return confirm(@js(__('notifications.confirm_revoke_access')));">
                            @csrf
                            <button type="submit" class="px-4 py-2 bg-gray-600 text-white rounded-md font-medium text-sm">{{ __('notifications.revoke_access') }}</button>
                        </form>
                    </div>
                </div>
            @empty
                <p class="text-gray-500 dark:text-gray-400">{{ __('notifications.no_active_access') }}</p>
            @endforelse
        </div>
    </div>
</div>
@endsection

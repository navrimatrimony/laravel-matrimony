@extends('layouts.app')

@section('content')

{{--
|--------------------------------------------------------------------------
| Sent Interests Page
|--------------------------------------------------------------------------
| PURPOSE:
|   - Logged-in user ने कोणकोणाला interest पाठवला आहे
|     ते यादीत दाखवणे
|
| DATA SOURCE:
|   - $sentInterests
|     → InterestController@sent मधून येतो
|
| SSOT RULE:
|   - Classic Blade Layout ONLY
|   - @extends / @section वापरायचा
|--------------------------------------------------------------------------
--}}

<div class="py-12">
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">

        <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg">
            <div class="p-6 text-gray-900 dark:text-gray-100">

                <h2 class="text-xl font-bold mb-4">
                    {{ __('interests.sent_interests') }}
                </h2>

                {{-- No interests case --}}
                @if ($sentInterests->count() === 0)
                    <p class="text-gray-600 dark:text-gray-400">
                        {{ __('interests.no_sent_interests') }}
                    </p>
                @else

                    {{-- Sent interests list --}}
                    @foreach ($sentInterests as $interest)
                    <div class="border rounded-lg p-4 mb-4 bg-gray-50 dark:bg-gray-700 flex items-center justify-between">

                        <div>
                            {{-- Receiver Name --}}
                            <p class="text-lg font-semibold">
                                {{ __('interests.to') }}: {{ $interest->receiverProfile->full_name ?? __('interests.profile_deleted') }}
                            </p>

                            {{-- Profile Link --}}
                            @if ($interest->receiverProfile)
                                <p class="mt-1">
                                    <a href="{{ route('matrimony.profile.show', $interest->receiverProfile->id) }}"
                                       class="text-blue-600 hover:underline">
                                        {{ __('interests.view_matrimony_profile') }}
                                    </a>
                                </p>
                            @endif

                            {{-- Status --}}
                            <p class="mt-1">
                                <span class="text-gray-500">{{ __('interests.status') }}:</span>
                                @if ($interest->status === 'pending')
                                    <span class="text-yellow-600 font-semibold">{{ __('interests.pending') }}</span>
                                @elseif ($interest->status === 'accepted')
                                    <span class="text-green-600 font-semibold">{{ __('interests.accepted') }}</span>
                                @elseif ($interest->status === 'rejected')
                                    <span class="text-red-600 font-semibold">{{ __('interests.rejected') }}</span>
                                @endif
                            </p>

                            {{-- 🔴 Withdraw button (ONLY for pending interests) --}}
                            @if ($interest->status === 'pending')
                                <form method="POST"
                                      action="{{ route('interests.withdraw', $interest->id) }}"
                                      class="mt-3">
                                    @csrf
                                    <button type="submit"
                                        class="text-sm text-red-600 hover:underline"
                                        onclick="return confirm('{{ __('interests.confirm_withdraw_interest') }}')">
                                        {{ __('interests.withdraw_interest') }}
                                    </button>
                                </form>
                            @endif
                        </div>

                        {{-- Receiver Photo (Right Side) with Gender-based Fallback --}}
                        <div>
                            @if ($interest->receiverProfile && $interest->receiverProfile->profile_photo && $interest->receiverProfile->photo_approved !== false)
                                <img
                                    src="{{ app(\App\Services\Image\ProfilePhotoUrlService::class)->publicUrl($interest->receiverProfile->profile_photo) }}"
                                    alt="{{ __('Profile Photo') }}"
                                    class="w-14 h-14 rounded-full object-cover border">
                            @else
                                @php
                                    $recGender = $interest->receiverProfile->gender?->key ?? null;
                                    if ($recGender === 'male') {
                                        $recPlaceholder = asset('images/placeholders/male-profile.svg');
                                    } elseif ($recGender === 'female') {
                                        $recPlaceholder = asset('images/placeholders/female-profile.svg');
                                    } else {
                                        $recPlaceholder = asset('images/placeholders/default-profile.svg');
                                    }
                                @endphp
                                <img
                                    src="{{ $recPlaceholder }}"
                                    alt="{{ __('dashboard.profile_placeholder') }}"
                                    class="w-14 h-14 rounded-full object-cover border">
                            @endif
                        </div>



                        </div>
                    @endforeach

                @endif

            </div>
        </div>

    </div>
</div>

@endsection

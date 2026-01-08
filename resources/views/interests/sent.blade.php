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
                    Sent Interests
                </h2>

                {{-- No interests case --}}
                @if ($sentInterests->count() === 0)
                    <p class="text-gray-600 dark:text-gray-400">
                        You have not sent any interests yet.
                    </p>
                @else

                    {{-- Sent interests list --}}
                    @foreach ($sentInterests as $interest)
                    <div class="border rounded-lg p-4 mb-4 bg-gray-50 dark:bg-gray-700 flex items-center justify-between">

                        <div>
                            {{-- Receiver Name --}}
                            <p class="text-lg font-semibold">
                                To: {{ $interest->receiverProfile->full_name ?? 'Profile Deleted' }}
                            </p>

                            {{-- Profile Link --}}
                            @if ($interest->receiverProfile)
                                <p class="mt-1">
                                    <a href="{{ route('matrimony.profile.show', $interest->receiverProfile->id) }}"
                                       class="text-blue-600 hover:underline">
                                        View Matrimony Profile
                                    </a>
                                </p>
                            @endif

                            {{-- Status --}}
                            <p class="mt-1">
                                <span class="text-gray-500">Status:</span>
                                @if ($interest->status === 'pending')
                                    <span class="text-yellow-600 font-semibold">Pending</span>
                                @elseif ($interest->status === 'accepted')
                                    <span class="text-green-600 font-semibold">Accepted</span>
                                @elseif ($interest->status === 'rejected')
                                    <span class="text-red-600 font-semibold">Rejected</span>
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
                                        onclick="return confirm('Are you sure you want to withdraw this interest?')">
                                        Withdraw Interest
                                    </button>
                                </form>
                            @endif
                        </div>

                        {{-- Receiver Photo (Right Side) --}}
                        <div>
                            @if ($interest->receiverProfile && $interest->receiverProfile->profile_photo)
                                <img
                                    src="{{ asset('uploads/matrimony_photos/'.$interest->receiverProfile->profile_photo) }}"
                                    alt="Profile Photo"
                                    class="w-14 h-14 rounded-full object-cover border">
                            @else
                                <img
                                    src="{{ asset('images/default-profile.png') }}"
                                    alt="Default Profile Photo"
                                    class="w-14 h-14 rounded-full object-cover border opacity-70">
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

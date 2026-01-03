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
                        <div class="border rounded p-3 mb-3">

                            <p>
                                <strong>To:</strong>
                                {{-- Receiver Profile Name --}}
        
{{ $interest->receiverProfile->full_name ?? 'Profile Deleted' }}

{{-- Receiver Profile Link (Null Safe) --}}
@if ($interest->receiverProfile)
    <a href="{{ route('matrimony.profile.show', $interest->receiverProfile->id) }}">
        View Matrimony Profile
    </a>
@endif



                        </div>
                    @endforeach

                @endif

            </div>
        </div>

    </div>
</div>

@endsection

@extends('layouts.app')

@section('content')

<div class="py-12">
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">

        {{-- Page Card --}}
        <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg">
            <div class="p-6 text-gray-900 dark:text-gray-100">

                {{-- Page Heading --}}
                <h1 class="text-2xl font-bold mb-6">
                    Matrimony Profiles
                </h1>

                <hr class="mb-4 border-gray-300">

                {{-- Search / Filter Form --}}
                <form method="GET" action="{{ route('matrimony.profiles.index') }}" class="mb-6 space-y-3">

                    <div>
                        <label>Age From:</label>
                        <input type="number" name="age_from" value="{{ request('age_from') }}">
                    </div>

                    <div>
                        <label>Age To:</label>
                        <input type="number" name="age_to" value="{{ request('age_to') }}">
                    </div>

                    <div>
                        <label>Caste:</label>
                        <input type="text" name="caste" value="{{ request('caste') }}">
                    </div>

                    <div>
                        <label>Location:</label>
                        <input type="text" name="location" value="{{ request('location') }}">
                    </div>

                    <div class="mt-2">
                        <button type="submit">Search</button>
                        <a href="{{ route('matrimony.profiles.index') }}">Reset</a>
                    </div>

                </form>

                {{-- Profiles List --}}
                @if ($profiles->isEmpty())
                    <p class="text-gray-600">
                        No profiles found.
                    </p>
                @else

                    @foreach ($profiles as $profile)
                        <div class="bg-white text-gray-900 border border-gray-200 rounded-lg p-4 mb-4 flex justify-between items-center">

                        <div class="flex items-center gap-4">


  {{-- Profile Photo --}}
<div class="mb-4 flex justify-center">

    @if ($profile->profile_photo)
        {{-- Uploaded photo --}}
        <img
            src="{{ asset('uploads/matrimony_photos/'.$profile->profile_photo) }}"
            alt="Profile Photo"
            class="w-24 h-24 rounded-full object-cover border"
        />
    @else
        {{-- Default placeholder --}}
        <img
            src="{{ asset('images/default-profile.png') }}"
            alt="Default Profile Photo"
            class="w-24 h-24 rounded-full object-cover border opacity-70"
        />
    @endif

</div>



<div>
    <p class="font-semibold text-lg">
        {{ $profile->full_name }}
    </p>
    <p class="text-sm text-gray-600">
        {{ $profile->gender }} | {{ $profile->location }}
    </p>
</div>

</div>


                            <div>
                                <a
                                    href="{{ route('matrimony.profile.show', $profile->id) }}"
                                    class="text-blue-600 hover:underline font-medium"
                                >
                                    View Profile
                                </a>
                            </div>

                        </div>
                    @endforeach

                @endif

            </div>
        </div>
    </div>
</div>

@endsection

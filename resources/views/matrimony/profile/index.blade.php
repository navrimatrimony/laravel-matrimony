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
                <form method="GET" action="{{ route('matrimony.profiles.index') }}">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">

                    {{-- Age From --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Age From
                            </label>
                            <input
                                type="number"
                                name="age_from"
                                value="{{ request('age_from') }}"
                                class="w-full border rounded px-3 py-2"
                                placeholder="Min age">
                        </div>

                        {{-- Age To --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Age To
                            </label>
                            <input
                                type="number"
                                name="age_to"
                                value="{{ request('age_to') }}"
                                class="w-full border rounded px-3 py-2"
                                placeholder="Max age">
                        </div>

                        {{-- Caste --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Caste
                            </label>
                            <input
                                type="text"
                                name="caste"
                                value="{{ request('caste') }}"
                                class="w-full border rounded px-3 py-2"
                                placeholder="Caste">
                        </div>

                        {{-- Location --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Location
                            </label>
                            <input
                                type="text"
                                name="location"
                                value="{{ request('location') }}"
                                class="w-full border rounded px-3 py-2"
                                placeholder="City">
                        </div>
                    </div>

                    <div class="flex gap-4 mb-8">
    <button
        type="submit"
        class="bg-blue-600 text-white px-5 py-2 rounded">
        Search
    </button>

    <a
        href="{{ route('matrimony.profiles.index') }}"
        class="text-gray-600 underline pt-2">
        Reset
    </a>
</div>


                </form>

                {{-- Profiles List --}}
                @if ($profiles->isEmpty())
                    <p class="text-gray-600">
                        No profiles found.
                    </p>
                @else

                    @foreach ($profiles as $matrimonyProfile)
                        <div class="bg-white text-gray-900 border border-gray-200 rounded-lg p-4 mb-4 flex justify-between items-center">

                        <div class="flex items-center gap-4">


  {{-- Profile Photo --}}
<div class="mb-4 flex justify-center">

    @if ($matrimonyProfile->
profile_photo)
        {{-- Uploaded photo --}}
        <img
            src="{{ asset('uploads/matrimony_photos/'.$matrimonyProfile->
profile_photo) }}"
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
        {{ $matrimonyProfile->
full_name }}
    </p>
    <p class="text-sm text-gray-600">
    <span class="text-sm text-gray-600">
        {{-- Gender --}}
        {{ ucfirst($matrimonyProfile->gender) }}

        {{-- Age (calculated from DOB) --}}
        @if ($matrimonyProfile->date_of_birth)
            | {{ \Carbon\Carbon::parse($matrimonyProfile->date_of_birth)->age }} yrs
        @endif

        {{-- Location --}}
        @if ($matrimonyProfile->location)
            | {{ ucfirst($matrimonyProfile->location) }}
        @endif
    </span>
</p>

</div>

</div>


<div>
    @auth
        <a
            href="{{ route('matrimony.profile.show', $matrimonyProfile->id) }}"
            class="text-blue-600 hover:underline font-medium"
        >
            View Profile
        </a>
    @else
        <a
            href="{{ route('login') }}"
            class="text-gray-500 hover:underline font-medium"
            title="Login required to view full profile"
        >
            Login to View Profile
        </a>
    @endauth
</div>
 

                        </div>
                    @endforeach

                @endif

            </div>
        </div>
    </div>
</div>

@endsection

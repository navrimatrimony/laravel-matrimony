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
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Age From</label>
                            <input type="number" name="age_from" value="{{ request('age_from') }}" class="w-full border rounded px-3 py-2" placeholder="Min age">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Age To</label>
                            <input type="number" name="age_to" value="{{ request('age_to') }}" class="w-full border rounded px-3 py-2" placeholder="Max age">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Caste</label>
                            <input type="text" name="caste" value="{{ request('caste') }}" class="w-full border rounded px-3 py-2" placeholder="Caste">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Location</label>
                            <input type="text" name="location" value="{{ request('location') }}" class="w-full border rounded px-3 py-2" placeholder="City">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Height From (cm)</label>
                            <input type="number" name="height_from" value="{{ request('height_from') }}" class="w-full border rounded px-3 py-2" placeholder="Min cm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Height To (cm)</label>
                            <input type="number" name="height_to" value="{{ request('height_to') }}" class="w-full border rounded px-3 py-2" placeholder="Max cm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Marital Status</label>
                            <select name="marital_status" class="w-full border rounded px-3 py-2">
                                <option value="">—</option>
                                <option value="single" {{ request('marital_status') === 'single' ? 'selected' : '' }}>Single</option>
                                <option value="divorced" {{ request('marital_status') === 'divorced' ? 'selected' : '' }}>Divorced</option>
                                <option value="widowed" {{ request('marital_status') === 'widowed' ? 'selected' : '' }}>Widowed</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Education</label>
                            <input type="text" name="education" value="{{ request('education') }}" class="w-full border rounded px-3 py-2" placeholder="Education">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Per page</label>
                            <select name="per_page" class="w-full border rounded px-3 py-2">
                                @foreach ([15, 25, 50] as $n)
                                    <option value="{{ $n }}" {{ (int) request('per_page', 15) === $n ? 'selected' : '' }}>{{ $n }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="flex gap-4 mb-8">
                        <button type="submit" class="bg-blue-600 text-white px-5 py-2 rounded">Search</button>
                        <a href="{{ route('matrimony.profiles.index') }}" class="text-gray-600 underline pt-2">Reset</a>
                    </div>
                </form>

                {{-- Profiles List --}}
                @if ($profiles->isEmpty())
                    <p class="text-gray-600">No profiles found.</p>
                @else
                    @foreach ($profiles as $matrimonyProfile)
                        <div class="bg-white text-gray-900 border border-gray-200 rounded-lg p-4 mb-4 flex justify-between items-center">

                        <div class="flex items-center gap-4">


  {{-- Profile Photo with Gender-based Fallback --}}
<div class="mb-4 flex justify-center">

    @if ($matrimonyProfile->profile_photo && $matrimonyProfile->photo_approved !== false)
        {{-- Real uploaded photo --}}
        <img
            src="{{ asset('uploads/matrimony_photos/'.$matrimonyProfile->profile_photo) }}"
            alt="Profile Photo"
            class="w-24 h-24 rounded-full object-cover border"
        />
    @else
        {{-- Gender-based placeholder fallback (UI only) --}}
        @php
            $gender = $matrimonyProfile->gender ?? null;
            if ($gender === 'male') {
                $placeholderSrc = asset('images/placeholders/male-profile.svg');
            } elseif ($gender === 'female') {
                $placeholderSrc = asset('images/placeholders/female-profile.svg');
            } else {
                $placeholderSrc = asset('images/placeholders/default-profile.svg');
            }
        @endphp
        <img
            src="{{ $placeholderSrc }}"
            alt="Profile Placeholder"
            class="w-24 h-24 rounded-full object-cover border"
        />
    @endif

</div>



<div>
    <p class="font-semibold text-lg">{{ $matrimonyProfile->full_name }}</p>
    <p class="text-sm text-gray-600">
    <span class="text-sm text-gray-600">
        {{-- Gender --}}
        {{ ucfirst($matrimonyProfile->gender) }}

        {{-- Age (calculated from DOB) --}}
        @if ($matrimonyProfile->date_of_birth)
            | {{ \Carbon\Carbon::parse($matrimonyProfile->date_of_birth)->age }} yrs
        @endif

        {{-- Location (hierarchy) --}}
        @php
            $locLine1 = trim(implode(', ', array_filter([$matrimonyProfile->city?->name ?? null, $matrimonyProfile->taluka?->name ?? null])));
            $locLine2 = trim(implode(', ', array_filter([$matrimonyProfile->district?->name ?? null, $matrimonyProfile->state?->name ?? null])));
            $locLine3 = $matrimonyProfile->country?->name ?? '';
            $locDisplay = $locLine1 ?: ($locLine2 ?: ($locLine3 ?: null));
        @endphp
        @if ($locDisplay)
            | {{ $locDisplay }}
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

                    <div class="mt-6">{{ $profiles->links() }}</div>
                @endif

            </div>
        </div>
    </div>
</div>

@endsection

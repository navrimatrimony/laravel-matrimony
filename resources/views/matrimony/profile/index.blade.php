@extends('layouts.app')

@section('content')

<div class="py-12">
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">

        {{-- Page Card --}}
        <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg">
            <div class="p-6 text-gray-900 dark:text-gray-100">

                {{-- Page Heading --}}
                <h1 class="text-2xl font-bold mb-6">
                    {{ __('search.matrimony_profiles') }}
                </h1>

                <hr class="mb-4 border-gray-300">

                {{-- Search / Filter Form --}}
                <form method="GET" action="{{ route('matrimony.profiles.index') }}">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('search.age_from') }}</label>
                            <input type="number" name="age_from" value="{{ request('age_from') }}" class="w-full border rounded px-3 py-2" placeholder="{{ __('search.min_age') }}">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('search.age_to') }}</label>
                            <input type="number" name="age_to" value="{{ request('age_to') }}" class="w-full border rounded px-3 py-2" placeholder="{{ __('search.max_age') }}">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Caste') }}</label>
                            <input type="text" name="caste" value="{{ request('caste') }}" class="w-full border rounded px-3 py-2" placeholder="{{ __('Caste') }}">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Location') }}</label>
                            <input type="text" name="location" value="{{ request('location') }}" class="w-full border rounded px-3 py-2" placeholder="{{ __('search.city') }}">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('search.height_from_cm') }}</label>
                            <input type="number" name="height_from" value="{{ request('height_from') }}" class="w-full border rounded px-3 py-2" placeholder="{{ __('search.min_cm') }}">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('search.height_to_cm') }}</label>
                            <input type="number" name="height_to" value="{{ request('height_to') }}" class="w-full border rounded px-3 py-2" placeholder="{{ __('search.max_cm') }}">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Marital Status') }}</label>
                            <select name="marital_status" class="w-full border rounded px-3 py-2">
                                <option value="">—</option>
                                <option value="single" {{ request('marital_status') === 'single' ? 'selected' : '' }}>{{ __('search.single') }}</option>
                                <option value="divorced" {{ request('marital_status') === 'divorced' ? 'selected' : '' }}>{{ __('search.divorced') }}</option>
                                <option value="widowed" {{ request('marital_status') === 'widowed' ? 'selected' : '' }}>{{ __('search.widowed') }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Education') }}</label>
                            <input type="text" name="education" value="{{ request('education') }}" class="w-full border rounded px-3 py-2" placeholder="{{ __('Education') }}">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('search.per_page') }}</label>
                            <select name="per_page" class="w-full border rounded px-3 py-2">
                                @foreach ([15, 25, 50] as $n)
                                    <option value="{{ $n }}" {{ (int) request('per_page', 15) === $n ? 'selected' : '' }}>{{ $n }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="flex gap-4 mb-8">
                        <button type="submit" class="bg-blue-600 text-white px-5 py-2 rounded">{{ __('search.search') }}</button>
                        <a href="{{ route('matrimony.profiles.index') }}" class="text-gray-600 underline pt-2">{{ __('search.reset') }}</a>
                    </div>
                </form>

                {{-- Profiles List --}}
                @if ($profiles->isEmpty())
                    <p class="text-gray-600">{{ __('search.no_profiles_found') }}</p>
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
            alt="{{ __('Profile Photo') }}"
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
            alt="{{ __('dashboard.profile_placeholder') }}"
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
            | {{ \Carbon\Carbon::parse($matrimonyProfile->date_of_birth)->age }} {{ __('search.years_short') }}
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
            {{ __('search.view_profile') }}
        </a>
    @else
        <a
            href="{{ route('login') }}"
            class="text-gray-500 hover:underline font-medium"
            title="{{ __('search.login_required_to_view_full_profile') }}"
        >
            {{ __('search.login_to_view_profile') }}
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

@extends('layouts.app')

@section('content')
<div class="max-w-5xl mx-auto py-8">
    <h1 class="text-2xl font-bold mb-6">
        Matrimony Profiles
    </h1>
@if ($profiles->isEmpty())
    <p class="text-gray-600">
        No profiles found.
    </p>
@else

<form method="GET" action="{{ url('/profiles') }}" style="margin-bottom:20px;">
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

    <div style="margin-top:10px;">
        <button type="submit">Search</button>
        <a href="{{ url('/profiles') }}">Reset</a>
    </div>
</form>


    @foreach ($profiles as $profile)
        <div class="bg-white shadow rounded p-4 mb-4 flex justify-between items-center">
            <div>
                <p class="font-semibold">{{ $profile->full_name }}</p>
                <p class="text-sm text-gray-600">
                    {{ $profile->gender }} | {{ $profile->location }}
                </p>
            </div>

            <a href="{{ url('/profile/' . $profile->id) }}"
               class="text-blue-600 hover:underline">
                View Profile
            </a>
        </div>
		
    @endforeach
	@endif

</div>
@endsection

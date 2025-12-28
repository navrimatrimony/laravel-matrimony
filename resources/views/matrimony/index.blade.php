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

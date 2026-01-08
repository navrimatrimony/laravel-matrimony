@extends('layouts.app')

@section('content')



<div class="max-w-3xl mx-auto py-8">
    <h1 class="text-2xl font-bold mb-6">
        Matrimony Profile
    </h1>



    
<div class="bg-white shadow rounded-lg p-6">

{{-- Profile Photo --}}
<div class="mb-6 flex justify-center">

    @if ($matrimonyProfile->profile_photo)
        {{-- Uploaded profile photo --}}
        <img
            src="{{ asset('uploads/matrimony_photos/'.$matrimonyProfile->profile_photo) }}"
            alt="Profile Photo"
            class="w-40 h-40 rounded-full object-cover border"
        />
    @else
        {{-- Default placeholder photo (no upload yet) --}}
        <img
            src="{{ asset('images/default-profile.png') }}"
            alt="Default Profile Photo"
            class="w-40 h-40 rounded-full object-cover border opacity-70"
        />
    @endif

</div>



{{-- Name & Gender --}}
<div class="text-center mb-6">
    <h2 class="text-2xl font-semibold">
        {{ $matrimonyProfile->full_name }}
    </h2>
    <p class="text-gray-500">
        {{ ucfirst($matrimonyProfile->gender) }}
    </p>
</div>

{{-- Biodata Grid --}}
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">

    <div>
        <p class="text-gray-500 text-sm">Date of Birth</p>
        <p class="font-medium text-base">{{ $matrimonyProfile->date_of_birth }}</p>
    </div>

    <div>
        <p class="text-gray-500 text-sm">Education</p>
        <p class="font-medium text-base">{{ $matrimonyProfile->education }}</p>
    </div>

    <div>
        <p class="text-gray-500 text-sm">Location</p>
        <p class="font-medium text-base">{{ $matrimonyProfile->location }}</p>
    </div>

    <div>
        <p class="text-gray-500 text-sm">Caste</p>
        <p class="font-medium text-base">{{ $matrimonyProfile->caste }}</p>
    </div>

</div>


</div>

	
	<hr>



<hr>

	
{{-- 🔒 Interest button hidden on own profile --}}

   

@if (auth()->check() && !$isOwnProfile)

	<h3 class="text-lg font-semibold mt-6 mb-3 text-center">
    Express Interest
</h3>

    @if ($interestAlreadySent)
        <button disabled
            style="margin-top:15px; padding:10px; background:#9ca3af; color:white; border:none;">
            Interest Sent
        </button>
    @else
        <form method="POST" action="{{ route('interests.send', $matrimonyProfile) }}">

            @csrf
            <button type="submit"
                style="margin-top:15px; padding:10px; background:#ec4899; color:white; border:none;">
                Send Interest
            </button>
        </form>
    @endif

@endif

</div>
@endsection

@extends('layouts.app')

@section('content')
{{-- Flash Messages --}}
@if (session('success'))
    <div style="margin:15px 0; padding:10px; background:#d1fae5; color:#065f46; border:1px solid #10b981;">
        {{ session('success') }}
    </div>
@endif

@if (session('error'))
    <div style="margin:15px 0; padding:10px; background:#fee2e2; color:#7f1d1d; border:1px solid #ef4444;">
        {{ session('error') }}
    </div>
@endif


<div class="max-w-3xl mx-auto py-8">
    <h1 class="text-2xl font-bold mb-6">
        Matrimony Profile
    </h1>

    <div class="bg-white shadow rounded p-6 space-y-3">
        <p><strong>Full Name:</strong> {{ $matrimonyProfile->full_name }}</p>
        <p><strong>Gender:</strong> {{ $matrimonyProfile->gender }}</p>
        <p><strong>Date of Birth:</strong> {{ $matrimonyProfile->date_of_birth }}</p>
        <p><strong>Education:</strong> {{ $matrimonyProfile->education }}</p>
        <p><strong>Location:</strong> {{ $matrimonyProfile->location }}</p>
		<p><strong>Caste:</strong> {{ $matrimonyProfile->caste }}</p>

    </div>
	
	<hr>

<p><strong>DEBUG INFO</strong></p>
<p>Login User ID: {{ auth()->id() }}</p>
<p>Profile User ID: {{ $matrimonyProfile->user_id }}</p>
<p>isOwnProfile: {{ $isOwnProfile ? 'TRUE' : 'FALSE' }}</p>

<hr>

	
	    {{-- Interest Section --}}
   
@if (!$isOwnProfile)

    @if ($interestAlreadySent)
        <button disabled
            style="margin-top:15px; padding:10px; background:#9ca3af; color:white; border:none;">
            Interest Sent
        </button>
    @else
        <form method="POST" action="{{ route('interests.send', $matrimonyProfile->user_id) }}">
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

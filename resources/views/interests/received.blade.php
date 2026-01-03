@extends('layouts.app')

@section('content')
<div class="container">

    <h2>Received Interests</h2>

    {{-- 
        Meaning:
        $receivedInterests = 
        मला आलेले सर्व interests (receiver_id = my id)
    --}}

    @forelse ($receivedInterests as $interest)
        <div class="card mb-2 p-2">

            <p>
                <strong>From:</strong>
{{ $interest->senderProfile->full_name ?? 'Profile Deleted' }}

@if ($interest->senderProfile)
    <a href="{{ route('matrimony.profile.show', $interest->senderProfile->id) }}">
        View Matrimony Profile
    </a>
@endif
 </p>

            {{-- Matrimony Profile Link --}}
           
 


        </div>
    @empty
        <p>No received interests.</p>
    @endforelse

</div>
@endsection

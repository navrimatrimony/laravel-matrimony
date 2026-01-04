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
    <div class="border rounded-lg p-4 mb-4 bg-gray-50">

{{-- Sender Name --}}
<p class="text-lg font-semibold">
    From: {{ $interest->senderProfile->full_name ?? 'Profile Deleted' }}
</p>

{{-- View Profile --}}
@if ($interest->senderProfile)
    <p class="mt-1">
        <a href="{{ route('matrimony.profile.show', $interest->senderProfile->id) }}"
           class="text-blue-600 hover:underline">
            View Matrimony Profile
        </a>
    </p>
@endif

{{-- Status --}}
<p class="mt-2">
    Status:
    @if ($interest->status === 'pending')
        <span class="text-yellow-600 font-semibold">Pending</span>
    @elseif ($interest->status === 'accepted')
        <span class="text-green-600 font-semibold">Accepted</span>
    @elseif ($interest->status === 'rejected')
        <span class="text-red-600 font-semibold">Rejected</span>
    @endif
</p>

{{-- Accept / Reject buttons --}}
@if ($interest->status === 'pending')
    <div class="mt-3 flex gap-3">
        <form method="POST" action="{{ route('interests.accept', $interest->id) }}">
            @csrf
            <button type="submit"
                    class="px-4 py-1 bg-green-600 text-white rounded hover:bg-green-700">
                Accept
            </button>
        </form>

        <form method="POST" action="{{ route('interests.reject', $interest->id) }}">
            @csrf
            <button type="submit"
                    class="px-4 py-1 bg-red-600 text-white rounded hover:bg-red-700">
                Reject
            </button>
        </form>
    </div>
@endif

</div>

    @empty
        <p>No received interests.</p>
    @endforelse

</div>
@endsection

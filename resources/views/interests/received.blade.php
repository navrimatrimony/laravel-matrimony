@extends('layouts.app')

@section('content')
<div class="py-12">
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">

        <h2 class="text-xl font-semibold mb-4">Received Interests</h2>

        @forelse ($receivedInterests as $interest)

            <div class="border rounded-lg p-4 mb-4 bg-gray-50">

                {{-- Sender Name --}}
                <p class="text-lg font-semibold">
                    From: {{ optional($interest->senderProfile)->full_name ?? 'Profile Deleted' }}
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
                <p class="mt-1">
                    <span class="text-gray-500">Status:</span>
                    @if ($interest->status === 'pending')
                        <span class="text-yellow-600 font-semibold">Pending</span>
                    @elseif ($interest->status === 'accepted')
                        <span class="text-green-600 font-semibold">Accepted</span>
                    @elseif ($interest->status === 'rejected')
                        <span class="text-red-600 font-semibold">Rejected</span>
                    @endif
                </p>
<div class="flex items-center gap-4">
    @if ($interest->senderProfile && $interest->senderProfile->profile_photo && $interest->senderProfile->photo_approved !== false)
        <img
            src="{{ asset('uploads/matrimony_photos/'.$interest->senderProfile->profile_photo) }}"
            class="w-14 h-14 rounded-full object-cover border"
        >
    @else
        @php
            $senderProfile = $interest->senderProfile;
            $senderGender = $senderProfile ? ($senderProfile->gender ?? null) : null;
            if ($senderGender === 'male') {
                $senderPlaceholder = asset('images/placeholders/male-profile.svg');
            } elseif ($senderGender === 'female') {
                $senderPlaceholder = asset('images/placeholders/female-profile.svg');
            } else {
                $senderPlaceholder = asset('images/placeholders/default-profile.svg');
            }
        @endphp
        <img
            src="{{ $senderPlaceholder }}"
            class="w-14 h-14 rounded-full object-cover border"
        >
    @endif

    <div>
    </div>
</div>

                {{-- Accept / Reject --}}
                @if ($interest->status === 'pending')
                    <div class="mt-4 flex flex-col sm:flex-row gap-3">

                        {{-- Accept --}}
                        <form method="POST" action="{{ route('interests.accept', $interest) }}">
                            @csrf
                            <button type="submit" class="inline-flex items-center px-4 py-2 bg-green-600 border border-transparent rounded-md font-semibold text-sm text-white tracking-wide hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 transition disabled:opacity-50 disabled:cursor-not-allowed shadow-sm w-full sm:w-auto">
                                Accept
                            </button>
                        </form>

                        {{-- Reject --}}
                        <form method="POST" action="{{ route('interests.reject', $interest) }}">
                            @csrf
                            <button type="submit" class="inline-flex items-center px-4 py-2 bg-white border-2 border-red-600 rounded-md font-semibold text-sm text-red-600 tracking-wide hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition w-full sm:w-auto">
                                Reject
                            </button>
                        </form>

                    </div>
                @endif

            </div>

        @empty
            <p class="text-gray-600">
                No received interests.
            </p>
        @endforelse

    </div>
</div>
@endsection

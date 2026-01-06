@extends('layouts.app')

@section('content')
<div class="py-12">
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">

        <h2 class="text-xl font-semibold mb-4">Received Interests</h2>

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

                {{-- Accept / Reject --}}
                @if ($interest->status === 'pending')
                    <div class="mt-4 flex flex-col sm:flex-row gap-3">

                        {{-- Accept --}}
                        <form method="POST" action="{{ route('interests.accept', $interest) }}">
                            @csrf
                            <button type="submit"
                                style="background-color: #16a34a; color: white; padding: 0.625rem 1.5rem; border-radius: 0.375rem; font-weight: 600; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); width: 100%;"
                                class="sm:w-auto hover:bg-green-700 transition-colors">
                                Accept
                            </button>


                        </form>

                        {{-- Reject --}}
                        <form method="POST" action="{{ route('interests.reject', $interest) }}">
                            @csrf
                            <button type="submit"
                                style="border: 2px solid #dc2626; background-color: white; color: #dc2626; padding: 0.625rem 1.5rem; border-radius: 0.375rem; font-weight: 600; width: 100%;"
                                class="sm:w-auto hover:bg-red-50 transition-colors">
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

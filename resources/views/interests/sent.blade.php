@extends('layouts.app')

@section('content')
<div class="container">

    <h2 class="text-xl font-bold mb-4">Sent Interests</h2>

    {{-- 
        Meaning:
        $sentInterests =
        Logged-in user ने पाठवलेले सर्व interests
    --}}

    @if ($sentInterests->count() === 0)
        <p class="text-gray-600">
            You have not sent any interests yet.
        </p>
    @else
        @foreach ($sentInterests as $interest)
            <div class="card mb-2 p-2 border rounded">

                <p>
                    <strong>To:</strong>
                    {{ $interest->receiverProfile->full_name ?? 'Profile Deleted' }}

                </p>

                <a href="{{ route('matrimony.profile.show', $interest->receiverProfile->id) }}">
				View Matrimony Profile
				</a>


            </div>
        @endforeach
    @endif

</div>
@endsection

@extends('layouts.app')

@section('content')
<div class="py-12">
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg">
            <div class="p-6 text-gray-900 dark:text-gray-100">
                <h2 class="text-xl font-bold mb-4">My Shortlist</h2>
                @if (session('success'))
                    <p class="text-green-600 dark:text-green-400 mb-4">{{ session('success') }}</p>
                @endif
                @if ($entries->isEmpty())
                    <p class="text-gray-600 dark:text-gray-400">Your shortlist is empty.</p>
                @else
                    @foreach ($entries as $e)
                        @php $p = $e->shortlistedProfile; @endphp
                        @if (!$p) @continue @endif
                        <div class="border rounded-lg p-4 mb-4 bg-gray-50 dark:bg-gray-700 flex items-center justify-between">
                            <div class="flex items-center gap-4">
                                @if ($p->profile_photo && $p->photo_approved !== false)
                                    <img src="{{ asset('uploads/matrimony_photos/'.$p->profile_photo) }}" alt="" class="w-14 h-14 rounded-full object-cover border">
                                @else
                                    @php
                                        $pGender = $p->gender ?? null;
                                        if ($pGender === 'male') {
                                            $pPlaceholder = asset('images/placeholders/male-profile.svg');
                                        } elseif ($pGender === 'female') {
                                            $pPlaceholder = asset('images/placeholders/female-profile.svg');
                                        } else {
                                            $pPlaceholder = asset('images/placeholders/default-profile.svg');
                                        }
                                    @endphp
                                    <img src="{{ $pPlaceholder }}" alt="" class="w-14 h-14 rounded-full object-cover border">
                                @endif
                                <div>
                                    <p class="font-semibold">{{ $p->full_name }}</p>
                                    <a href="{{ route('matrimony.profile.show', $p->id) }}" class="text-blue-600 hover:underline text-sm">View profile</a>
                                </div>
                            </div>
                            <form method="POST" action="{{ route('shortlist.destroy', $p->id) }}" class="inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-sm text-red-600 hover:underline">Remove</button>
                            </form>
                        </div>
                    @endforeach
                @endif
            </div>
        </div>
    </div>
</div>
@endsection

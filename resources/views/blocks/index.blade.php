@extends('layouts.app')

@section('content')
<div class="py-12">
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg">
            <div class="p-6 text-gray-900 dark:text-gray-100">
                <h2 class="text-xl font-bold mb-4">Blocked Profiles</h2>
                @if (session('success'))
                    <p class="text-green-600 dark:text-green-400 mb-4">{{ session('success') }}</p>
                @endif
                @if ($entries->isEmpty())
                    <p class="text-gray-600 dark:text-gray-400">You have not blocked any profiles.</p>
                @else
                    @foreach ($entries as $e)
                        @php $p = $e->blockedProfile; @endphp
                        @if (!$p) @continue @endif
                        <div class="border rounded-lg p-4 mb-4 bg-gray-50 dark:bg-gray-700 flex items-center justify-between">
                            <div>
                                <p class="font-semibold">{{ $p->full_name }}</p>
                            </div>
                            <form method="POST" action="{{ route('blocks.destroy', $p->id) }}" class="inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-sm text-green-600 hover:underline">Unblock</button>
                            </form>
                        </div>
                    @endforeach
                @endif
            </div>
        </div>
    </div>
</div>
@endsection

@extends('layouts.admin')

@section('content')
<div class="space-y-6 text-gray-900">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">{{ __('matching_engine.preview_title') }}</h1>
        <p class="text-sm text-gray-600 mt-1">{{ __('matching_engine.preview_intro') }}</p>
    </div>
    <form method="GET" action="{{ route('admin.matching-engine.preview') }}" class="flex flex-wrap gap-3 items-end rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <div>
            <label class="mb-1 block text-sm font-medium text-gray-800">{{ __('matching_engine.preview_profile_id') }}</label>
            <input type="number" name="profile_id" value="{{ $profileId }}" min="1" class="w-40 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500" />
        </div>
        <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">{{ __('matching_engine.preview_run') }}</button>
    </form>

    @if ($profileId > 0 && ! $profile)
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">Profile not found.</div>
    @endif

    @if ($profile)
        <p class="text-sm text-gray-700">Preview for <strong>#{{ $profile->id }}</strong> {{ $profile->full_name ? '— '.$profile->full_name : '' }}</p>
        <div class="space-y-4">
            @forelse ($rows as $row)
                @php $p = $row['profile']; @endphp
                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                    <div class="flex flex-wrap justify-between gap-2 border-b border-gray-100 p-4">
                        <span class="font-semibold text-gray-900">{{ $p->full_name ?: 'Profile #'.$p->id }}</span>
                        <span class="text-sm font-bold text-rose-600">Score {{ $row['score'] }}</span>
                    </div>
                    @if (! empty($row['explain']))
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-left text-gray-600">
                                <tr><th class="px-4 py-2">Reason</th><th class="px-4 py-2 w-24">Impact</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($row['explain'] as $line)
                                    <tr class="border-t border-gray-100">
                                        <td class="px-4 py-2 text-gray-800">{{ $line['reason'] }}</td>
                                        <td class="px-4 py-2 font-mono {{ ($line['impact'] ?? 0) < 0 ? 'text-red-600' : 'text-emerald-600' }}">{{ $line['impact'] > 0 ? '+' : '' }}{{ $line['impact'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            @empty
                <p class="text-sm text-gray-600">No matches (pool empty or gates excluded everyone).</p>
            @endforelse
        </div>
    @endif
</div>
@endsection

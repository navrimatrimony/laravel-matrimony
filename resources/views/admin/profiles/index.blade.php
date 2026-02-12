@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-6">All Profiles</h1>

    @if ($profiles->isEmpty())
        <p class="text-gray-600 dark:text-gray-400">No profiles found.</p>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">ID</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Name</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Gender</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Location</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Status</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Action</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ($profiles as $profile)
                        <tr>
                            <td class="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">{{ $profile->id }}</td>
                            <td class="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">{{ $profile->full_name ?? '—' }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">{{ ucfirst($profile->gender ?? '—') }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">
                                @php
                                    $c = $profile->city?->name ?? null;
                                    $t = $profile->taluka?->name ?? null;
                                    $d = $profile->district?->name ?? null;
                                    $s = $profile->state?->name ?? null;
                                    $co = $profile->country?->name ?? null;
                                    $l1 = trim(implode(', ', array_filter([$c, $t])));
                                    $l2 = trim(implode(', ', array_filter([$d, $s])));
                                    $locText = ($l1 || $l2 || $co) ? implode(' — ', array_filter([$l1, $l2, $co])) : '—';
                                @endphp
                                {{ $locText }}
                            </td>
                            <td class="px-4 py-2 text-sm">
                                @if ($profile->trashed())
                                    <span class="text-red-600 dark:text-red-400">Deleted</span>
                                @elseif ($profile->is_suspended ?? false)
                                    <span class="text-amber-600 dark:text-amber-400">Suspended</span>
                                @else
                                    <span class="text-emerald-600 dark:text-emerald-400">Active</span>
                                @endif
                            </td>
                            <td class="px-4 py-2">
                                <a href="{{ route('admin.profiles.show', $profile->id) }}" class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 font-medium">
                                    View Profile
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-6">{{ $profiles->links() }}</div>
    @endif
</div>
@endsection

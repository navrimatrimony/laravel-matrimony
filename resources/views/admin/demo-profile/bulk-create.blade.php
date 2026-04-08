@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">Bulk Create Showcase Profiles (1–50)</h1>
    <p class="text-gray-500 dark:text-gray-400 text-sm mb-6">Create multiple showcase profiles. New profiles are created as <strong>draft</strong> and are <strong>not visible in member search</strong> until you publish.</p>
    @if ($errors->any())
        <ul class="text-red-600 text-sm mb-4 space-y-1">
            @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
    @endif
    @if (session('success'))
        <div class="mb-4 rounded border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-900/40 dark:bg-emerald-950/30 dark:text-emerald-200">
            {{ session('success') }}
        </div>
    @endif
    <form method="POST" action="{{ route('admin.demo-profile.bulk-store') }}" class="space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Number of profiles (1–50)</label>
            <input type="number" name="count" min="1" max="50" value="{{ old('count', '5') }}" required class="border border-gray-300 dark:border-gray-600 rounded px-3 py-2 w-24 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Gender</label>
            <select name="gender" class="border border-gray-300 dark:border-gray-600 rounded px-3 py-2 w-full max-w-xs bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                <option value="random" {{ old('gender', 'random') === 'random' ? 'selected' : '' }}>Random</option>
                <option value="male" {{ old('gender') === 'male' ? 'selected' : '' }}>Male</option>
                <option value="female" {{ old('gender') === 'female' ? 'selected' : '' }}>Female</option>
            </select>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Random = each profile gets random gender. Otherwise all use selected gender; other fields remain random per profile.</p>
        </div>
        <p class="text-sm text-gray-500 dark:text-gray-400">All other mandatory fields are auto-filled with random values per profile. No manual input.</p>
        <div class="flex gap-3">
            <button type="submit" style="background-color: #059669; color: white; padding: 10px 20px; border-radius: 6px; font-weight: 600; font-size: 14px; border: none; cursor: pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">Create</button>
            <a href="{{ route('admin.demo-profile.create') }}" class="px-4 py-2 text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 text-sm">Cancel</a>
        </div>
    </form>
</div>

@php
    $created = $createdProfiles ?? collect();
    $drafts = $recentDrafts ?? collect();
@endphp

@if ($created->isNotEmpty() || $drafts->isNotEmpty())
    <div class="mt-6 bg-white dark:bg-gray-800 shadow rounded-lg p-6">
        <div class="flex items-center justify-between gap-4 mb-4">
            <div class="min-w-0">
                <h2 class="text-lg font-bold text-gray-800 dark:text-gray-100">Draft showcase profiles</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">Only profiles you publish will become visible to members in search.</p>
            </div>
        </div>

        @if ($created->isNotEmpty())
            <div class="mb-5 rounded border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-900 dark:border-indigo-900/40 dark:bg-indigo-950/30 dark:text-indigo-200">
                <strong>Just created:</strong> {{ $created->count() }} profile(s)
            </div>
        @endif

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                        <th class="py-2 pr-4">ID</th>
                        <th class="py-2 pr-4">Name</th>
                        <th class="py-2 pr-4">Lifecycle</th>
                        <th class="py-2 pr-4">Photo</th>
                        <th class="py-2 pr-4">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($created as $p)
                        <tr>
                            <td class="py-3 pr-4 font-semibold text-gray-900 dark:text-gray-100">#{{ $p->id }}</td>
                            <td class="py-3 pr-4 text-gray-800 dark:text-gray-200">{{ $p->full_name ?? ('Profile #' . $p->id) }}</td>
                            <td class="py-3 pr-4"><span class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-gray-900 dark:text-gray-200">{{ $p->lifecycle_state ?? '—' }}</span></td>
                            <td class="py-3 pr-4">
                                <img src="{{ $p->profile_photo_url }}" alt="" class="h-10 w-10 rounded-full object-cover border border-gray-200 dark:border-gray-700" />
                            </td>
                            <td class="py-3 pr-4">
                                <div class="flex flex-wrap gap-2">
                                    <a href="{{ route('matrimony.profile.wizard.section', ['section' => 'full', 'all' => 1, 'profile_id' => $p->id]) }}" class="px-3 py-1.5 rounded bg-gray-100 hover:bg-gray-200 text-gray-800 dark:bg-gray-900 dark:hover:bg-gray-800 dark:text-gray-100">Edit all</a>
                                    <a href="{{ route('matrimony.profile.upload-photo', ['profile_id' => $p->id]) }}" class="px-3 py-1.5 rounded bg-indigo-600 hover:bg-indigo-700 text-white">Photos</a>
                                    @if (($p->lifecycle_state ?? null) !== 'active')
                                        <form method="POST" action="{{ route('admin.demo-profile.publish', $p->id) }}">
                                            @csrf
                                            <button type="submit" class="px-3 py-1.5 rounded bg-emerald-600 hover:bg-emerald-700 text-white">Publish</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('admin.demo-profile.delete', $p->id) }}" onsubmit="return confirm('Delete this showcase profile?');">
                                        @csrf
                                        <button type="submit" class="px-3 py-1.5 rounded bg-red-600 hover:bg-red-700 text-white">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach

                    @foreach ($drafts as $p)
                        @if ($created->pluck('id')->contains($p->id))
                            @continue
                        @endif
                        <tr>
                            <td class="py-3 pr-4 font-semibold text-gray-900 dark:text-gray-100">#{{ $p->id }}</td>
                            <td class="py-3 pr-4 text-gray-800 dark:text-gray-200">{{ $p->full_name ?? ('Profile #' . $p->id) }}</td>
                            <td class="py-3 pr-4"><span class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-gray-900 dark:text-gray-200">{{ $p->lifecycle_state ?? '—' }}</span></td>
                            <td class="py-3 pr-4">
                                <img src="{{ $p->profile_photo_url }}" alt="" class="h-10 w-10 rounded-full object-cover border border-gray-200 dark:border-gray-700" />
                            </td>
                            <td class="py-3 pr-4">
                                <div class="flex flex-wrap gap-2">
                                    <a href="{{ route('matrimony.profile.wizard.section', ['section' => 'full', 'all' => 1, 'profile_id' => $p->id]) }}" class="px-3 py-1.5 rounded bg-gray-100 hover:bg-gray-200 text-gray-800 dark:bg-gray-900 dark:hover:bg-gray-800 dark:text-gray-100">Edit all</a>
                                    <a href="{{ route('matrimony.profile.upload-photo', ['profile_id' => $p->id]) }}" class="px-3 py-1.5 rounded bg-indigo-600 hover:bg-indigo-700 text-white">Photos</a>
                                    <form method="POST" action="{{ route('admin.demo-profile.publish', $p->id) }}">
                                        @csrf
                                        <button type="submit" class="px-3 py-1.5 rounded bg-emerald-600 hover:bg-emerald-700 text-white">Publish</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.demo-profile.delete', $p->id) }}" onsubmit="return confirm('Delete this showcase profile?');">
                                        @csrf
                                        <button type="submit" class="px-3 py-1.5 rounded bg-red-600 hover:bg-red-700 text-white">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection

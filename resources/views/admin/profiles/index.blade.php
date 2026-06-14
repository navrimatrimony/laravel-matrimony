@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <div class="mb-6 flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">All Profiles</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Filter by profile source, status, ID, name, phone, email, or Suchak details.</p>
        </div>
        <form method="GET" action="{{ route('admin.profiles.index') }}" class="grid w-full grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-6 xl:max-w-6xl">
            <label class="sm:col-span-2 lg:col-span-2">
                <span class="sr-only">Search profiles</span>
                <input
                    type="search"
                    name="q"
                    value="{{ $filters['q'] ?? '' }}"
                    placeholder="Search name, ID, phone, email"
                    class="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                >
            </label>
            <label>
                <span class="sr-only">Source</span>
                <select name="source" class="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                    <option value="">All sources</option>
                    <option value="direct" @selected(($filters['source'] ?? '') === 'direct')>Direct users</option>
                    <option value="suchak" @selected(($filters['source'] ?? '') === 'suchak')>Suchak-managed</option>
                </select>
            </label>
            <label>
                <span class="sr-only">Status</span>
                <select name="status" class="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                    <option value="">All statuses</option>
                    <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                    <option value="suspended" @selected(($filters['status'] ?? '') === 'suspended')>Suspended</option>
                    <option value="deleted" @selected(($filters['status'] ?? '') === 'deleted')>Deleted</option>
                </select>
            </label>
            <label>
                <span class="sr-only">Sort profiles</span>
                <select name="sort" class="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                    <option value="matrimony_id_desc" @selected(($filters['sort'] ?? '') === 'matrimony_id_desc')>Matrimony ID ↓</option>
                    <option value="matrimony_id_asc" @selected(($filters['sort'] ?? '') === 'matrimony_id_asc')>Matrimony ID ↑</option>
                    <option value="latest" @selected(($filters['sort'] ?? '') === 'latest')>Newest first</option>
                    <option value="oldest" @selected(($filters['sort'] ?? '') === 'oldest')>Oldest first</option>
                    <option value="name_asc" @selected(($filters['sort'] ?? '') === 'name_asc')>Name A-Z</option>
                    <option value="name_desc" @selected(($filters['sort'] ?? '') === 'name_desc')>Name Z-A</option>
                </select>
            </label>
            <label>
                <span class="sr-only">Rows per page</span>
                <select name="per_page" class="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                    @foreach ([15, 25, 50, 100] as $pageSize)
                        <option value="{{ $pageSize }}" @selected((int) ($filters['per_page'] ?? 15) === $pageSize)>{{ $pageSize }} / page</option>
                    @endforeach
                </select>
            </label>
            <div class="flex gap-2 sm:col-span-2 lg:col-span-6 xl:justify-end">
                <button type="submit" class="inline-flex items-center justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">Apply</button>
                <a href="{{ route('admin.profiles.index') }}" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">Reset</a>
            </div>
        </form>
    </div>

    @if ($profiles->isEmpty())
        <p class="text-gray-600 dark:text-gray-400">No profiles found.</p>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">IDs</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Name</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Source</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Gender</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Location</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Status</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Action</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ($profiles as $profile)
                        @php
                            $genderLabel = trim((string) ($profile->gender?->label ?? ''));
                            if ($genderLabel === '') {
                                $genderKey = trim((string) ($profile->gender?->key ?? ''));
                                $genderLabel = $genderKey !== '' ? ucfirst(str_replace('_', ' ', $genderKey)) : '—';
                            }

                            $profileUrl = route('admin.profiles.show', $profile->id);
                            $profileEditUrl = route('matrimony.profile.wizard.section', [
                                'section' => 'full',
                                'all' => 1,
                                'profile_id' => $profile->id,
                            ]);
                            $suchakRepresentation = $profile->suchakProfileRepresentations->first();
                            $suchakAccount = $suchakRepresentation?->suchakAccount;
                            $suchakName = trim((string) ($suchakAccount?->suchak_name ?? $suchakAccount?->office_name ?? ''));
                            $userName = trim((string) ($profile->user?->name ?? ''));
                        @endphp
                        <tr>
                            <td class="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">
                                <div class="space-y-1 whitespace-nowrap">
                                    <div><span class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Matrimony</span> #{{ $profile->id }}</div>
                                    <div><span class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">User</span> {{ $profile->user_id ? '#'.$profile->user_id : '—' }}</div>
                                    <div><span class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Suchak</span> {{ $suchakAccount ? '#'.$suchakAccount->id : '—' }}</div>
                                </div>
                            </td>
                            <td class="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">{{ $profile->full_name ?? '—' }}</td>
                            <td class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                @if ($suchakAccount)
                                    <div class="space-y-1">
                                        <span class="inline-flex items-center rounded-full bg-violet-50 px-2 py-0.5 text-xs font-semibold text-violet-700 ring-1 ring-violet-200 dark:bg-violet-900/30 dark:text-violet-200 dark:ring-violet-700">Suchak-managed</span>
                                        <div class="font-medium">{{ $suchakName !== '' ? $suchakName : 'Suchak #'.$suchakAccount->id }}</div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ str_replace('_', ' ', ucfirst($suchakRepresentation->representation_status ?? 'linked')) }}</div>
                                    </div>
                                @else
                                    <div class="space-y-1">
                                        <span class="inline-flex items-center rounded-full bg-sky-50 px-2 py-0.5 text-xs font-semibold text-sky-700 ring-1 ring-sky-200 dark:bg-sky-900/30 dark:text-sky-200 dark:ring-sky-700">Direct user</span>
                                        <div class="font-medium">{{ $userName !== '' ? $userName : ($profile->user_id ? 'User #'.$profile->user_id : 'No user linked') }}</div>
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">{{ $genderLabel }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">
                                @php
                                    $locText = trim(\App\Support\ProfileDisplayCopy::profileResidenceDisplayLine($profile));
                                    $locText = $locText !== '' ? $locText : '—';
                                @endphp
                                {{ $locText }}
                            </td>
                            <td class="px-4 py-2 text-sm">
                                @if ($profile->trashed())
                                    <span class="text-red-600 dark:text-red-400">Deleted</span>
                                @elseif ($profile->is_suspended ?? false)
                                    <span class="text-amber-600 dark:text-amber-400">Suspended</span>
                                @else
                                    <span class="text-emerald-600 dark:text-emerald-400">active</span>
                                @endif
                            </td>
                            <td class="px-4 py-2">
                                <div class="flex flex-col gap-1 text-sm">
                                    <a href="{{ $profileUrl }}" class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 font-medium">
                                        View Profile
                                    </a>
                                    <a href="{{ $profileEditUrl }}" class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 font-medium">
                                        Edit Profile
                                    </a>
                                </div>
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

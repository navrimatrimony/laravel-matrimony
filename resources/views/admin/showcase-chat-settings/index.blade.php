@extends('layouts.admin-showcase')

@section('showcase_content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">Showcase Chat Settings</h1>
    <p class="text-gray-500 dark:text-gray-400 text-sm mb-6">Configure orchestration for showcase profiles. User UI shows only “AI Assisted Replies” tag.</p>

    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-gray-600 dark:text-gray-300">
                    <th class="py-2 pr-4">Profile</th>
                    <th class="py-2 pr-4">Lifecycle</th>
                    <th class="py-2 pr-4">Enabled</th>
                    <th class="py-2 pr-4">AI Tag</th>
                    <th class="py-2 pr-4"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse ($showcases as $p)
                    @php
                        $s = $settingsByProfileId[$p->id] ?? null;
                    @endphp
                    <tr>
                        <td class="py-3 pr-4 font-semibold text-gray-900 dark:text-gray-100">
                            {{ $p->full_name ?: ('Showcase #' . $p->id) }}
                            <span class="ml-2 text-xs text-gray-400">#{{ $p->id }}</span>
                        </td>
                        <td class="py-3 pr-4 text-gray-600 dark:text-gray-300">{{ $p->lifecycle_state ?? '—' }}</td>
                        <td class="py-3 pr-4">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-bold {{ ($s?->enabled ?? false) ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-950/30 dark:text-emerald-200 dark:ring-emerald-800/50' : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' }}">
                                {{ ($s?->enabled ?? false) ? 'ON' : 'OFF' }}
                            </span>
                        </td>
                        <td class="py-3 pr-4 text-gray-600 dark:text-gray-300">
                            {{ ($s?->ai_assisted_replies_enabled ?? false) ? 'AI Assisted Replies' : '—' }}
                        </td>
                        <td class="py-3 pr-4 text-right">
                            <a href="{{ route('admin.showcase-chat-settings.show', ['profile' => $p->id]) }}" class="text-indigo-600 hover:text-indigo-700 font-semibold">Configure</a>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-6 text-center text-gray-500" colspan="5">No showcase profiles found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection


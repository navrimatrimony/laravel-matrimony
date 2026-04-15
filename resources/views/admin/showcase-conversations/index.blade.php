@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">Showcase Conversations</h1>
    <p class="text-gray-500 dark:text-gray-400 text-sm mb-6">Conversations involving enabled showcase profiles. Use this to monitor orchestration and take over safely.</p>

    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-gray-600 dark:text-gray-300">
                    <th class="py-2 pr-4">Conversation</th>
                    <th class="py-2 pr-4">Showcase</th>
                    <th class="py-2 pr-4">Other</th>
                    <th class="py-2 pr-4">Last activity</th>
                    <th class="py-2 pr-4">Automation</th>
                    <th class="py-2 pr-4"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse ($conversations as $c)
                    @php
                        $p1 = $profiles[$c->profile_one_id] ?? null;
                        $p2 = $profiles[$c->profile_two_id] ?? null;
                        $showcase = ($p1 && $p1->isShowcaseProfile()) ? $p1 : (($p2 && $p2->isShowcaseProfile()) ? $p2 : null);
                        $other = ($showcase && (int) $showcase->id === (int) ($p1?->id ?? 0)) ? $p2 : $p1;
                        $state = ($states[$c->id] ?? collect())->firstWhere('showcase_profile_id', $showcase?->id);
                    @endphp
                    <tr>
                        <td class="py-3 pr-4 text-gray-800 dark:text-gray-200">#{{ $c->id }}</td>
                        <td class="py-3 pr-4 font-semibold text-gray-900 dark:text-gray-100">{{ $showcase?->full_name ?: ('Showcase #' . ($showcase?->id ?? '')) }}</td>
                        <td class="py-3 pr-4 text-gray-700 dark:text-gray-200">{{ $other?->full_name ?: ('Profile #' . ($other?->id ?? '')) }}</td>
                        <td class="py-3 pr-4 text-gray-600 dark:text-gray-300">{{ $c->last_message_at?->diffForHumans() ?? '—' }}</td>
                        <td class="py-3 pr-4">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-bold bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                                {{ $state?->automation_status ?? '—' }}
                            </span>
                        </td>
                        <td class="py-3 pr-4 text-right">
                            <a href="{{ route('admin.showcase-conversations.show', ['conversation' => $c->id]) }}" class="text-indigo-600 hover:text-indigo-700 font-semibold">Open</a>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-6 text-center text-gray-500" colspan="6">No showcase conversations found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection


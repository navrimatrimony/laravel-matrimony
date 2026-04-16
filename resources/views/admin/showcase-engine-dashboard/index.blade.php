@extends('layouts.admin-showcase')

@section('showcase_content')
@php
    $profileLabel = function (?\App\Models\MatrimonyProfile $p): string {
        if (! $p) {
            return '—';
        }
        $name = trim((string) ($p->full_name ?? ''));
        $suffix = $p->is_showcase ? ' (showcase)' : '';

        return $name !== '' ? $name.$suffix : 'Profile #'.$p->id.$suffix;
    };
@endphp

<div class="space-y-6">
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-1">Showcase activity</h1>
        <p class="text-gray-500 dark:text-gray-400 text-sm mb-4">
            Recent showcase → real profile views (including random views & view-back), automated outgoing interests, and real → showcase interest flow.
            Artisan commands still log to the terminal; this page is the admin-visible trail with profile links.
        </p>
        <div class="flex flex-wrap gap-2 text-sm">
            <a href="{{ route('admin.view-back-settings.index') }}" class="inline-flex items-center rounded-lg bg-violet-50 dark:bg-violet-900/30 px-3 py-1.5 font-medium text-violet-800 dark:text-violet-200 ring-1 ring-violet-200 dark:ring-violet-700 hover:bg-violet-100 dark:hover:bg-violet-900/50">View-back &amp; random views</a>
            <a href="{{ route('admin.showcase-interest-settings.index') }}" class="inline-flex items-center rounded-lg bg-violet-50 dark:bg-violet-900/30 px-3 py-1.5 font-medium text-violet-800 dark:text-violet-200 ring-1 ring-violet-200 dark:ring-violet-700 hover:bg-violet-100 dark:hover:bg-violet-900/50">Interest automation</a>
            <a href="{{ route('admin.showcase-conversations.index') }}" class="inline-flex items-center rounded-lg bg-violet-50 dark:bg-violet-900/30 px-3 py-1.5 font-medium text-violet-800 dark:text-violet-200 ring-1 ring-violet-200 dark:ring-violet-700 hover:bg-violet-100 dark:hover:bg-violet-900/50">Conversations</a>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <div class="rounded-xl bg-white dark:bg-gray-800 shadow p-4 ring-1 ring-gray-200 dark:ring-gray-700">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Showcase → real views</p>
            <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-50">{{ $stats['views_24h'] }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Last 24h · {{ $stats['views_7d'] }} in last 7d</p>
        </div>
        <div class="rounded-xl bg-white dark:bg-gray-800 shadow p-4 ring-1 ring-gray-200 dark:ring-gray-700">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Outgoing interests</p>
            <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-50">{{ $stats['outgoing_24h'] }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Showcase sent (created) · {{ $stats['outgoing_7d'] }} in last 7d</p>
        </div>
        <div class="rounded-xl bg-white dark:bg-gray-800 shadow p-4 ring-1 ring-gray-200 dark:ring-gray-700">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Incoming (real → showcase)</p>
            <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-50">{{ $stats['incoming_24h'] }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Rows touched · {{ $stats['incoming_7d'] }} in last 7d</p>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden ring-1 ring-gray-200 dark:ring-gray-700">
        <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Recent activity (merged)</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Newest first — click a name to open the admin profile.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                        <th class="px-4 py-2 font-semibold">When</th>
                        <th class="px-4 py-2 font-semibold">Type</th>
                        <th class="px-4 py-2 font-semibold">Detail</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($timeline as $entry)
                        @php
                            $at = $entry['at'];
                            $kind = $entry['kind'];
                            $row = $entry['row'];
                        @endphp
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-900/40">
                            <td class="px-4 py-2 whitespace-nowrap text-gray-600 dark:text-gray-300">{{ $at->timezone(config('app.timezone'))->format('M j, H:i') }}</td>
                            <td class="px-4 py-2">
                                @if ($kind === 'view')
                                    <span class="inline-flex rounded-md bg-sky-100 dark:bg-sky-900/40 text-sky-800 dark:text-sky-200 px-2 py-0.5 text-xs font-semibold">View</span>
                                @elseif ($kind === 'outgoing_interest')
                                    <span class="inline-flex rounded-md bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-200 px-2 py-0.5 text-xs font-semibold">Outgoing interest</span>
                                @else
                                    <span class="inline-flex rounded-md bg-amber-100 dark:bg-amber-900/40 text-amber-900 dark:text-amber-100 px-2 py-0.5 text-xs font-semibold">Incoming interest</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-gray-800 dark:text-gray-200">
                                @if ($kind === 'view')
                                    @php $pv = $row; @endphp
                                    <a href="{{ route('admin.profiles.show', $pv->viewer_profile_id) }}" class="font-medium text-violet-700 dark:text-violet-300 hover:underline">{{ $profileLabel($pv->viewerProfile) }}</a>
                                    <span class="text-gray-500 dark:text-gray-400"> viewed </span>
                                    <a href="{{ route('admin.profiles.show', $pv->viewed_profile_id) }}" class="font-medium text-violet-700 dark:text-violet-300 hover:underline">{{ $profileLabel($pv->viewedProfile) }}</a>
                                @elseif ($kind === 'outgoing_interest')
                                    @php $i = $row; @endphp
                                    <a href="{{ route('admin.profiles.show', $i->sender_profile_id) }}" class="font-medium text-violet-700 dark:text-violet-300 hover:underline">{{ $profileLabel($i->senderProfile) }}</a>
                                    <span class="text-gray-500 dark:text-gray-400"> → </span>
                                    <a href="{{ route('admin.profiles.show', $i->receiver_profile_id) }}" class="font-medium text-violet-700 dark:text-violet-300 hover:underline">{{ $profileLabel($i->receiverProfile) }}</a>
                                    <span class="text-gray-500 dark:text-gray-400"> · </span>
                                    <span class="text-xs font-mono">{{ $i->status }}</span>
                                @else
                                    @php $i = $row; @endphp
                                    <a href="{{ route('admin.profiles.show', $i->sender_profile_id) }}" class="font-medium text-violet-700 dark:text-violet-300 hover:underline">{{ $profileLabel($i->senderProfile) }}</a>
                                    <span class="text-gray-500 dark:text-gray-400"> → </span>
                                    <a href="{{ route('admin.profiles.show', $i->receiver_profile_id) }}" class="font-medium text-violet-700 dark:text-violet-300 hover:underline">{{ $profileLabel($i->receiverProfile) }}</a>
                                    <span class="text-gray-500 dark:text-gray-400"> · </span>
                                    <span class="text-xs font-mono">{{ $i->status }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">No showcase activity recorded yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden ring-1 ring-gray-200 dark:ring-gray-700">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 font-semibold text-gray-900 dark:text-gray-100">Profile views (showcase → real)</div>
            <ul class="divide-y divide-gray-100 dark:divide-gray-700 max-h-96 overflow-y-auto text-sm">
                @forelse ($viewRows as $pv)
                    <li class="px-4 py-2.5 hover:bg-gray-50 dark:hover:bg-gray-900/40">
                        <a href="{{ route('admin.profiles.show', $pv->viewer_profile_id) }}" class="text-violet-700 dark:text-violet-300 font-medium hover:underline">{{ $profileLabel($pv->viewerProfile) }}</a>
                        <span class="text-gray-500 dark:text-gray-400"> → </span>
                        <a href="{{ route('admin.profiles.show', $pv->viewed_profile_id) }}" class="text-violet-700 dark:text-violet-300 font-medium hover:underline">{{ $profileLabel($pv->viewedProfile) }}</a>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ $pv->created_at->timezone(config('app.timezone'))->format('M j, H:i') }}</div>
                    </li>
                @empty
                    <li class="px-4 py-6 text-gray-500 dark:text-gray-400 text-center">None yet.</li>
                @endforelse
            </ul>
        </div>
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden ring-1 ring-gray-200 dark:ring-gray-700">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 font-semibold text-gray-900 dark:text-gray-100">Outgoing interests</div>
            <ul class="divide-y divide-gray-100 dark:divide-gray-700 max-h-96 overflow-y-auto text-sm">
                @forelse ($outgoing as $i)
                    <li class="px-4 py-2.5 hover:bg-gray-50 dark:hover:bg-gray-900/40">
                        <a href="{{ route('admin.profiles.show', $i->sender_profile_id) }}" class="text-violet-700 dark:text-violet-300 font-medium hover:underline">{{ $profileLabel($i->senderProfile) }}</a>
                        <span class="text-gray-500 dark:text-gray-400"> → </span>
                        <a href="{{ route('admin.profiles.show', $i->receiver_profile_id) }}" class="text-violet-700 dark:text-violet-300 font-medium hover:underline">{{ $profileLabel($i->receiverProfile) }}</a>
                        <span class="text-xs text-gray-500 dark:text-gray-400"> · {{ $i->status }}</span>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ $i->created_at->timezone(config('app.timezone'))->format('M j, H:i') }}</div>
                    </li>
                @empty
                    <li class="px-4 py-6 text-gray-500 dark:text-gray-400 text-center">None yet.</li>
                @endforelse
            </ul>
        </div>
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden ring-1 ring-gray-200 dark:ring-gray-700">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 font-semibold text-gray-900 dark:text-gray-100">Incoming (real → showcase)</div>
            <ul class="divide-y divide-gray-100 dark:divide-gray-700 max-h-96 overflow-y-auto text-sm">
                @forelse ($incoming as $i)
                    <li class="px-4 py-2.5 hover:bg-gray-50 dark:hover:bg-gray-900/40">
                        <a href="{{ route('admin.profiles.show', $i->sender_profile_id) }}" class="text-violet-700 dark:text-violet-300 font-medium hover:underline">{{ $profileLabel($i->senderProfile) }}</a>
                        <span class="text-gray-500 dark:text-gray-400"> → </span>
                        <a href="{{ route('admin.profiles.show', $i->receiver_profile_id) }}" class="text-violet-700 dark:text-violet-300 font-medium hover:underline">{{ $profileLabel($i->receiverProfile) }}</a>
                        <span class="text-xs text-gray-500 dark:text-gray-400"> · {{ $i->status }}</span>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Updated {{ $i->updated_at->timezone(config('app.timezone'))->format('M j, H:i') }}</div>
                    </li>
                @empty
                    <li class="px-4 py-6 text-gray-500 dark:text-gray-400 text-center">None yet.</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
@endsection

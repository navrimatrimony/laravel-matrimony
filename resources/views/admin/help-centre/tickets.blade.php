@extends('layouts.admin')

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Help centre tickets</h1>
            <p class="mt-1 text-sm text-gray-600">Queue, escalation status, and top intent analytics.</p>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
        <div class="rounded-xl border border-gray-200 bg-white p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Total</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ (int) ($stats['total'] ?? 0) }}</p>
        </div>
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">Open</p>
            <p class="mt-1 text-2xl font-bold text-amber-900">{{ (int) ($stats['open'] ?? 0) }}</p>
        </div>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Resolved</p>
            <p class="mt-1 text-2xl font-bold text-emerald-900">{{ (int) ($stats['resolved'] ?? 0) }}</p>
        </div>
        <div class="rounded-xl border border-sky-200 bg-sky-50 p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-sky-700">Auto-resolved</p>
            <p class="mt-1 text-2xl font-bold text-sky-900">{{ (int) ($stats['auto_resolved'] ?? 0) }}</p>
        </div>
        <div class="rounded-xl border border-violet-200 bg-violet-50 p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-violet-700">Escalated</p>
            <p class="mt-1 text-2xl font-bold text-violet-900">{{ (int) ($stats['escalated'] ?? 0) }}</p>
        </div>
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-3 md:col-span-1 col-span-2">
            <p class="text-xs font-semibold uppercase tracking-wide text-rose-700">SLA overdue</p>
            <p class="mt-1 text-2xl font-bold text-rose-900">{{ (int) ($overdueCount ?? 0) }}</p>
        </div>
    </div>

    <div class="rounded-xl border border-gray-200 bg-white p-4">
        <p class="text-sm font-semibold text-gray-900">Top intents</p>
        <div class="mt-3 flex flex-wrap gap-2">
            @forelse ($intentStats as $row)
                <span class="inline-flex items-center rounded-full border border-gray-200 bg-gray-50 px-3 py-1 text-xs font-semibold text-gray-700">
                    {{ str_replace('_', ' ', (string) $row->intent) }}: {{ (int) $row->aggregate }}
                </span>
            @empty
                <span class="text-sm text-gray-500">No intent data yet.</span>
            @endforelse
        </div>
    </div>

    <div class="rounded-xl border border-gray-200 bg-white">
        <div class="border-b border-gray-100 px-4 py-3">
            <form method="GET" class="flex flex-wrap items-end gap-3">
                <div>
                    <label for="status" class="block text-xs font-semibold uppercase tracking-wide text-gray-500">Status</label>
                    <select id="status" name="status" class="mt-1 rounded-lg border border-gray-300 px-3 py-2 text-sm">
                        @foreach (['open' => 'Open', 'resolved' => 'Resolved', 'auto_resolved' => 'Auto-resolved', 'all' => 'All'] as $key => $label)
                            <option value="{{ $key }}" @selected($statusFilter === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Apply</button>
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Ticket</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">User</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Intent</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Status</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Assigned</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">SLA</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Query</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">When</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    @forelse ($tickets as $ticket)
                        <tr>
                            <td class="px-4 py-3 font-semibold text-gray-900">{{ $ticket->ticket_code ?: '—' }}</td>
                            <td class="px-4 py-3 text-gray-700">
                                <div>{{ $ticket->user?->name ?: 'Unknown' }}</div>
                                <div class="text-xs text-gray-500">#{{ (int) $ticket->user_id }}</div>
                            </td>
                            <td class="px-4 py-3 text-gray-700">{{ str_replace('_', ' ', (string) $ticket->intent) }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $ticket->status === 'open' ? 'bg-amber-100 text-amber-900' : ($ticket->status === 'resolved' ? 'bg-emerald-100 text-emerald-900' : 'bg-sky-100 text-sky-900') }}">
                                    {{ $ticket->status }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-gray-700">
                                {{ $ticket->workflow?->assignedAdmin?->name ?: 'Unassigned' }}
                            </td>
                            <td class="px-4 py-3 text-xs">
                                @php($wf = $ticket->workflow)
                                @if ($wf && $wf->first_response_due_at && ! $wf->first_response_at && ! $wf->resolved_at && $wf->first_response_due_at->isPast())
                                    <span class="font-semibold text-rose-700">Overdue</span>
                                @elseif ($wf && $wf->first_response_due_at)
                                    <span class="text-gray-500">Due {{ $wf->first_response_due_at->diffForHumans() }}</span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="max-w-md px-4 py-3 text-gray-700">
                                <p class="line-clamp-2">{{ $ticket->query_text }}</p>
                                <p class="mt-1 line-clamp-2 text-xs text-gray-500">{{ $ticket->bot_reply }}</p>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-500">{{ $ticket->created_at?->diffForHumans() }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <a href="{{ route('admin.help-centre.tickets.show', $ticket) }}" class="rounded-md border border-indigo-200 px-3 py-1.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-50">Open</a>
                                    @if ($ticket->status !== 'resolved')
                                        <form method="POST" action="{{ route('admin.help-centre.tickets.resolve', $ticket) }}">
                                            @csrf
                                            <button type="submit" class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">Resolve</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-8 text-center text-sm text-gray-500">No tickets found for this filter.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-100 px-4 py-3">
            {{ $tickets->links() }}
        </div>
    </div>
</div>
@endsection

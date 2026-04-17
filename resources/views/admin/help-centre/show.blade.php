@extends('layouts.admin')

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('admin.help-centre.tickets.index') }}" class="text-sm font-semibold text-indigo-700 hover:underline">← Back to tickets</a>
            <h1 class="mt-1 text-2xl font-bold text-gray-900">Ticket {{ $ticket->ticket_code ?: '#'.$ticket->id }}</h1>
            <p class="mt-1 text-sm text-gray-600">User: {{ $ticket->user?->name ?: 'Unknown' }} (ID #{{ (int) $ticket->user_id }})</p>
        </div>
        <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold {{ $ticket->status === 'resolved' ? 'bg-emerald-100 text-emerald-900' : ($ticket->status === 'open' ? 'bg-amber-100 text-amber-900' : 'bg-sky-100 text-sky-900') }}">
            {{ $ticket->status }}
        </span>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-4 lg:col-span-2">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">User query</p>
            <p class="mt-2 text-sm text-gray-900">{{ $ticket->query_text }}</p>
            <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Bot reply</p>
            <p class="mt-2 text-sm text-gray-700">{{ $ticket->bot_reply }}</p>
            <p class="mt-4 text-xs text-gray-500">Created {{ $ticket->created_at?->toDayDateTimeString() }}</p>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4">
            @php($wf = $ticket->workflow)
            <p class="text-sm font-semibold text-gray-900">Assignment & SLA</p>
            <form method="POST" action="{{ route('admin.help-centre.tickets.assign', $ticket) }}" class="mt-3 space-y-3">
                @csrf
                <div>
                    <label for="assigned_admin_id" class="block text-xs font-semibold uppercase tracking-wide text-gray-500">Assigned admin</label>
                    <select id="assigned_admin_id" name="assigned_admin_id" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                        <option value="">Unassigned</option>
                        @foreach ($admins as $admin)
                            <option value="{{ $admin->id }}" @selected((int) ($wf?->assigned_admin_id ?? 0) === (int) $admin->id)>{{ $admin->name }} (#{{ $admin->id }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="priority" class="block text-xs font-semibold uppercase tracking-wide text-gray-500">Priority</label>
                    <select id="priority" name="priority" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                        @foreach (['low', 'normal', 'high', 'urgent'] as $priority)
                            <option value="{{ $priority }}" @selected(($wf?->priority ?? 'normal') === $priority)>{{ ucfirst($priority) }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Save assignment</button>
            </form>

            <div class="mt-4 space-y-1 text-xs text-gray-600">
                <p><strong>Due:</strong> {{ $wf?->first_response_due_at?->toDayDateTimeString() ?: '—' }}</p>
                <p><strong>First response:</strong> {{ $wf?->first_response_at?->toDayDateTimeString() ?: '—' }}</p>
                <p><strong>Resolved at:</strong> {{ $wf?->resolved_at?->toDayDateTimeString() ?: '—' }}</p>
            </div>

            @if ($ticket->status !== 'resolved')
                <form method="POST" action="{{ route('admin.help-centre.tickets.resolve', $ticket) }}" class="mt-4">
                    @csrf
                    <button type="submit" class="w-full rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Mark resolved</button>
                </form>
            @endif
        </div>
    </div>

    <div class="rounded-xl border border-gray-200 bg-white p-4">
        <p class="text-sm font-semibold text-gray-900">Internal notes</p>
        <form method="POST" action="{{ route('admin.help-centre.tickets.notes', $ticket) }}" class="mt-3">
            @csrf
            <label for="note" class="sr-only">Note</label>
            <textarea id="note" name="note" rows="3" maxlength="4000" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" placeholder="Add investigation note, follow-up, or user callback summary..." required></textarea>
            <button type="submit" class="mt-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-black">Add note</button>
        </form>

        <div class="mt-4 space-y-3">
            @forelse ($ticket->notes->sortByDesc('id') as $note)
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                    <p class="text-xs font-semibold text-gray-600">{{ $note->adminUser?->name ?: 'Admin' }} • {{ $note->created_at?->diffForHumans() }}</p>
                    <p class="mt-1 text-sm text-gray-800">{{ $note->note }}</p>
                </div>
            @empty
                <p class="text-sm text-gray-500">No internal notes yet.</p>
            @endforelse
        </div>
    </div>
</div>
@endsection

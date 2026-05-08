@extends('layouts.admin')

@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">
    <h1 class="text-xl font-semibold mb-4">Audit Center</h1>
    <div class="rounded border bg-white dark:bg-gray-900 dark:border-gray-700 overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-3 py-2 text-left">When</th>
                    <th class="px-3 py-2 text-left">User</th>
                    <th class="px-3 py-2 text-left">Action</th>
                    <th class="px-3 py-2 text-left">Recipe</th>
                    <th class="px-3 py-2 text-left">Result</th>
                    <th class="px-3 py-2 text-left">Approval</th>
                    <th class="px-3 py-2 text-left">Validation</th>
                    <th class="px-3 py-2 text-left">Rollback</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($rows as $row)
                <tr class="border-t dark:border-gray-700">
                    <td class="px-3 py-2">{{ $row->created_at?->format('Y-m-d H:i:s') }}</td>
                    <td class="px-3 py-2">{{ $row->user?->name ?? 'System' }}</td>
                    <td class="px-3 py-2">{{ $row->action }}</td>
                    <td class="px-3 py-2 font-mono">{{ $row->recipe ?? '—' }}</td>
                    <td class="px-3 py-2"><span class="inline-flex rounded-full border px-2 py-0.5 text-xs">{{ $row->status }}</span></td>
                    <td class="px-3 py-2">
                        @if ($row->status === 'pending_approval')
                            <span class="text-amber-700 text-xs">Pending reviewer</span>
                            @if (!empty($canApprove))
                                <form method="post" action="{{ route('admin.data-engine.governance-action.approve', $row->id) }}" class="inline">@csrf
                                    <button class="ml-2 rounded border px-2 py-0.5 text-xs">Approve</button>
                                </form>
                            @endif
                        @elseif ($row->approver)
                            <span class="text-xs">Approved by {{ $row->approver->name }}</span>
                        @else
                            <span class="text-xs text-gray-500">N/A</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-xs">
                        @php $v = is_array($row->validation_payload) ? $row->validation_payload : []; @endphp
                        @if ($v)
                            Pass: {{ !empty($v['validation_passed']) ? 'yes' : 'no' }}
                            @if (is_array($v['before_after_diff'] ?? null))
                                · Health Δ {{ (int) (($v['before_after_diff']['health_improvement'] ?? 0)) }}
                            @endif
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-3 py-2">{{ $row->rollback_available ? 'Available' : 'N/A' }}</td>
                </tr>
            @empty
                <tr><td class="px-3 py-4" colspan="8">No actions logged yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $rows->links() }}</div>
</div>
@endsection


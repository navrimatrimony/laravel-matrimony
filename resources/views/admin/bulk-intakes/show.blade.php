@extends('layouts.admin')

@section('content')
@php
    $activeAdminProfileTab = 'bulk';
    $parsePendingCount = $batch->items->filter(fn ($item) => $item->biodataIntake && $item->biodataIntake->parse_status === 'pending')->count();
    $parseQueuedCount = $batch->items->where('item_status', \App\Models\BulkIntakeBatchItem::STATUS_PARSE_QUEUED)->count();
    $parsedCount = $batch->items->filter(fn ($item) => $item->biodataIntake && $item->biodataIntake->parse_status === 'parsed')->count();
    $parseErrorCount = $batch->items->filter(fn ($item) => $item->biodataIntake && $item->biodataIntake->parse_status === 'error')->count();
@endphp
<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Bulk Intake #{{ $batch->id }}</h1>
            <p class="mt-1 text-sm text-gray-600">{{ $batch->batch_name ?: 'Untitled batch' }}</p>
        </div>
        <a href="{{ route('admin.bulk-intakes.index') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Back to bulk intakes</a>
    </div>

    @include('admin.intake._tabs')

    @if (session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800">{{ session('success') }}</div>
    @endif

    <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
        Bulk intake creates biodata_intakes only. It does not create or apply profiles.
    </div>

    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p>This queues parser from existing OCR/raw text only. Paid Sarvam/OpenAI vision extraction is not called.</p>
            <form method="POST" action="{{ route('admin.bulk-intakes.queue-free-parse', $batch) }}">
                @csrf
                <button type="submit" class="rounded-lg bg-amber-700 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-600">
                    Queue free parse for pending items
                </button>
            </form>
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-4">
        <div class="rounded-lg bg-white p-4 shadow">
            <p class="text-xs font-semibold uppercase text-gray-500">Status</p>
            <p class="mt-1 text-lg font-semibold text-gray-900">{{ $batch->batch_status }}</p>
        </div>
        <div class="rounded-lg bg-white p-4 shadow">
            <p class="text-xs font-semibold uppercase text-gray-500">Items</p>
            <p class="mt-1 text-lg font-semibold text-gray-900">{{ $batch->total_items }}</p>
        </div>
        <div class="rounded-lg bg-white p-4 shadow">
            <p class="text-xs font-semibold uppercase text-gray-500">Intakes Created</p>
            <p class="mt-1 text-lg font-semibold text-gray-900">{{ $batch->total_intakes_created }}</p>
        </div>
        <div class="rounded-lg bg-white p-4 shadow">
            <p class="text-xs font-semibold uppercase text-gray-500">Profiles Created</p>
            <p class="mt-1 text-lg font-semibold text-gray-900">{{ $batch->total_profiles_created }}</p>
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-4">
        <div class="rounded-lg bg-white p-4 shadow">
            <p class="text-xs font-semibold uppercase text-gray-500">Parse Pending</p>
            <p class="mt-1 text-lg font-semibold text-gray-900">{{ $parsePendingCount }}</p>
        </div>
        <div class="rounded-lg bg-white p-4 shadow">
            <p class="text-xs font-semibold uppercase text-gray-500">Parse Queued</p>
            <p class="mt-1 text-lg font-semibold text-gray-900">{{ $parseQueuedCount }}</p>
        </div>
        <div class="rounded-lg bg-white p-4 shadow">
            <p class="text-xs font-semibold uppercase text-gray-500">Parsed</p>
            <p class="mt-1 text-lg font-semibold text-gray-900">{{ $parsedCount }}</p>
        </div>
        <div class="rounded-lg bg-white p-4 shadow">
            <p class="text-xs font-semibold uppercase text-gray-500">Parse Errors</p>
            <p class="mt-1 text-lg font-semibold text-gray-900">{{ $parseErrorCount }}</p>
        </div>
    </div>

    <div class="rounded-lg bg-white p-6 shadow">
        <dl class="grid gap-4 text-sm md:grid-cols-3">
            <div>
                <dt class="font-semibold text-gray-700">Uploader</dt>
                <dd class="mt-1 text-gray-600">{{ $batch->uploaded_by_actor_type ?: '—' }} · {{ $batch->uploadedByUser?->name ?? '—' }}{{ $batch->uploadedByUser?->email ? ' · '.$batch->uploadedByUser->email : '' }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-700">Created</dt>
                <dd class="mt-1 text-gray-600">{{ $batch->created_at?->format('d-m-Y H:i') ?? '—' }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-700">Completed</dt>
                <dd class="mt-1 text-gray-600">{{ $batch->completed_at?->format('d-m-Y H:i') ?? '—' }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-700">Files</dt>
                <dd class="mt-1 text-gray-600">{{ $batch->total_files }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-700">Texts</dt>
                <dd class="mt-1 text-gray-600">{{ $batch->total_texts }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-700">Failed</dt>
                <dd class="mt-1 text-gray-600">{{ $batch->total_failed }}</dd>
            </div>
        </dl>
    </div>

    <div class="rounded-lg bg-white p-6 shadow">
        <h2 class="text-lg font-semibold text-gray-900">Items</h2>
        @if ($batch->items->isEmpty())
            <p class="mt-3 text-sm text-gray-600">No items found.</p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Seq</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Input</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Filename</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Status</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Failure</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Quality</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Intake</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Parse Error</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Source Contexts</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white">
                        @foreach ($batch->items as $item)
                            <tr>
                                <td class="px-4 py-2 text-sm text-gray-900">{{ $item->item_sequence }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700">{{ $item->input_type }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700">{{ $item->original_filename ?: '—' }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700">{{ $item->item_status }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700">
                                    @if ($item->failure_code)
                                        <span class="font-medium">{{ $item->failure_code }}</span>
                                        <span class="block max-w-xs truncate text-xs text-gray-500" title="{{ $item->failure_message }}">{{ $item->failure_message }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-sm text-gray-700">{{ $item->quality_score ?? '—' }}</td>
                                <td class="px-4 py-2 text-sm">
                                    @if ($item->biodataIntake)
                                        <a href="{{ route('admin.biodata-intakes.show', $item->biodataIntake) }}" class="font-medium text-indigo-600 hover:text-indigo-800">#{{ $item->biodataIntake->id }}</a>
                                        <span class="block text-xs text-gray-500">parse: {{ $item->biodataIntake->parse_status }}</span>
                                        @if ($item->biodataIntake->uploaded_by)
                                            <span class="block text-xs text-gray-500">owner: {{ $item->biodataIntake->uploadedByUser?->name ?? ('user #'.$item->biodataIntake->uploaded_by) }}</span>
                                        @else
                                            <span class="block text-xs text-amber-700">owner: Unclaimed / consent pending</span>
                                        @endif
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-sm text-gray-700">
                                    @if ($item->biodataIntake?->last_error)
                                        <span class="block max-w-xs truncate text-xs text-red-700" title="{{ $item->biodataIntake->last_error }}">{{ $item->biodataIntake->last_error }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-sm text-gray-700">{{ (int) ($sourceContextCountsByItem[$item->id] ?? 0) }}</td>
                                <td class="px-4 py-2 text-sm">
                                    @if ($item->biodataIntake && $item->biodataIntake->parse_status === 'pending' && $item->item_status !== \App\Models\BulkIntakeBatchItem::STATUS_PARSE_QUEUED && ! $item->biodataIntake->approved_by_user && ! $item->biodataIntake->intake_locked)
                                        <form method="POST" action="{{ route('admin.bulk-intakes.items.queue-free-parse', [$batch, $item]) }}">
                                            @csrf
                                            <button type="submit" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Queue</button>
                                        </form>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection

@extends('layouts.admin')

@section('content')
@php
    $activeAdminProfileTab = 'bulk';
    $reviewSummary = $reviewSummary ?? [
        'total' => 0,
        'unclaimed' => 0,
        'claimed' => 0,
        'intakes_created' => 0,
        'parse_pending' => 0,
        'parse_queued' => 0,
        'parsed' => 0,
        'parse_error' => 0,
        'needs_review' => 0,
        'failed' => 0,
    ];
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
    @if (session('error'))
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ session('error') }}</div>
    @endif

    <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
        This page is review visibility only. It does not create, approve, claim, or apply profiles.
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

    <div class="grid gap-4 md:grid-cols-3 xl:grid-cols-9">
        @foreach ([
            'Total Items' => $reviewSummary['total'],
            'Unclaimed' => $reviewSummary['unclaimed'],
            'Claimed' => $reviewSummary['claimed'],
            'Intakes Created' => $reviewSummary['intakes_created'],
            'Parse Pending' => $reviewSummary['parse_pending'],
            'Parse Queued' => $reviewSummary['parse_queued'],
            'Parsed' => $reviewSummary['parsed'],
            'Parse Errors' => $reviewSummary['parse_error'],
            'Needs Review' => $reviewSummary['needs_review'],
            'Failed' => $reviewSummary['failed'],
        ] as $label => $value)
            <div class="rounded-lg bg-white p-4 shadow">
                <p class="text-xs font-semibold uppercase text-gray-500">{{ $label }}</p>
                <p class="mt-1 text-lg font-semibold text-gray-900">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    <div class="rounded-lg bg-white p-6 shadow">
        <dl class="grid gap-4 text-sm md:grid-cols-3">
            <div>
                <dt class="font-semibold text-gray-700">Status</dt>
                <dd class="mt-1 text-gray-600">{{ $batch->batch_status }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-700">Uploader</dt>
                <dd class="mt-1 text-gray-600">{{ $batch->uploaded_by_actor_type ?: '-' }} · {{ $batch->uploadedByUser?->name ?? '-' }}{{ $batch->uploadedByUser?->email ? ' · '.$batch->uploadedByUser->email : '' }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-700">Created</dt>
                <dd class="mt-1 text-gray-600">{{ $batch->created_at?->format('d-m-Y H:i') ?? '-' }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-700">Completed</dt>
                <dd class="mt-1 text-gray-600">{{ $batch->completed_at?->format('d-m-Y H:i') ?? '-' }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-700">Files</dt>
                <dd class="mt-1 text-gray-600">{{ $batch->total_files }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-700">Texts</dt>
                <dd class="mt-1 text-gray-600">{{ $batch->total_texts }}</dd>
            </div>
        </dl>
    </div>

    <div class="rounded-lg bg-white p-6 shadow">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <h2 class="text-lg font-semibold text-gray-900">Items</h2>
            <div class="flex flex-wrap gap-2">
                @foreach ($statusFilters as $key => $label)
                    <a href="{{ route('admin.bulk-intakes.show', ['bulkIntakeBatch' => $batch, 'status' => $key]) }}"
                       class="rounded-full border px-3 py-1 text-xs font-semibold {{ $statusFilter === $key ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-indigo-300 hover:text-indigo-700' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </div>

        @if ($batch->items->isEmpty())
            <p class="mt-3 text-sm text-gray-600">No items found for this filter.</p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Seq</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Input</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Filename</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Item Status</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Owner</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Intake</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Parsed JSON</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Exceptions</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Source</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white">
                        @foreach ($batch->items as $item)
                            @php
                                $intake = $item->biodataIntake;
                                $hasParsedJson = $intake && ! empty($intake->parsed_json);
                                $exceptionBadges = [];
                                if (! $intake) {
                                    $exceptionBadges[] = ['label' => 'Missing linked intake', 'class' => 'border-red-200 bg-red-50 text-red-700'];
                                } elseif ($intake->uploaded_by === null) {
                                    $exceptionBadges[] = ['label' => 'Unclaimed / consent pending', 'class' => 'border-amber-200 bg-amber-50 text-amber-800'];
                                }
                                if ($intake && (string) $intake->parse_status === 'error') {
                                    $exceptionBadges[] = ['label' => 'Parse error', 'class' => 'border-red-200 bg-red-50 text-red-700'];
                                }
                                if ($intake && filled($intake->last_error)) {
                                    $exceptionBadges[] = ['label' => 'Intake last_error present', 'class' => 'border-red-200 bg-red-50 text-red-700'];
                                }
                                if ($intake && (string) $intake->parse_status === 'parsed' && ! $hasParsedJson) {
                                    $exceptionBadges[] = ['label' => 'Parsed JSON missing', 'class' => 'border-orange-200 bg-orange-50 text-orange-700'];
                                }
                            @endphp
                            <tr>
                                <td class="px-4 py-2 text-sm text-gray-900">{{ $item->item_sequence }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700">{{ $item->input_type }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700">{{ $item->original_filename ?: '-' }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700">
                                    <span class="font-medium">{{ $item->item_status }}</span>
                                    @if ($item->failure_code)
                                        <span class="block max-w-xs truncate text-xs text-red-700" title="{{ $item->failure_message }}">{{ $item->failure_code }}: {{ $item->failure_message }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-sm text-gray-700">
                                    @if ($intake && $intake->uploaded_by)
                                        <span class="font-medium">{{ $intake->uploadedByUser?->name ?? ('User #'.$intake->uploaded_by) }}</span>
                                        <span class="block text-xs text-gray-500">{{ $intake->uploadedByUser?->mobile ?: ($intake->uploadedByUser?->email ?: '-') }}</span>
                                    @elseif ($intake)
                                        <span class="font-medium text-amber-700">Unclaimed / consent pending</span>
                                    @else
                                        <span class="text-red-700">Missing linked intake</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-sm">
                                    @if ($intake)
                                        <a href="{{ route('admin.biodata-intakes.show', $intake) }}" class="font-medium text-indigo-600 hover:text-indigo-800">#{{ $intake->id }}</a>
                                        <span class="block text-xs text-gray-500">parse: {{ $intake->parse_status }}</span>
                                        @if ($intake->last_error)
                                            <span class="block max-w-xs truncate text-xs text-red-700" title="{{ $intake->last_error }}">{{ \Illuminate\Support\Str::limit((string) $intake->last_error, 90) }}</span>
                                        @endif
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-sm text-gray-700">{{ $hasParsedJson ? 'Yes' : 'No' }}</td>
                                <td class="px-4 py-2 text-sm">
                                    @if ($exceptionBadges === [])
                                        <span class="text-gray-400">-</span>
                                    @else
                                        <div class="flex max-w-xs flex-wrap gap-1">
                                            @foreach ($exceptionBadges as $badge)
                                                <span class="rounded-full border px-2 py-0.5 text-xs font-semibold {{ $badge['class'] }}">{{ $badge['label'] }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-sm text-gray-700">{{ (int) ($sourceContextCountsByItem[$item->id] ?? 0) }}</td>
                                <td class="px-4 py-2 text-sm">
                                    <div class="flex min-w-40 flex-col gap-2">
                                        @if ($intake)
                                            <a href="{{ route('admin.biodata-intakes.show', $intake) }}" class="font-medium text-indigo-600 hover:text-indigo-800">Open intake review</a>
                                            @if ($intake->uploaded_by === null)
                                                <a href="{{ route('admin.bulk-intakes.items.assign-owner', [$batch, $item]) }}" class="font-medium text-blue-700 hover:text-blue-900">Assign owner</a>
                                            @endif
                                        @endif

                                        @if ($intake && $intake->parse_status === 'pending' && $item->item_status !== \App\Models\BulkIntakeBatchItem::STATUS_PARSE_QUEUED && ! $intake->approved_by_user && ! $intake->intake_locked)
                                            <form method="POST" action="{{ route('admin.bulk-intakes.items.queue-free-parse', [$batch, $item]) }}">
                                                @csrf
                                                <button type="submit" class="text-left text-sm font-medium text-indigo-600 hover:text-indigo-800">Queue free parse item</button>
                                            </form>
                                        @endif

                                        @if ($item->item_status === \App\Models\BulkIntakeBatchItem::STATUS_NEEDS_REVIEW)
                                            <form method="POST" action="{{ route('admin.bulk-intakes.items.clear-needs-review', [$batch, $item]) }}">
                                                @csrf
                                                <button type="submit" class="text-left text-sm font-medium text-green-700 hover:text-green-900">Clear needs review</button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('admin.bulk-intakes.items.mark-needs-review', [$batch, $item]) }}">
                                                @csrf
                                                <button type="submit" class="text-left text-sm font-medium text-amber-700 hover:text-amber-900">Mark needs review</button>
                                            </form>
                                        @endif
                                    </div>
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

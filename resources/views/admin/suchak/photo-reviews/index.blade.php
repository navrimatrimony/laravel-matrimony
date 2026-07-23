@extends('layouts.admin')

@section('content')
@php
    $typeLabel = fn (string $value) => ucfirst(str_replace('_', ' ', $value));
    $isImage = function (?string $path): bool {
        if (! is_string($path) || $path === '') {
            return false;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
    };
    $queueLabels = [
        \App\Http\Controllers\Admin\Suchak\PhotoReviewController::QUEUE_NEEDS_REVIEW => 'Review करा',
        \App\Http\Controllers\Admin\Suchak\PhotoReviewController::QUEUE_AUTO_REJECTED => 'Auto-rejected',
        \App\Http\Controllers\Admin\Suchak\PhotoReviewController::QUEUE_AUTO_PASSED => 'Auto-passed',
        \App\Http\Controllers\Admin\Suchak\PhotoReviewController::QUEUE_HUMAN_REVIEWED => 'Admin reviewed',
    ];
    $aiLabel = function (?string $decision): string {
        return match ($decision) {
            \App\Models\SuchakVerificationRecord::MODERATION_SAFE => 'Safe',
            \App\Models\SuchakVerificationRecord::MODERATION_REVIEW => 'Needs review',
            \App\Models\SuchakVerificationRecord::MODERATION_REJECTED => 'Unsafe',
            \App\Models\SuchakVerificationRecord::MODERATION_ERROR => 'AI unavailable',
            default => 'Legacy (pre-AI)',
        };
    };
    $statusBadge = function (string $status): array {
        return match ($status) {
            \App\Models\SuchakVerificationRecord::STATUS_APPROVED => ['Approved', 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200'],
            \App\Models\SuchakVerificationRecord::STATUS_REJECTED => ['Rejected', 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200'],
            default => ['Pending', 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200'],
        };
    };
    $defaultApproveReason = 'Approved from Suchak photo review queue.';
    $defaultRejectReason = 'Rejected from Suchak photo review queue.';
    $afterActionQueue = \App\Http\Controllers\Admin\Suchak\PhotoReviewController::QUEUE_HUMAN_REVIEWED;
@endphp

<div
    class="space-y-6"
    x-data="{ previewOpen: false, previewUrl: '', previewTitle: '' }"
>
    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Suchak photo review</h1>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                    <strong>Review करा</strong> = decision बाकी.
                    <strong>Auto-passed / Auto-rejected</strong> = AI decision.
                    <strong>Admin reviewed</strong> = तुम्ही Approve/Reject केलेला इतिहास.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.suchak.dashboard') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-100 dark:hover:bg-gray-700">Dashboard</a>
                <a href="{{ route('admin.suchak.accounts.index', ['verification_status' => 'pending']) }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Pending accounts</a>
            </div>
        </div>
    </div>

    @if (session('success'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">{{ session('error') }}</div>
    @endif

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($queueLabels as $queueKey => $queueLabel)
            @php
                $count = (int) ($counts[$queueKey] ?? 0);
                $active = $queue === $queueKey;
            @endphp
            <a href="{{ route('admin.suchak.photo-reviews.index', array_filter(['queue' => $queueKey, 'verification_type' => $type])) }}"
               class="rounded-lg border p-4 shadow-sm transition {{ $active ? 'border-amber-400 bg-amber-50 dark:border-amber-500 dark:bg-amber-950/40' : 'border-gray-200 bg-white hover:border-indigo-300 dark:border-gray-700 dark:bg-gray-800 dark:hover:border-indigo-500' }}">
                <div class="text-xs font-semibold uppercase tracking-wide {{ $active ? 'text-amber-800 dark:text-amber-200' : 'text-gray-500 dark:text-gray-400' }}">{{ $queueLabel }}</div>
                <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($count) }}</div>
            </a>
        @endforeach
    </div>

    <form method="GET" action="{{ route('admin.suchak.photo-reviews.index') }}" class="flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <input type="hidden" name="queue" value="{{ $queue }}">
        <div>
            <label for="verification_type" class="block text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Type</label>
            <select id="verification_type" name="verification_type" class="mt-1 rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                <option value="">All photo types</option>
                @foreach ($photoTypes as $photoType)
                    <option value="{{ $photoType }}" @selected($type === $photoType)>{{ $typeLabel($photoType) }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-900">Filter</button>
    </form>

    <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900/40">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">Preview</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">Type</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">Suchak</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">Status</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">AI</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">File</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">Uploaded</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($records as $record)
                        @php
                            $account = $record->suchakAccount;
                            $docUrl = $account
                                ? route('admin.suchak.accounts.verification-records.document', [$account, $record])
                                : null;
                            $meta = $fileMetaById[$record->id] ?? [
                                'kb_label' => '—',
                                'dims_label' => '—',
                                'format_label' => '—',
                            ];
                            [$statusText, $statusClass] = $statusBadge((string) $record->admin_status);
                            $canApprove = $account && in_array($record->admin_status, [
                                \App\Models\SuchakVerificationRecord::STATUS_PENDING,
                                \App\Models\SuchakVerificationRecord::STATUS_REJECTED,
                            ], true);
                            $canReject = $account && $record->admin_status === \App\Models\SuchakVerificationRecord::STATUS_PENDING;
                        @endphp
                        <tr class="align-top">
                            <td class="px-3 py-3">
                                @if ($docUrl && $isImage($record->document_path))
                                    <button
                                        type="button"
                                        class="block h-28 w-20 overflow-hidden rounded-md border border-gray-200 bg-gray-100 dark:border-gray-600 dark:bg-gray-900"
                                        @click="previewOpen = true; previewUrl = @js($docUrl); previewTitle = @js(($account?->suchak_name ?: 'Photo').' · '.$typeLabel((string) $record->verification_type))"
                                    >
                                        <img src="{{ $docUrl }}" alt="Photo preview" class="h-full w-full object-cover">
                                    </button>
                                    <a href="{{ $docUrl }}" target="_blank" rel="noopener" class="mt-1 inline-block text-[11px] font-semibold text-indigo-600 hover:underline dark:text-indigo-300">Open full</a>
                                @elseif ($docUrl)
                                    <a href="{{ $docUrl }}" target="_blank" rel="noopener" class="text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-300">Open file</a>
                                @else
                                    <span class="text-xs text-gray-500">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-gray-800 dark:text-gray-200">{{ $typeLabel((string) $record->verification_type) }}</td>
                            <td class="px-3 py-3">
                                @if ($account)
                                    <a href="{{ route('admin.suchak.accounts.show', $account) }}" class="font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-300 dark:hover:text-indigo-200">
                                        {{ $account->suchak_name }}
                                    </a>
                                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        #{{ $account->id }}
                                        · {{ $account->mobile_number ?: ($account->user?->mobile ?: '—') }}
                                    </div>
                                @else
                                    <span class="text-gray-500">Missing account</span>
                                @endif
                            </td>
                            <td class="px-3 py-3">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold {{ $statusClass }}">{{ $statusText }}</span>
                            </td>
                            <td class="px-3 py-3">
                                <div class="font-medium text-gray-800 dark:text-gray-200">{{ $aiLabel($record->moderation_decision) }}</div>
                                @if ($record->remarks)
                                    <div class="mt-1 max-w-xs text-xs text-gray-500 dark:text-gray-400">{{ \Illuminate\Support\Str::limit($record->remarks, 100) }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-xs text-gray-700 dark:text-gray-300">
                                <div><span class="font-semibold">Size:</span> {{ $meta['kb_label'] }}</div>
                                <div class="mt-0.5"><span class="font-semibold">Format:</span> {{ $meta['format_label'] }}</div>
                                <div class="mt-0.5"><span class="font-semibold">Size px:</span> {{ $meta['dims_label'] }}</div>
                            </td>
                            <td class="px-3 py-3 text-gray-700 dark:text-gray-300">{{ $record->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="px-3 py-3">
                                @if ($canApprove || $canReject)
                                    <div class="flex flex-wrap gap-2">
                                        @if ($canApprove)
                                            <form method="POST" action="{{ route('admin.suchak.accounts.verification-records.approve', [$account, $record]) }}">
                                                @csrf
                                                <input type="hidden" name="return_to" value="photo_reviews">
                                                <input type="hidden" name="return_queue" value="{{ $afterActionQueue }}">
                                                <input type="hidden" name="reason" value="{{ $defaultApproveReason }}">
                                                <button type="submit" class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">
                                                    {{ $record->admin_status === \App\Models\SuchakVerificationRecord::STATUS_REJECTED ? 'Override approve' : 'Approve' }}
                                                </button>
                                            </form>
                                        @endif
                                        @if ($canReject)
                                            <form method="POST" action="{{ route('admin.suchak.accounts.verification-records.reject', [$account, $record]) }}">
                                                @csrf
                                                <input type="hidden" name="return_to" value="photo_reviews">
                                                <input type="hidden" name="return_queue" value="{{ $afterActionQueue }}">
                                                <input type="hidden" name="reason" value="{{ $defaultRejectReason }}">
                                                <button type="submit" class="rounded-md bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700">Reject</button>
                                            </form>
                                        @endif
                                    </div>
                                @else
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        @if ($record->adminUser?->email)
                                            By {{ $record->adminUser->email }}
                                        @elseif ($record->moderation_decision === \App\Models\SuchakVerificationRecord::MODERATION_SAFE)
                                            By AI (auto-passed)
                                        @elseif ($record->moderation_decision === \App\Models\SuchakVerificationRecord::MODERATION_REJECTED)
                                            By AI (auto-rejected)
                                        @else
                                            No further action
                                        @endif
                                        @if ($record->remarks)
                                            <div class="mt-1 max-w-xs">{{ \Illuminate\Support\Str::limit($record->remarks, 100) }}</div>
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">
                                No photos in this queue.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($records->hasPages())
            <div class="border-t border-gray-200 px-4 py-3 dark:border-gray-700">
                {{ $records->links() }}
            </div>
        @endif
    </div>

    <div
        x-show="previewOpen"
        x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4"
        @keydown.escape.window="previewOpen = false"
        @click.self="previewOpen = false"
    >
        <div class="max-h-[90vh] w-full max-w-3xl overflow-hidden rounded-lg bg-white shadow-xl dark:bg-gray-900">
            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                <div class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100" x-text="previewTitle"></div>
                <button type="button" class="rounded-md px-2 py-1 text-sm font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800" @click="previewOpen = false">Close</button>
            </div>
            <div class="flex max-h-[80vh] items-center justify-center bg-gray-950 p-3">
                <img :src="previewUrl" alt="Full preview" class="max-h-[75vh] max-w-full object-contain">
            </div>
        </div>
    </div>
</div>
@endsection

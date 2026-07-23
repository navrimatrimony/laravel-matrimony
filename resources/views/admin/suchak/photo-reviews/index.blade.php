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
    ];
    $defaultApproveReason = 'Approved from Suchak photo review queue.';
    $defaultRejectReason = 'Rejected from Suchak photo review queue.';
@endphp

<div class="space-y-6">
    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Suchak photo review</h1>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                    Safe photos auto-pass. Only uncertain or unsafe photos need your attention.
                    Account approval is separate — this queue is photos only.
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

    <div class="grid gap-3 sm:grid-cols-3">
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
                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">AI</th>
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
                            $canApprove = $account && in_array($record->admin_status, [
                                \App\Models\SuchakVerificationRecord::STATUS_PENDING,
                                \App\Models\SuchakVerificationRecord::STATUS_REJECTED,
                            ], true);
                            $canReject = $account && $record->admin_status === \App\Models\SuchakVerificationRecord::STATUS_PENDING;
                        @endphp
                        <tr class="align-top">
                            <td class="px-3 py-3">
                                @if ($docUrl && $isImage($record->document_path))
                                    <a href="{{ $docUrl }}" target="_blank" rel="noopener" class="block h-20 w-16 overflow-hidden rounded-md border border-gray-200 bg-gray-100 dark:border-gray-600 dark:bg-gray-900">
                                        <img src="{{ $docUrl }}" alt="Photo preview" class="h-full w-full object-cover">
                                    </a>
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
                                <div class="text-gray-800 dark:text-gray-200">{{ $record->moderation_decision ? ucfirst((string) $record->moderation_decision) : '—' }}</div>
                                @if ($record->remarks)
                                    <div class="mt-1 max-w-xs text-xs text-gray-500 dark:text-gray-400">{{ \Illuminate\Support\Str::limit($record->remarks, 80) }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-gray-700 dark:text-gray-300">{{ $record->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="px-3 py-3">
                                @if ($canApprove || $canReject)
                                    <div class="flex flex-wrap gap-2">
                                        @if ($canApprove)
                                            <form method="POST" action="{{ route('admin.suchak.accounts.verification-records.approve', [$account, $record]) }}">
                                                @csrf
                                                <input type="hidden" name="return_to" value="photo_reviews">
                                                <input type="hidden" name="return_queue" value="{{ $queue }}">
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
                                                <input type="hidden" name="return_queue" value="{{ $queue }}">
                                                <input type="hidden" name="reason" value="{{ $defaultRejectReason }}">
                                                <button type="submit" class="rounded-md bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700">Reject</button>
                                            </form>
                                        @endif
                                    </div>
                                @else
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ ucfirst((string) $record->admin_status) }}
                                        @if ($record->adminUser?->email)
                                            · {{ $record->adminUser->email }}
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">
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
</div>
@endsection

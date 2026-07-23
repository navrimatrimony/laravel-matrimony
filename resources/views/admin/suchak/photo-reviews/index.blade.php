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
        \App\Http\Controllers\Admin\Suchak\PhotoReviewController::QUEUE_NEEDS_REVIEW => 'Pending',
        \App\Http\Controllers\Admin\Suchak\PhotoReviewController::QUEUE_AUTO_REJECTED => 'Auto-rejected',
        \App\Http\Controllers\Admin\Suchak\PhotoReviewController::QUEUE_AUTO_PASSED => 'Auto-passed',
        \App\Http\Controllers\Admin\Suchak\PhotoReviewController::QUEUE_HUMAN_REVIEWED => 'Admin reviewed',
    ];
    $statusLabels = [
        \App\Models\SuchakVerificationRecord::STATUS_PENDING => 'Pending',
        \App\Models\SuchakVerificationRecord::STATUS_APPROVED => 'Approved',
        \App\Models\SuchakVerificationRecord::STATUS_REJECTED => 'Rejected',
        \App\Http\Controllers\Admin\Suchak\PhotoReviewController::STATUS_ALL => 'All',
    ];
    $aiLabel = function (?string $decision): string {
        return match ($decision) {
            \App\Models\SuchakVerificationRecord::MODERATION_SAFE => 'Safe',
            \App\Models\SuchakVerificationRecord::MODERATION_REVIEW => 'Uncertain',
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
    $visibleIds = $records->pluck('id')->values()->all();
@endphp

<script>
    window.suchakPhotoReview = function (visibleIds) {
        return {
            visibleIds: Array.isArray(visibleIds) ? visibleIds : [],
            previewOpen: false,
            previewUrl: '',
            previewTitle: '',
            reasonOpen: false,
            reasonText: '',
            reasonError: '',
            reasonTitle: '',
            reasonAction: '',
            selected: {},
            allVisibleSelected: false,
            openPreview(url, title) {
                this.previewUrl = url || '';
                this.previewTitle = title || '';
                this.previewOpen = true;
            },
            toggleVisible() {
                this.allVisibleSelected = !this.allVisibleSelected;
                this.visibleIds.forEach((id) => {
                    this.selected[id] = this.allVisibleSelected;
                });
            },
            selectedIds() {
                return Object.keys(this.selected)
                    .filter((id) => this.selected[id])
                    .map((id) => Number(id));
            },
            openReason(action, title) {
                this.reasonAction = action;
                this.reasonTitle = title || '';
                this.reasonText = '';
                this.reasonError = '';
                this.reasonOpen = true;
            },
            confirmReason() {
                const text = (this.reasonText || '').trim();
                if (text.length < 10) {
                    this.reasonError = 'Reason must be at least 10 characters.';
                    return;
                }

                if (this.reasonAction === 'bulk-approve' || this.reasonAction === 'bulk-reject') {
                    const ids = this.selectedIds();
                    if (ids.length === 0) {
                        this.reasonError = 'Select at least one photo.';
                        return;
                    }

                    const form = document.getElementById('bulk-photo-form');
                    if (!form) {
                        this.reasonError = 'Bulk form missing.';
                        return;
                    }

                    form.querySelector('[name="bulk_action"]').value =
                        this.reasonAction === 'bulk-approve' ? 'approve' : 'reject';
                    form.querySelector('[name="reason"]').value = text;
                    form.querySelectorAll('input[name="record_ids[]"]').forEach((el) => el.remove());
                    ids.forEach((id) => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'record_ids[]';
                        input.value = String(id);
                        form.appendChild(input);
                    });
                    this.reasonOpen = false;
                    form.submit();
                    return;
                }

                const form = document.getElementById(this.reasonAction);
                if (!form) {
                    this.reasonError = 'Action form missing.';
                    return;
                }
                form.querySelector('[name="reason"]').value = text;
                this.reasonOpen = false;
                form.submit();
            },
        };
    };
</script>

<div class="space-y-6" x-data="suchakPhotoReview(@js($visibleIds))">
    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Suchak photo review</h1>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                    Photo status is separate from Suchak account approval.
                    Use Status to filter the list. Use checkboxes only to act on selected photos.
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
                $cardStatus = $queueKey === \App\Http\Controllers\Admin\Suchak\PhotoReviewController::QUEUE_NEEDS_REVIEW
                    ? \App\Models\SuchakVerificationRecord::STATUS_PENDING
                    : \App\Http\Controllers\Admin\Suchak\PhotoReviewController::STATUS_ALL;
            @endphp
            <a href="{{ route('admin.suchak.photo-reviews.index', array_filter(['queue' => $queueKey, 'status' => $cardStatus, 'verification_type' => $type])) }}"
               class="rounded-lg border p-4 shadow-sm transition {{ $active ? 'border-amber-400 bg-amber-50 dark:border-amber-500 dark:bg-amber-950/40' : 'border-gray-200 bg-white hover:border-indigo-300 dark:border-gray-700 dark:bg-gray-800 dark:hover:border-indigo-500' }}">
                <div class="text-xs font-semibold uppercase tracking-wide {{ $active ? 'text-amber-800 dark:text-amber-200' : 'text-gray-500 dark:text-gray-400' }}">{{ $queueLabel }}</div>
                <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($count) }}</div>
            </a>
        @endforeach
    </div>

    <form method="GET" action="{{ route('admin.suchak.photo-reviews.index') }}" class="flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <input type="hidden" name="queue" value="{{ $queue }}">
        <div>
            <label for="status" class="block text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Status</label>
            <select id="status" name="status" class="mt-1 rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                @foreach ($statusLabels as $statusKey => $statusLabel)
                    <option value="{{ $statusKey }}" @selected($status === $statusKey)>{{ $statusLabel }}</option>
                @endforeach
            </select>
        </div>
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

    <div class="flex flex-wrap items-center gap-2">
        <button type="button" class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-100 dark:hover:bg-gray-700" @click="toggleVisible()">
            Select all
        </button>
        <button type="button" class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700" @click="openReason('bulk-approve', 'Approve selected photos')">
            Approve selected
        </button>
        <button type="button" class="rounded-md bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700" @click="openReason('bulk-reject', 'Reject selected photos')">
            Reject selected
        </button>
    </div>

    <form id="bulk-photo-form" method="POST" action="{{ route('admin.suchak.photo-reviews.bulk') }}" class="hidden">
        @csrf
        <input type="hidden" name="bulk_action" value="">
        <input type="hidden" name="reason" value="">
        <input type="hidden" name="return_status" value="{{ $status }}">
        <input type="hidden" name="return_queue" value="{{ $queue }}">
    </form>

    <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900/40">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">Select</th>
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
                            $formApproveId = 'photo-approve-'.$record->id;
                            $formRejectId = 'photo-reject-'.$record->id;
                            $previewTitle = ($account?->suchak_name ?: 'Photo').' · '.$typeLabel((string) $record->verification_type);
                            $decisionAt = $record->admin_status === \App\Models\SuchakVerificationRecord::STATUS_APPROVED
                                ? $record->verified_at
                                : ($record->admin_status === \App\Models\SuchakVerificationRecord::STATUS_REJECTED ? $record->rejected_at : null);
                            $canApprove = $account && in_array($record->admin_status, [
                                \App\Models\SuchakVerificationRecord::STATUS_PENDING,
                                \App\Models\SuchakVerificationRecord::STATUS_REJECTED,
                            ], true);
                            $canReject = $account && in_array($record->admin_status, [
                                \App\Models\SuchakVerificationRecord::STATUS_PENDING,
                                \App\Models\SuchakVerificationRecord::STATUS_APPROVED,
                            ], true);
                            $approveLabel = $record->admin_status === \App\Models\SuchakVerificationRecord::STATUS_REJECTED
                                ? 'पुन्हा Approve'
                                : 'Approve';
                            $rejectLabel = $record->admin_status === \App\Models\SuchakVerificationRecord::STATUS_APPROVED
                                ? 'पुन्हा Reject'
                                : 'Reject';
                        @endphp
                        <tr class="align-top">
                            <td class="px-3 py-3">
                                <input type="checkbox" class="rounded border-gray-300 dark:border-gray-600" :checked="!!selected[{{ $record->id }}]" @change="selected[{{ $record->id }}] = $event.target.checked">
                            </td>
                            <td class="px-3 py-3">
                                @if ($docUrl && $isImage($record->document_path))
                                    <button
                                        type="button"
                                        class="block h-28 w-20 overflow-hidden rounded-md border border-gray-200 bg-gray-100 dark:border-gray-600 dark:bg-gray-900"
                                        data-preview-url="{{ $docUrl }}"
                                        data-preview-title="{{ $previewTitle }}"
                                        @click="openPreview($event.currentTarget.dataset.previewUrl, $event.currentTarget.dataset.previewTitle)"
                                    >
                                        <img src="{{ $docUrl }}" alt="Photo preview" loading="lazy" decoding="async" class="h-full w-full object-cover">
                                    </button>
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
                                    @if ($account->verification_status === \App\Models\SuchakAccount::VERIFICATION_PENDING)
                                        <div class="mt-1 text-xs font-semibold text-amber-700 dark:text-amber-300">Account pending</div>
                                    @endif
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
                                <div class="flex flex-wrap gap-2">
                                    @if ($canApprove)
                                        <form id="{{ $formApproveId }}" method="POST" action="{{ route('admin.suchak.accounts.verification-records.approve', [$account, $record]) }}" class="hidden">
                                            @csrf
                                            <input type="hidden" name="return_to" value="photo_reviews">
                                            <input type="hidden" name="return_status" value="{{ $status }}">
                                            <input type="hidden" name="return_queue" value="{{ $queue }}">
                                            <input type="hidden" name="reason" value="">
                                        </form>
                                        <button
                                            type="button"
                                            class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700"
                                            data-form-id="{{ $formApproveId }}"
                                            data-reason-title="{{ $approveLabel }}"
                                            @click="openReason($event.currentTarget.dataset.formId, $event.currentTarget.dataset.reasonTitle)"
                                        >{{ $approveLabel }}</button>
                                    @endif
                                    @if ($canReject)
                                        <form id="{{ $formRejectId }}" method="POST" action="{{ route('admin.suchak.accounts.verification-records.reject', [$account, $record]) }}" class="hidden">
                                            @csrf
                                            <input type="hidden" name="return_to" value="photo_reviews">
                                            <input type="hidden" name="return_status" value="{{ $status }}">
                                            <input type="hidden" name="return_queue" value="{{ $queue }}">
                                            <input type="hidden" name="reason" value="">
                                        </form>
                                        <button
                                            type="button"
                                            class="rounded-md bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700"
                                            data-form-id="{{ $formRejectId }}"
                                            data-reason-title="{{ $rejectLabel }}"
                                            @click="openReason($event.currentTarget.dataset.formId, $event.currentTarget.dataset.reasonTitle)"
                                        >{{ $rejectLabel }}</button>
                                    @endif
                                </div>
                                @if ($record->adminUser?->email || $decisionAt || in_array($record->moderation_decision, [\App\Models\SuchakVerificationRecord::MODERATION_SAFE, \App\Models\SuchakVerificationRecord::MODERATION_REJECTED], true))
                                    <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                        @if ($record->adminUser?->email)
                                            By {{ $record->adminUser->email }}
                                        @elseif ($record->moderation_decision === \App\Models\SuchakVerificationRecord::MODERATION_SAFE && ! $record->admin_user_id)
                                            By AI (auto-passed)
                                        @elseif ($record->moderation_decision === \App\Models\SuchakVerificationRecord::MODERATION_REJECTED && ! $record->admin_user_id)
                                            By AI (auto-rejected)
                                        @endif
                                        @if ($decisionAt)
                                            <div>{{ $decisionAt->format('Y-m-d H:i') }}</div>
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">
                                No photos match this filter.
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

    <div
        x-show="reasonOpen"
        x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
        @keydown.escape.window="reasonOpen = false"
        @click.self="reasonOpen = false"
    >
        <div class="w-full max-w-md rounded-lg bg-white p-5 shadow-xl dark:bg-gray-900">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100" x-text="reasonTitle"></h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Write a short reason (min 10 characters).</p>
            <textarea
                x-model="reasonText"
                rows="4"
                maxlength="500"
                class="mt-3 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100"
                placeholder="Example: Clear face photo, good quality."
            ></textarea>
            <p class="mt-1 text-xs text-red-600 dark:text-red-300" x-show="reasonError" x-text="reasonError"></p>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-100 dark:hover:bg-gray-800" @click="reasonOpen = false">Cancel</button>
                <button type="button" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-700" @click="confirmReason()">Confirm</button>
            </div>
        </div>
    </div>
</div>
@endsection

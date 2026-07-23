@extends('layouts.admin')

@php
    use App\Models\SuchakAccount;
    use App\Http\Controllers\Admin\Suchak\AccountVerificationController as AccountsController;

    /**
     * ONE badge carrying the account's real state — no second line repeating it.
     *
     * A pending account's useful state is not "pending" (the filter already says
     * that); it is whether it is actually reviewable. So the badge itself says
     * "Ready to review" or "Incomplete · <step>". public_status is only called
     * out when it genuinely diverges from verified.
     */
    $statusBadge = function (SuchakAccount $account): array {
        $amber = 'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300';
        $grey = 'bg-gray-100 text-gray-600 ring-gray-500/20 dark:bg-gray-700 dark:text-gray-300';

        if ($account->verification_status === SuchakAccount::VERIFICATION_PENDING) {
            if ($account->registration_completed_at !== null) {
                return ['Ready to review', $amber];
            }
            $step = trim((string) $account->onboarding_step);

            return [$step !== '' ? 'Incomplete · '.$step : 'Signup incomplete', $grey];
        }

        return match ($account->verification_status) {
            SuchakAccount::VERIFICATION_VERIFIED => ['Verified', 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300'],
            SuchakAccount::VERIFICATION_REJECTED => ['Rejected', 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-300'],
            SuchakAccount::VERIFICATION_SUSPENDED => ['Suspended', 'bg-orange-50 text-orange-700 ring-orange-600/20 dark:bg-orange-500/10 dark:text-orange-300'],
            SuchakAccount::VERIFICATION_ARCHIVED => ['Archived', 'bg-gray-100 text-gray-600 ring-gray-500/20 dark:bg-gray-700 dark:text-gray-300'],
            default => ['Pending', $amber],
        };
    };

    // Keeps every active filter, just swaps the sort and resets paging.
    $sortLink = fn (string $key): string => request()->fullUrlWithQuery(['sort' => $key, 'page' => null]);
@endphp

@section('content')
<div class="space-y-6" x-data="{ selected: [], reason: '' }">
    {{-- Workload first: what is actually reviewable vs what is a half-finished signup. --}}
    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Suchak Accounts</h1>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Review queue — oldest reviewable request first.</p>
                {{-- Opens a preview of exactly what would be deleted; nothing is
                     removed from here. --}}
                <a href="{{ route('admin.suchak.accounts.cleanup') }}"
                   class="mt-2 inline-block text-sm font-medium text-rose-600 underline hover:text-rose-800 dark:text-rose-300">
                    Clean up abandoned signups…
                </a>
            </div>

            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.suchak.accounts.index', ['verification_status' => SuchakAccount::VERIFICATION_PENDING, 'readiness' => AccountsController::READINESS_READY]) }}"
                   class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-center dark:border-amber-500/40 dark:bg-amber-500/10">
                    <div class="text-lg font-bold text-amber-800 dark:text-amber-300">{{ $queueCounts['ready'] }}</div>
                    <div class="text-xs font-medium text-amber-700 dark:text-amber-300">Ready to review</div>
                </a>
                <a href="{{ route('admin.suchak.accounts.index', ['verification_status' => SuchakAccount::VERIFICATION_PENDING, 'readiness' => AccountsController::READINESS_INCOMPLETE]) }}"
                   class="rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-center dark:border-gray-600 dark:bg-gray-900">
                    <div class="text-lg font-bold text-gray-700 dark:text-gray-200">{{ $queueCounts['incomplete'] }}</div>
                    <div class="text-xs font-medium text-gray-600 dark:text-gray-400">In progress</div>
                </a>
                <a href="{{ route('admin.suchak.accounts.index', ['verification_status' => SuchakAccount::VERIFICATION_PENDING, 'readiness' => AccountsController::READINESS_STALLED]) }}"
                   class="rounded-lg border border-slate-300 bg-slate-100 px-3 py-2 text-center dark:border-slate-600 dark:bg-slate-800">
                    <div class="text-lg font-bold text-slate-700 dark:text-slate-200">{{ $queueCounts['stalled'] }}</div>
                    <div class="text-xs font-medium text-slate-600 dark:text-slate-400">Stalled {{ $stalledAfterDays }}d+</div>
                </a>
                <a href="{{ route('admin.suchak.accounts.index', ['verification_status' => SuchakAccount::VERIFICATION_VERIFIED]) }}"
                   class="rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-2 text-center dark:border-emerald-500/40 dark:bg-emerald-500/10">
                    <div class="text-lg font-bold text-emerald-800 dark:text-emerald-300">{{ $queueCounts['verified'] }}</div>
                    <div class="text-xs font-medium text-emerald-700 dark:text-emerald-300">Verified</div>
                </a>
            </div>
        </div>

        <form method="GET" action="{{ route('admin.suchak.accounts.index') }}" class="mt-5 flex flex-wrap items-end gap-3">
            <div class="min-w-[240px] flex-1">
                <label for="q" class="block text-xs font-medium text-gray-700 dark:text-gray-300">Search</label>
                <input id="q" name="q" value="{{ $search }}" placeholder="Name, office or mobile"
                       class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            </div>
            <div>
                <label for="verification_status" class="block text-xs font-medium text-gray-700 dark:text-gray-300">Status</label>
                <select id="verification_status" name="verification_status" class="mt-1 rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">All</option>
                    @foreach ($allowedStatuses as $allowedStatus)
                        <option value="{{ $allowedStatus }}" @selected($status === $allowedStatus)>{{ ucfirst($allowedStatus) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="readiness" class="block text-xs font-medium text-gray-700 dark:text-gray-300">Readiness</label>
                <select id="readiness" name="readiness" class="mt-1 rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">Any</option>
                    <option value="{{ AccountsController::READINESS_READY }}" @selected($readiness === AccountsController::READINESS_READY)>Signup complete</option>
                    <option value="{{ AccountsController::READINESS_INCOMPLETE }}" @selected($readiness === AccountsController::READINESS_INCOMPLETE)>In progress (&lt;{{ $stalledAfterDays }}d)</option>
                    <option value="{{ AccountsController::READINESS_STALLED }}" @selected($readiness === AccountsController::READINESS_STALLED)>Stalled ({{ $stalledAfterDays }}d+)</option>
                </select>
            </div>
            <div>
                <label for="business_type" class="block text-xs font-medium text-gray-700 dark:text-gray-300">Type</label>
                <select id="business_type" name="business_type" class="mt-1 rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">All</option>
                    @foreach ($businessTypes as $type)
                        <option value="{{ $type }}" @selected($businessType === $type)>{{ ucfirst($type) }}</option>
                    @endforeach
                </select>
            </div>
            <input type="hidden" name="sort" value="{{ $sort }}">
            <button type="submit" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">Filter</button>
            @if ($search || $status || $readiness || $businessType)
                <a href="{{ route('admin.suchak.accounts.index') }}" class="text-sm font-medium text-gray-600 underline dark:text-gray-300">Clear</a>
            @endif
        </form>
    </div>

    @if (session('success'))
        <div class="rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-500/40 dark:bg-emerald-500/10 dark:text-emerald-300">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="rounded-lg border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-500/40 dark:bg-rose-500/10 dark:text-rose-300">{{ session('error') }}</div>
    @endif

    <form method="POST" action="{{ route('admin.suchak.accounts.bulk') }}">
        @csrf
        @foreach (['verification_status' => $status, 'business_type' => $businessType, 'readiness' => $readiness, 'sort' => $sort, 'q' => $search] as $key => $value)
            @if ($value)
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endif
        @endforeach

        {{-- Bulk bar only appears once something is selected, and always demands a reason. --}}
        <div x-show="selected.length > 0" x-cloak
             class="mb-3 flex flex-wrap items-center gap-3 rounded-lg border border-indigo-300 bg-indigo-50 px-4 py-3 dark:border-indigo-500/40 dark:bg-indigo-500/10">
            <span class="text-sm font-semibold text-indigo-900 dark:text-indigo-200" x-text="selected.length + ' selected'"></span>
            <input type="text" name="reason" x-model="reason" minlength="10" maxlength="500"
                   placeholder="Reason (min 10 characters) — recorded in the activity log"
                   class="min-w-[280px] flex-1 rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            {{-- No bulk approve by design (PO 2026-07-23): approving grants
                 access to real member data and stays a per-account decision on
                 the review screen. Bulk exists to clear junk, not to grant. --}}
            <button type="submit" name="bulk_action" value="reject"
                    :disabled="reason.trim().length < 10"
                    @click="return confirm('Reject ' + selected.length + ' Suchak account(s)?')"
                    class="rounded-md bg-rose-600 px-3 py-2 text-sm font-semibold text-white hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-50">Reject selected</button>
            <button type="button" @click="selected = []" class="text-sm font-medium text-indigo-800 underline dark:text-indigo-200">Clear</button>
            <span class="w-full text-xs text-indigo-800/80 dark:text-indigo-200/80">Approval stays per-account — open Review to approve.</span>
        </div>

        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900">
                    <tr>
                        <th class="px-3 py-3 text-left">
                            <input type="checkbox" aria-label="Select all on this page"
                                   @change="selected = $event.target.checked ? {{ $accounts->pluck('id')->toJson() }} : []"
                                   :checked="selected.length > 0 && selected.length === {{ $accounts->count() }}"
                                   class="rounded border-gray-300 dark:border-gray-600">
                        </th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">
                            <a href="{{ $sortLink(AccountsController::SORT_NAME) }}" class="hover:underline">Suchak{{ $sort === AccountsController::SORT_NAME ? ' ▾' : '' }}</a>
                        </th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Location</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Type</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Profiles</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Status</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">
                            <a href="{{ $sortLink(AccountsController::SORT_WAITING) }}" class="hover:underline">Waiting{{ in_array($sort, [AccountsController::SORT_WAITING, AccountsController::SORT_SMART], true) ? ' ▾' : '' }}</a>
                        </th>
                        <th class="px-4 py-3 text-right font-semibold text-gray-700 dark:text-gray-200">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($accounts as $account)
                        @php
                            [$badgeLabel, $badgeClass] = $statusBadge($account);
                            $isPending = $account->verification_status === SuchakAccount::VERIFICATION_PENDING;
                            $signupDone = $account->registration_completed_at !== null;
                            // Whole calendar days. Carbon 3's diffInDays() returns a
                            // float, which rendered as "42.047526034479d" — compare
                            // day boundaries and cast so the queue reads cleanly.
                            $waitingDays = $account->created_at
                                ? (int) $account->created_at->copy()->startOfDay()->diffInDays(now()->startOfDay())
                                : 0;
                            $photoUrl = $account->profile_photo_path
                                ? \Illuminate\Support\Facades\Storage::disk('public')->url($account->profile_photo_path)
                                : null;
                            $isOrg = $account->business_type !== SuchakAccount::BUSINESS_TYPE_INDIVIDUAL;
                            $place = $account->cityLocation?->name ?? $account->districtLocation?->name;
                            $duplicate = $duplicateKeys[$account->id] ?? null;
                            $lastAction = $lastActions[$account->id] ?? null;
                            $docs = $documentStatus[$account->id] ?? null;
                        @endphp
                        <tr class="{{ $isPending && $signupDone ? 'bg-amber-50/40 dark:bg-amber-500/5' : '' }}">
                            <td class="px-3 py-3 align-top">
                                <input type="checkbox" name="account_ids[]" value="{{ $account->id }}" x-model.number="selected"
                                       aria-label="Select {{ $account->suchak_name }}"
                                       class="rounded border-gray-300 dark:border-gray-600">
                            </td>

                            {{-- Identity: photo + name + the mobile they actually registered with. --}}
                            <td class="px-4 py-3">
                                <div class="flex items-start gap-3">
                                    @if ($photoUrl)
                                        <img src="{{ $photoUrl }}" alt="" class="h-9 w-9 shrink-0 rounded-full object-cover ring-1 ring-gray-200 dark:ring-gray-600">
                                    @else
                                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-bold text-gray-500 dark:bg-gray-700 dark:text-gray-300">
                                            {{ Str::upper(Str::substr(trim((string) $account->suchak_name), 0, 1)) ?: '?' }}
                                        </div>
                                    @endif
                                    <div class="min-w-0">
                                        <div class="font-medium text-gray-900 dark:text-gray-100">
                                            {{ $account->suchak_name }}
                                            @if ($account->suchak_name_mr && $account->suchak_name_mr !== $account->suchak_name)
                                                <span class="text-gray-500 dark:text-gray-400">· {{ $account->suchak_name_mr }}</span>
                                            @endif
                                        </div>
                                        @if ($isOrg && ($account->office_name || $account->office_name_mr))
                                            <div class="truncate text-xs text-gray-600 dark:text-gray-300">{{ $account->office_name ?: $account->office_name_mr }}</div>
                                        @endif
                                        <div class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ $account->mobile_number ?: ($account->email ?: $account->user?->email ?: 'No contact on file') }}
                                        </div>
                                        @if ($duplicate)
                                            @if (!empty($duplicate['twin']))
                                                <a href="{{ route('admin.suchak.accounts.show', $duplicate['twin']) }}"
                                                   class="mt-1 inline-flex items-center rounded bg-yellow-100 px-1.5 py-0.5 text-[11px] font-medium text-yellow-800 hover:bg-yellow-200 dark:bg-yellow-500/15 dark:text-yellow-300">
                                                    ⚠ {{ $duplicate['label'] }} as #{{ $duplicate['twin'] }}
                                                </a>
                                            @else
                                                <span class="mt-1 inline-flex items-center rounded bg-yellow-100 px-1.5 py-0.5 text-[11px] font-medium text-yellow-800 dark:bg-yellow-500/15 dark:text-yellow-300">
                                                    ⚠ {{ $duplicate['label'] }}
                                                </span>
                                            @endif
                                        @endif
                                    </div>
                                </div>
                            </td>

                            <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                                {{ $place ?: '—' }}
                            </td>

                            <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ ucfirst($account->business_type) }}</td>

                            {{-- Work actually done. A Suchak with profiles is never junk. --}}
                            <td class="px-4 py-3">
                                @if ($account->profile_representations_count > 0)
                                    <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $account->profile_representations_count }}</span>
                                @else
                                    <span class="text-gray-400 dark:text-gray-500">0</span>
                                @endif
                            </td>

                            {{-- Exactly one signal. The badge already carries the
                                 pending sub-state, so no second line repeats it;
                                 public state appears only when it disagrees. --}}
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold ring-1 ring-inset {{ $badgeClass }}">{{ $badgeLabel }}</span>
                                @if ($account->verification_status === SuchakAccount::VERIFICATION_VERIFIED && $account->public_status !== SuchakAccount::PUBLIC_ACTIVE)
                                    <div class="mt-1 text-xs font-medium text-orange-700 dark:text-orange-300">Not public ({{ $account->public_status }})</div>
                                @endif
                                {{-- Approving the account and approving its KYC documents are
                                     separate; this stops "Verified" being read as "documents
                                     checked too". --}}
                                @if ($docs)
                                    <div class="mt-1 text-xs {{ $docs['approved'] >= $docs['required'] ? 'text-emerald-700 dark:text-emerald-300' : 'font-medium text-amber-700 dark:text-amber-300' }}">
                                        Docs {{ $docs['approved'] }}/{{ $docs['required'] }}{{ $docs['approved'] >= $docs['required'] ? ' ✓' : '' }}
                                    </div>
                                @endif
                            </td>

                            {{-- Age of the request, not a raw timestamp. --}}
                            <td class="px-4 py-3">
                                <div class="{{ $isPending && $signupDone && $waitingDays >= 3 ? 'font-semibold text-rose-700 dark:text-rose-300' : 'text-gray-700 dark:text-gray-300' }}">
                                    {{ $waitingDays === 0 ? 'Today' : $waitingDays.'d' }}
                                </div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $account->created_at?->format('d M Y') }}</div>
                            </td>

                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('admin.suchak.accounts.show', $account) }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-300 dark:hover:text-indigo-200">Review</a>
                                @if ($lastAction)
                                    {{-- So two admins do not work the same row. --}}
                                    <div class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">
                                        {{ $lastAction['actor'] }}{{ $lastAction['at'] ? ' · '.$lastAction['at']->diffForHumans(short: true) : '' }}
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">No Suchak accounts match these filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 px-4 py-3 dark:border-gray-700">
                <div class="text-xs text-gray-600 dark:text-gray-400">
                    @if ($accounts->total() > 0)
                        Showing {{ $accounts->firstItem() }}–{{ $accounts->lastItem() }} of {{ $accounts->total() }}
                    @else
                        No results
                    @endif
                </div>
                {{ $accounts->links() }}
            </div>
        </div>
    </form>
</div>
@endsection

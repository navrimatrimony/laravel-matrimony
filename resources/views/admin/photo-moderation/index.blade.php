@extends('layouts.admin', ['adminContentWrapperClass' => 'w-full max-w-none mx-auto px-3 sm:px-4 lg:px-6'])

@section('content')
@php
    use Illuminate\Support\Arr;
    $pmQ = request()->except('page');
    $pmRoute = fn (array $query) => route('admin.photo-moderation.index', $query);
    $pmFilterFields = ['include_approved', 'flagged_users', 'new_only', 'old_only', 'eff_status', 'date_preset', 'date_from', 'date_to', 'per_page'];
@endphp

<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4 sm:p-6">
    <div class="mb-4">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Photo moderation engine</h1>
		
		<div class="mt-2 text-sm font-semibold">
    AI Status: <span id="aiStatusText" class="text-gray-500">Checking...</span>
</div>
		
        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
            Effective visibility prefers <code class="text-xs bg-gray-100 dark:bg-gray-900 px-1 rounded">admin_override_*</code> when set.
            <a href="{{ route('admin.moderation-engine-settings.index') }}" class="text-indigo-600 dark:text-indigo-400 underline">Threshold settings</a>
            · <a href="{{ route('admin.moderation-learning.index') }}" class="text-indigo-600 dark:text-indigo-400 underline">Learning analytics</a>
        </p>
    </div>

    @if (! empty($moderationListRiskMessage))
        <div class="mb-4 rounded-lg border border-amber-400 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-600 dark:bg-amber-950/40 dark:text-amber-100" role="status">
            {{ $moderationListRiskMessage }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-100">
            {{ $errors->first() }}
        </div>
    @endif

    @if (session('success'))
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900 dark:border-green-800 dark:bg-green-950/40 dark:text-green-100">{{ session('success') }}</div>
    @endif
    @if (session('warning'))
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">{{ session('warning') }}</div>
    @endif

    <div class="sticky top-0 z-40 -mx-4 sm:-mx-6 px-4 sm:px-6 py-3 mb-4 space-y-3 rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50/95 dark:bg-gray-900/95 backdrop-blur shadow-sm">
        <form method="get" action="{{ route('admin.photo-moderation.index') }}" id="pm-filter-form" class="space-y-3">
            @foreach (Arr::except($pmQ, $pmFilterFields) as $hiddenKey => $hiddenVal)
                @if (is_string($hiddenVal) || is_numeric($hiddenVal))
                    <input type="hidden" name="{{ $hiddenKey }}" value="{{ $hiddenVal }}">
                @endif
            @endforeach
            <div class="flex flex-wrap items-end gap-x-4 gap-y-2">
                <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 cursor-pointer">
                    <input type="checkbox" name="include_approved" value="1" class="h-5 w-5 rounded border-gray-400 text-indigo-600" @checked($includeApproved) onchange="this.form.submit()">
                    Include approved
                </label>
                <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 cursor-pointer">
                    <input type="checkbox" name="flagged_users" value="1" class="h-5 w-5 rounded border-gray-400 text-indigo-600" @checked($flaggedUsersOnly ?? false) onchange="this.form.submit()">
                    Flagged users only
                </label>
                <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 cursor-pointer">
                    <input type="checkbox" name="new_only" value="1" class="h-5 w-5 rounded border-gray-400 text-indigo-600" @checked(request()->boolean('new_only')) onchange="this.form.submit()">
                    New (7d)
                </label>
                <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 cursor-pointer">
                    <input type="checkbox" name="old_only" value="1" class="h-5 w-5 rounded border-gray-400 text-indigo-600" @checked(request()->boolean('old_only')) onchange="this.form.submit()">
                    Old (30d+)
                </label>

                <label class="text-sm text-gray-700 dark:text-gray-300">
                    <span class="block text-xs text-gray-500 dark:text-gray-400 mb-0.5">Effective status</span>
                    <select name="eff_status" class="rounded-md border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm py-1.5 pr-8" onchange="this.form.submit()">
                        <option value="" @selected(request('eff_status', '') === '')>All</option>
                        <option value="pending" @selected(request('eff_status') === 'pending')>Pending</option>
                        <option value="approved" @selected(request('eff_status') === 'approved')>Approved</option>
                        <option value="rejected" @selected(request('eff_status') === 'rejected')>Rejected</option>
                    </select>
                </label>

                <label class="text-sm text-gray-700 dark:text-gray-300">
                    <span class="block text-xs text-gray-500 dark:text-gray-400 mb-0.5">Updated</span>
                    <select name="date_preset" class="rounded-md border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm py-1.5 pr-8" onchange="this.form.submit()">
                        <option value="" @selected(request('date_preset', '') === '')>Any time</option>
                        <option value="today" @selected(request('date_preset') === 'today')>Today</option>
                        <option value="month" @selected(request('date_preset') === 'month')>This month</option>
                        <option value="year" @selected(request('date_preset') === 'year')>This year</option>
                        <option value="custom" @selected(request('date_preset') === 'custom')>Custom range</option>
                    </select>
                </label>

                @if (request('date_preset') === 'custom')
                    <label class="text-sm text-gray-700 dark:text-gray-300">
                        <span class="block text-xs text-gray-500 dark:text-gray-400 mb-0.5">From</span>
                        <input type="date" name="date_from" value="{{ request('date_from') }}" class="rounded-md border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm py-1.5" onchange="this.form.submit()">
                    </label>
                    <label class="text-sm text-gray-700 dark:text-gray-300">
                        <span class="block text-xs text-gray-500 dark:text-gray-400 mb-0.5">To</span>
                        <input type="date" name="date_to" value="{{ request('date_to') }}" class="rounded-md border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm py-1.5" onchange="this.form.submit()">
                    </label>
                @endif

                <div class="ml-auto flex items-center gap-2">
                    <label for="per_page" class="text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">Per page</label>
                    <select name="per_page" id="per_page" class="rounded-md border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm py-1.5 pr-8" onchange="this.form.submit()">
                        @foreach ([20, 30, 50, 100] as $n)
                            <option value="{{ $n }}" @selected((int) request('per_page', 30) === $n)>{{ $n }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="flex flex-wrap gap-2 items-center text-xs sm:text-sm">
                <span class="text-gray-500 dark:text-gray-400 mr-1">Risk</span>
                @foreach ([
                    ['', 'All', ['risk_band'], []],
                    ['high', 'High', ['risk_band' => 'high'], ['risk_band']],
                    ['medium', 'Medium', ['risk_band' => 'medium'], ['risk_band']],
                    ['low', 'Low', ['risk_band' => 'low'], ['risk_band']],
                ] as $r)
                    @php
                        [$val, $label, $add, $remove] = $r;
                        $href = $pmRoute($val === '' ? Arr::except($pmQ, ['risk_band']) : array_merge(Arr::except($pmQ, ['risk_band']), $add));
                        $on = $val === '' ? request('risk_band', '') === '' : request('risk_band') === $val;
                    @endphp
                    <a href="{{ $href }}" class="inline-flex items-center rounded-full px-2.5 py-1 font-medium border transition {{ $on ? 'bg-gray-900 text-white border-gray-900 dark:bg-indigo-600 dark:border-indigo-600' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 border-gray-300 dark:border-gray-600 hover:border-indigo-400' }}">{{ $label }}</a>
                @endforeach

                <span class="text-gray-400 mx-1">|</span>
                <span class="text-gray-500 dark:text-gray-400">AI</span>
                @foreach ([
                    ['', 'All', []],
                    ['unsafe', 'Unsafe', ['ai_result' => 'unsafe']],
                    ['review', 'Review', ['ai_result' => 'review']],
                    ['safe', 'Safe', ['ai_result' => 'safe']],
                ] as $r)
                    @php
                        [$val, $label, $add] = $r;
                        $href = $pmRoute($val === '' ? Arr::except($pmQ, ['ai_result']) : array_merge(Arr::except($pmQ, ['ai_result']), $add));
                        $on = $val === '' ? request('ai_result', '') === '' : request('ai_result') === $val;
                    @endphp
                    <a href="{{ $href }}" class="inline-flex items-center rounded-full px-2.5 py-1 font-medium border transition {{ $on ? 'bg-gray-900 text-white border-gray-900 dark:bg-indigo-600 dark:border-indigo-600' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 border-gray-300 dark:border-gray-600 hover:border-indigo-400' }}">{{ $label }}</a>
                @endforeach
            </div>
        </form>

        <form method="post" action="{{ route('admin.photo-moderation.bulk') }}" class="flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-gray-200 dark:border-gray-600 pt-3" id="bulk-moderation-form">
            @csrf
            @foreach ($indexPreserveQuery as $k => $v)
                <input type="hidden" name="{{ $k }}" value="{{ $v }}">
            @endforeach

            <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 cursor-pointer shrink-0">
                <input type="checkbox" id="select-all-photos" class="h-5 w-5 rounded border-gray-400 text-indigo-600" title="Select all on this page">
                <span class="font-medium">All on page</span>
            </label>
            <span id="bulk-selected-count" class="text-sm tabular-nums text-gray-600 dark:text-gray-400 shrink-0">0 selected</span>

            <div class="flex flex-wrap items-center gap-2 ml-auto">
                <label class="text-sm text-gray-700 dark:text-gray-300 flex items-center gap-1.5">
                    <span class="whitespace-nowrap">Action</span>
                    <select name="action" id="bulk-action-select" required class="rounded-md border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm py-1.5 pr-7">
                        <option value="approve">Approve</option>
                        <option value="move_to_review">Move to review</option>
                        <option value="reject">Reject</option>
                        <option value="delete">Delete</option>
                    </select>
                </label>
                <label class="text-sm text-gray-700 dark:text-gray-300 flex items-center gap-1.5">
                    <span class="whitespace-nowrap sr-only sm:not-sr-only sm:inline">Reason</span>
                    <input type="text" name="reason" id="bulk-moderation-reason" minlength="10" maxlength="2000" required
                        class="w-56 sm:w-72 max-w-[40vw] rounded-md border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm px-2 py-1.5"
                        placeholder="Reason (min 10 chars)"
                        value="{{ old('reason') }}"
                        autocomplete="off">
                </label>
                <button type="submit" id="bulk-run-btn" disabled
                    class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed shrink-0">
                    Run on selected
                </button>
            </div>
        </form>
        <p id="bulk-reason-hint" class="hidden text-xs text-amber-700 dark:text-amber-300 -mt-1"></p>
    </div>

    @if ($photos->isEmpty())
        <p class="text-gray-500 dark:text-gray-400 text-sm">No photos match this filter.</p>
    @else
        <div class="w-full border border-gray-200 dark:border-gray-600 rounded-lg overflow-x-auto">
            <table class="w-full min-w-[1100px] text-sm border-separate border-spacing-0">
                <thead class="bg-gray-50 dark:bg-gray-700/50 text-left text-gray-600 dark:text-gray-300">
                    <tr>
                        <th class="px-2 py-2.5 align-middle w-10">
                            <span class="sr-only">Select</span>
                        </th>
                        <th class="px-2 py-2.5 align-middle">Preview</th>
                        <th class="px-2 py-2.5 align-middle whitespace-nowrap">IDs</th>
                        <th class="px-2 py-2.5 align-middle">User risk</th>
                        <th class="px-2 py-2.5 align-middle whitespace-nowrap" title="Model output">AI</th>
                        <th class="px-2 py-2.5 align-middle whitespace-nowrap" title="Auto vs admin touch">Who</th>
                        <th class="px-2 py-2.5 align-middle whitespace-nowrap" title="Row status">Row</th>
                        <th class="px-2 py-2.5 align-middle whitespace-nowrap" title="Effective">Out</th>
                        <th class="px-2 py-2.5 align-middle min-w-[200px]">AI reason</th>
                        <th class="px-2 py-2.5 align-middle whitespace-nowrap">Conf.</th>
                        <th class="px-2 py-2.5 align-middle whitespace-nowrap">#</th>
                        <th class="px-2 py-2.5 align-middle min-w-[120px]">Scan</th>
                        <th class="px-2 py-2.5 align-middle min-w-[200px]">Flow</th>
                        <th class="px-2 py-2.5 align-middle whitespace-nowrap">Quick</th>
                        <th class="px-2 py-2.5 align-middle whitespace-nowrap">Detail</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-600">
                    @foreach ($photos as $photo)
                        @php
                            $scanArr = \App\Services\Admin\PhotoModerationStoredScan::asArray($photo->moderation_scan_json);
                            $headMini = \App\Services\Admin\PhotoModerationScanPresenter::headline($scanArr);
                            $explain = \App\Services\Admin\PhotoModerationAiReasonPresenter::explain($scanArr);
                            $apiSt = \App\Services\Admin\PhotoModerationStoredScan::apiStatus($scanArr) ?? '—';
                            $detCount = (int) ($headMini['detection_count'] ?? 0);
                            $confPct = $headMini['confidence_pct'];
                            $confNum = $confPct !== null ? (float) $confPct : null;
                            $tooltipRaw = \App\Services\Admin\PhotoModerationScanPresenter::nudenetTooltipText($scanArr);
                            $rejectSug = \App\Services\Admin\PhotoModerationRejectReasonSuggest::fromScan($scanArr);
                            $prof = $photo->profile;
                            $statRow = $prof && $prof->user_id ? ($statsByUserId->get($prof->user_id) ?? null) : null;
                            $previewUrl = $prof
                                ? route('admin.photo-moderation.preview', ['profile' => $prof, 'galleryPhoto' => $photo])
                                : '#';
                            $whoLbl = \App\Services\Admin\PhotoModerationListRowPresenter::sourceLabel($photo);
                            $aiLbl = \App\Services\Admin\PhotoModerationListRowPresenter::modelApiLabel($scanArr);
                            $flowTitle = \App\Services\Admin\PhotoModerationListRowPresenter::sourceTitle($photo, $scanArr);
                            $rowLogs = ($logsByPhotoId[$photo->id] ?? collect());
                            $timeline = \App\Services\Admin\PhotoModerationAuditTrailPresenter::timeline($photo, $rowLogs);
                            $badge = $explain['risk_badge'] ?? '';
                            $rowTint = match (true) {
                                str_contains($badge, 'HIGH') => 'bg-rose-50/80 dark:bg-rose-950/20',
                                str_contains($badge, 'MEDIUM') => 'bg-amber-50/70 dark:bg-amber-950/15',
                                str_contains($badge, 'LOW') => 'bg-emerald-50/50 dark:bg-emerald-950/10',
                                default => '',
                            };
                        @endphp
                        <tr class="align-top hover:bg-gray-50/80 dark:hover:bg-gray-700/20 {{ $rowTint }}"
                            data-reject-suggestion="{{ e($rejectSug) }}">
                            <td class="px-2 py-2 align-middle">
                                <input type="checkbox" name="photo_ids[]" value="{{ $photo->id }}" form="bulk-moderation-form" class="bulk-photo-cb h-5 w-5 rounded border-gray-400 text-indigo-600">
                            </td>
                            <td class="px-2 py-2 align-middle whitespace-nowrap">
                                <a href="{{ route('admin.photo-moderation.show', $photo) }}" class="block">
                                    <img src="{{ $previewUrl }}" alt="" class="h-24 w-24 rounded-lg object-cover border border-gray-200 dark:border-gray-600 shadow-sm" loading="lazy">
                                </a>
                            </td>
                            <td class="px-2 py-2 align-middle text-gray-800 dark:text-gray-200">
                                <div class="whitespace-nowrap font-mono text-xs space-y-0.5">
                                    <div><span class="text-gray-500">P</span> <a href="{{ route('admin.photo-moderation.show', $photo) }}" class="text-indigo-600 dark:text-indigo-400 underline">{{ $photo->id }}</a></div>
                                    @if ($prof)
                                        <div><span class="text-gray-500">Pr</span> <a href="{{ route('admin.profiles.show', $prof) }}" class="text-indigo-600 dark:text-indigo-400 underline">{{ $prof->id }}</a></div>
                                    @endif
                                    <div><span class="text-gray-500">U</span> <span>{{ $prof?->user_id ?? '—' }}</span></div>
                                </div>
                            </td>
                            <td class="px-2 py-2 align-middle text-xs">
                                @if ($statRow && $statRow->is_flagged)
                                    <span class="inline-flex items-center rounded-md border border-rose-500 px-2 py-0.5 text-[11px] font-semibold text-rose-700 dark:border-rose-400 dark:text-rose-300" title="risk_score {{ number_format((float) $statRow->risk_score, 4) }}">FLAGGED</span>
                                @elseif ($statRow)
                                    <span class="text-gray-600 dark:text-gray-400">score {{ number_format((float) $statRow->risk_score, 2) }}</span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                                @if ($prof?->user && ($prof->user->photo_uploads_suspended ?? false))
                                    <div class="text-[11px] text-gray-600 dark:text-gray-400 mt-1">Suspended</div>
                                @endif
                                @if ($statRow && $statRow->is_flagged && $prof?->user && ! ($prof->user->photo_uploads_suspended ?? false))
                                    <form method="post" action="{{ route('admin.photo-moderation.suspend-user-uploads', $prof->user) }}" class="mt-1" onsubmit="return confirm('Suspend photo uploads for this user?');">
                                        @csrf
                                        <button type="submit" class="text-[11px] font-medium text-rose-700 underline dark:text-rose-300 bg-transparent p-0">Suspend</button>
                                    </form>
                                @endif
                            </td>
                            <td class="px-2 py-2 align-middle whitespace-nowrap font-mono text-xs" title="Model: {{ $aiLbl }}">{{ strtoupper($aiLbl) }}</td>
                            <td class="px-2 py-2 align-middle whitespace-nowrap">
                                <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-semibold {{ $whoLbl === 'Admin' ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-800 dark:bg-gray-600 dark:text-gray-100' }}" title="{{ e($flowTitle) }}">{{ $whoLbl }}</span>
                            </td>
                            <td class="px-2 py-2 align-middle whitespace-nowrap text-xs" title="{{ e($flowTitle) }}">{{ $photo->approved_status }}</td>
                            <td class="px-2 py-2 align-middle whitespace-nowrap text-xs font-semibold" title="Override: {{ $photo->admin_override_status ?? '—' }}">{{ $photo->effectiveApprovedStatus() }}</td>
                            <td class="px-2 py-2 align-middle text-xs text-gray-800 dark:text-gray-200">
                                <div class="flex flex-wrap items-center gap-1 mb-1">
                                    <span class="inline-flex rounded-md px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide
                                        @if (($explain['risk_badge'] ?? '') === 'HIGH RISK') bg-rose-600 text-white
                                        @elseif (($explain['risk_badge'] ?? '') === 'MEDIUM RISK') bg-amber-500 text-gray-900
                                        @elseif (($explain['risk_badge'] ?? '') === 'LOW RISK') bg-emerald-600 text-white
                                        @else bg-gray-200 text-gray-700 dark:bg-gray-600 dark:text-gray-200 @endif">{{ $explain['risk_badge'] ?? '—' }}</span>
                                </div>
                                <p class="leading-snug [overflow-wrap:anywhere]" title="{{ $explain['summary'] ?? '' }}">{{ \Illuminate\Support\Str::limit($explain['summary'] ?? '—', 100) }}</p>
                            </td>
                            <td class="px-2 py-2 align-middle whitespace-nowrap w-28">
                                @if ($confNum !== null)
                                    <div class="text-xs text-gray-600 dark:text-gray-400 mb-0.5 tabular-nums">{{ $confPct }}%</div>
                                    <div class="h-2 w-full rounded-full bg-gray-200 dark:bg-gray-600 overflow-hidden" title="{{ $confPct }}%">
                                        <div class="h-full rounded-full {{ $confNum >= 70 ? 'bg-emerald-500' : ($confNum >= 40 ? 'bg-amber-500' : 'bg-rose-500') }}" style="width: {{ min(100, max(0, $confNum)) }}%"></div>
                                    </div>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-2 py-2 align-middle whitespace-nowrap tabular-nums text-gray-700 dark:text-gray-300">{{ $detCount }}</td>
                            <td class="px-2 py-2 align-middle">
                                <div class="relative group inline-block max-w-full cursor-help">
                                    <span class="text-xs text-gray-800 dark:text-gray-200 border-b border-dotted border-gray-500 dark:border-gray-400 whitespace-nowrap">
                                        {{ $apiSt }} · {{ $detCount }}
                                    </span>
                                    <div class="pointer-events-none absolute left-0 z-50 mb-1 hidden w-max max-w-md rounded-md border border-gray-600 bg-gray-900 px-2 py-2 text-left text-[11px] leading-snug text-gray-100 shadow-xl bottom-full group-hover:block">
                                        <pre class="m-0 max-h-64 overflow-auto whitespace-pre-wrap font-mono [overflow-wrap:anywhere]">{{ $tooltipRaw }}</pre>
                                    </div>
                                </div>
                            </td>
                            <td class="px-2 py-2 align-middle text-[11px] leading-snug text-gray-700 dark:text-gray-300">
                                <ul class="space-y-1 max-w-xs">
                                    @foreach (array_slice($timeline, 0, 4) as $row)
                                        <li class="flex gap-1.5 [overflow-wrap:anywhere]">
                                            <span class="shrink-0 mt-1 w-1.5 h-1.5 rounded-full @if ($row['kind'] === 'ai') bg-violet-500 @elseif ($row['kind'] === 'admin') bg-indigo-500 @else bg-gray-400 @endif"></span>
                                            <span>{{ $row['text'] }}</span>
                                        </li>
                                    @endforeach
                                    @if (count($timeline) > 4)
                                        <li class="text-indigo-600 dark:text-indigo-400">+{{ count($timeline) - 4 }} more in detail…</li>
                                    @endif
                                </ul>
                            </td>
                            <td class="px-2 py-2 align-middle whitespace-nowrap">
                                <div class="flex flex-col gap-1">
                                    <form method="post" action="{{ route('admin.photo-moderation.action', $photo) }}" class="inline" onsubmit="return confirm('Approve photo #{{ $photo->id }}?');">
                                        @csrf
                                        <input type="hidden" name="_return" value="{{ url()->full() }}">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="reason" value="Quick approve from moderation list — photo {{ $photo->id }}.">
                                        <button type="submit" class="w-full text-left rounded px-2 py-1 text-[11px] font-semibold bg-emerald-600 text-white hover:bg-emerald-700">Approve</button>
                                    </form>
                                    <form method="post" action="{{ route('admin.photo-moderation.action', $photo) }}" class="inline">
                                        @csrf
                                        <input type="hidden" name="_return" value="{{ url()->full() }}">
                                        <input type="hidden" name="action" value="move_to_review">
                                        <input type="hidden" name="reason" value="Quick move to review from list — photo {{ $photo->id }}.">
                                        <button type="submit" class="w-full text-left rounded px-2 py-1 text-[11px] font-semibold bg-amber-500 text-gray-900 hover:bg-amber-600">Review</button>
                                    </form>
                                    <form method="post" action="{{ route('admin.photo-moderation.action', $photo) }}" class="inline" onsubmit="return confirm('Reject photo #{{ $photo->id }}?');">
                                        @csrf
                                        <input type="hidden" name="_return" value="{{ url()->full() }}">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="reason" value="Quick reject from moderation list — photo {{ $photo->id }}.">
                                        <button type="submit" class="w-full text-left rounded px-2 py-1 text-[11px] font-semibold bg-rose-600 text-white hover:bg-rose-700">Reject</button>
                                    </form>
                                </div>
                            </td>
                            <td class="px-2 py-2 align-middle whitespace-nowrap">
                                <button type="button" class="text-indigo-600 dark:text-indigo-400 underline text-xs font-medium pm-open-panel" data-panel-url="{{ route('admin.photo-moderation.panel', $photo) }}">Detail</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $photos->links() }}</div>
    @endif
</div>

<div id="pm-modal" class="fixed inset-0 z-[100] hidden" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="absolute inset-0 bg-black/50 pm-modal-backdrop" data-pm-close></div>
    <div class="absolute inset-3 sm:inset-8 md:left-1/2 md:top-1/2 md:-translate-x-1/2 md:-translate-y-1/2 md:inset-auto md:max-w-3xl md:w-full max-h-[90vh] overflow-hidden flex flex-col rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 shadow-2xl">
        <div class="flex items-center justify-between gap-2 px-4 py-3 border-b border-gray-200 dark:border-gray-600 shrink-0">
            <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">Photo detail</span>
            <button type="button" class="rounded p-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" data-pm-close aria-label="Close">&times;</button>
        </div>
        <div id="pm-modal-body" class="overflow-y-auto p-4 flex-1 text-sm">
            <p class="text-gray-500">Loading…</p>
        </div>
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('bulk-moderation-form');
    const reasonEl = document.getElementById('bulk-moderation-reason');
    const btn = document.getElementById('bulk-run-btn');
    const hint = document.getElementById('bulk-reason-hint');
    const actionSel = document.getElementById('bulk-action-select');
    const selectAll = document.getElementById('select-all-photos');
    const countEl = document.getElementById('bulk-selected-count');

    function reasonOk() {
        return (reasonEl && reasonEl.value.trim().length >= 10);
    }
    function hasSelection() {
        return document.querySelectorAll('input.bulk-photo-cb:checked').length > 0;
    }
    function updateCount() {
        const n = document.querySelectorAll('input.bulk-photo-cb:checked').length;
        if (countEl) countEl.textContent = n + ' selected';
    }
    function sync() {
        const ok = reasonOk() && hasSelection();
        const sel = hasSelection();
        if (btn) btn.disabled = !ok;
        updateCount();
        if (hint) {
            if (!sel) {
                hint.textContent = '';
                hint.classList.add('hidden');
            } else if (!reasonOk()) {
                hint.textContent = 'Reason required (min 10 characters).';
                hint.classList.remove('hidden');
            } else {
                hint.textContent = '';
                hint.classList.add('hidden');
            }
        }
    }

    reasonEl?.addEventListener('input', sync);
    actionSel?.addEventListener('change', function () {
        if (this.value === 'reject' && reasonEl && reasonEl.value.trim().length < 10) {
            const first = document.querySelector('input.bulk-photo-cb:checked');
            const tr = first && first.closest('tr');
            const sug = tr && tr.dataset.rejectSuggestion;
            if (sug) reasonEl.value = sug;
        }
        sync();
    });
    document.querySelectorAll('input.bulk-photo-cb').forEach(function (el) {
        el.addEventListener('change', sync);
    });
    selectAll?.addEventListener('change', function () {
        document.querySelectorAll('input.bulk-photo-cb').forEach(function (el) {
            el.checked = selectAll.checked;
        });
        sync();
    });
    form?.addEventListener('submit', function (e) {
        if (!hasSelection()) {
            e.preventDefault();
            alert('Select at least one photo.');
            return;
        }
        if (!reasonOk()) {
            e.preventDefault();
            alert('Reason required (min 10 characters).');
            return;
        }
        const act = actionSel && actionSel.value;
        if (act === 'delete' && !confirm('Delete selected photos and files?')) {
            e.preventDefault();
        }
    });
    sync();

    const modal = document.getElementById('pm-modal');
    const modalBody = document.getElementById('pm-modal-body');

    function closeModal() {
        if (!modal) return;
        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('overflow-hidden');
    }
    function openModal() {
        if (!modal) return;
        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('overflow-hidden');
    }

    modal?.querySelectorAll('[data-pm-close]').forEach(function (el) {
        el.addEventListener('click', closeModal);
    });

    document.querySelectorAll('.pm-open-panel').forEach(function (btnEl) {
        btnEl.addEventListener('click', function () {
            const url = btnEl.getAttribute('data-panel-url');
            if (!url || !modalBody) return;
            openModal();
            modalBody.innerHTML = '<p class="text-gray-500">Loading…</p>';
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' } })
                .then(function (r) { return r.text(); })
                .then(function (html) { modalBody.innerHTML = html; })
                .catch(function () { modalBody.innerHTML = '<p class="text-red-600">Could not load detail.</p>'; });
        });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal && !modal.classList.contains('hidden')) {
            closeModal();
        }
    });
})();

function loadAiHealth() {
    fetch('/admin/dashboard-metrics/ai-health')
        .then(res => res.json())
        .then(json => {
            const status = json?.data?.status;
            const el = document.getElementById('aiStatusText');

            if (!el) return;

            if (status === 'up') {
                el.textContent = '🟢 Running';
                el.className = 'text-emerald-600 font-bold';
            } else {
                el.textContent = '🔴 Down';
                el.className = 'text-red-600 font-bold';
            }
        })
        .catch(() => {
            const el = document.getElementById('aiStatusText');
            if (el) {
                el.textContent = '⚠️ Error';
                el.className = 'text-yellow-600 font-bold';
            }
        });
}

// run on load
loadAiHealth();

// refresh every 60 sec
setInterval(loadAiHealth, 60000);
</script>
@endsection

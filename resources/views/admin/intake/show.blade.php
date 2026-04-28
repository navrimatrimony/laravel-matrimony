@extends('layouts.admin')

@section('content')
<div class="max-w-5xl mx-auto">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-100">Admin Intake Page</h1>
            <p class="text-gray-400 text-sm">Review biodata intake, attachment, and apply governance.</p>
        </div>
        <a href="{{ route('admin.biodata-intakes.index') }}" class="text-sm text-gray-300 hover:text-white underline">← Back to intakes</a>
    </div>

    @if (session('success'))
        <div class="mb-3 px-4 py-2 rounded bg-emerald-600/10 border border-emerald-500 text-emerald-200 text-sm">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-3 px-4 py-2 rounded bg-red-600/10 border border-red-500 text-red-200 text-sm">
            {{ session('error') }}
        </div>
    @endif
    @if (session('warning'))
        <div class="mb-3 px-4 py-2 rounded bg-amber-600/10 border border-amber-500 text-amber-100 text-sm">
            {{ session('warning') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="mb-3 px-4 py-2 rounded bg-red-600/10 border border-red-500 text-red-200 text-sm">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @php
        $fn = trim((string) ($intake->original_filename ?? ''));
        $fp = trim((string) ($intake->file_path ?? ''));
        $fallback = $fp !== '' ? basename($fp) : '';
        $display = $fn !== '' ? $fn : ($fallback !== '' ? $fallback : '—');
        $uploadedAt = $intake->created_at ?? null;
        $ext = strtolower(pathinfo($fp !== '' ? $fp : $display, PATHINFO_EXTENSION));
        $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
        $isPdf = $ext === 'pdf';
        $openUrl = $isImage ? route('intake.biodata-original', $intake) : null;
    @endphp

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <div class="bg-gray-800/70 border border-gray-700 rounded-xl p-6 lg:col-span-2">
            <div class="flex items-start justify-between gap-4 mb-4">
                <div>
                    <h2 class="text-sm font-semibold text-gray-100">Intake Overview</h2>
                    <p class="text-xs text-gray-400">Decision context: status, owner, file, and access.</p>
                </div>
                <div class="text-xs text-gray-400 text-right">
                    <div class="font-semibold text-gray-100">#{{ $intake->id }}</div>
                    <div class="mt-1 flex items-center justify-end gap-2">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border border-gray-600 text-gray-200 bg-gray-700/60">
                            {{ $intake->intake_status }}
                        </span>
                        @php
                            $parse = (string) ($intake->parse_status ?? '');
                            $parseChip = $parse === 'parsed'
                                ? 'border-emerald-600/50 text-emerald-200 bg-emerald-600/10'
                                : ($parse === 'error'
                                    ? 'border-red-600/50 text-red-200 bg-red-600/10'
                                    : 'border-gray-600 text-gray-200 bg-gray-700/60');
                        @endphp
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border {{ $parseChip }}">
                            {{ $parse !== '' ? $parse : '—' }}
                        </span>
                    </div>
                </div>
            </div>

            <dl class="grid grid-cols-1 md:grid-cols-2 gap-x-10 gap-y-5 text-sm">
                <div>
                    <dt class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Owner</dt>
                    <dd class="mt-1 text-gray-100">
                        <div class="font-semibold">{{ $intake->uploadedByUser->name ?? '—' }}</div>
                        <div class="text-xs text-gray-400">{{ $intake->uploadedByUser->email ?? '—' }}</div>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Attached profile</dt>
                    <dd class="mt-1 text-gray-100">
                        @if ($intake->profile)
                            <div class="font-semibold">#{{ $intake->profile->id }}</div>
                            <div class="text-xs text-gray-300">{{ $intake->profile->full_name }}</div>
                        @else
                            <span class="text-gray-400">Not attached</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Uploaded</dt>
                    <dd class="mt-1 text-gray-100">{{ $uploadedAt ? $uploadedAt->toDateTimeString() : '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold text-gray-400 uppercase tracking-wide">File name</dt>
                    <dd class="mt-1 text-gray-100">
                        <span class="font-semibold" title="{{ $display }}">{{ $display }}</span>
                    </dd>
                </div>

                <div class="md:col-span-2 pt-4 border-t border-gray-700/70">
                    <dt class="text-xs font-semibold text-gray-400 uppercase tracking-wide">File access</dt>
                    <dd class="mt-2">
                        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                            <div class="min-w-0">
                                @if ($fp !== '')
                                    <div class="text-[11px] text-gray-400">
                                        <span class="text-gray-500">Stored path:</span>
                                        <span class="font-mono inline-block max-w-full overflow-hidden text-ellipsis whitespace-nowrap align-bottom" title="{{ $fp }}">{{ $fp }}</span>
                                    </div>
                                @else
                                    <div class="text-[11px] text-gray-500">Stored path: —</div>
                                @endif
                            </div>
                            <div class="shrink-0 flex flex-wrap gap-2">
                                @if ($openUrl)
                                    <a href="{{ $openUrl }}" target="_blank"
                                       class="inline-flex items-center px-3 py-2 rounded bg-gray-700 hover:bg-gray-600 text-xs font-semibold text-gray-100">
                                        Open file →
                                    </a>
                                    <button type="button"
                                            class="inline-flex items-center px-3 py-2 rounded bg-gray-700 hover:bg-gray-600 text-xs font-semibold text-gray-100"
                                            onclick="(function(){ const d=document.getElementById('uploadPreviewDialog'); if(d){ d.showModal(); } })();">
                                        Preview file
                                    </button>
                                @elseif ($isPdf)
                                    <span class="text-xs text-gray-400">PDF open/preview not available via existing secure route.</span>
                                @else
                                    <span class="text-xs text-gray-400">Open/preview not available for this file type.</span>
                                @endif
                            </div>
                        </div>
                    </dd>
                </div>
            </dl>

            @if ($openUrl)
                <dialog id="uploadPreviewDialog" class="backdrop:bg-black/70 rounded-lg p-0 w-[min(900px,95vw)]">
                    <div class="bg-gray-900 border border-gray-700 rounded-lg">
                        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-700">
                            <div class="text-sm font-semibold text-gray-100 truncate" title="{{ $display }}">Preview: {{ $display }}</div>
                            <button type="button"
                                    class="text-xs font-semibold text-gray-300 hover:text-white"
                                    onclick="(function(){ const d=document.getElementById('uploadPreviewDialog'); if(d){ d.close(); } })();">
                                Close
                            </button>
                        </div>
                        <div class="p-3 bg-black/30">
                            <img src="{{ $openUrl }}" alt="Uploaded file preview" class="max-h-[75vh] w-auto mx-auto rounded">
                        </div>
                    </div>
                </dialog>
            @endif
        </div>

        <div class="bg-gray-800/70 border border-gray-700 rounded-xl p-6">
            <h2 class="text-sm font-semibold text-gray-100">Admin Actions</h2>
            <p class="text-xs text-gray-400 mb-4">Choose the next safe action.</p>

            <div class="space-y-4">
                <div class="border border-gray-700/70 rounded-lg p-3">
                    <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Parsing actions</div>
                    <div class="space-y-2">
                        <form method="POST" action="{{ route('admin.biodata-intakes.reparse', $intake) }}">
                            @csrf
                            <button type="submit"
                                    class="w-full inline-flex items-center justify-center px-3 py-2.5 rounded border border-gray-600 bg-gray-800 hover:bg-gray-700 text-xs font-semibold text-gray-100"
                                    title="Uses existing extracted text and runs parsing again (no new vision API call).">
                                Re-parse (no new AI extraction)
                            </button>
                            <div class="mt-1 text-[11px] text-gray-400">Uses the existing extracted text and runs parsing again.</div>
                        </form>

                        @if (! empty($showAdminReextractAction))
                            <form method="POST"
                                  action="{{ route('admin.biodata-intakes.re-extract', $intake) }}"
                                  onsubmit="return confirm('Run paid vision extraction again for this intake?');">
                                @csrf
                                <button type="submit"
                                        class="w-full inline-flex items-center justify-center px-3 py-2.5 rounded bg-amber-500 hover:bg-amber-400 text-xs font-semibold text-gray-900">
                                    Re-extract (vision again)
                                </button>
                                <div class="mt-1 text-[11px] text-amber-200/90">Runs vision extraction again from the uploaded file, then parses fresh output.</div>
                            </form>
                        @endif
                    </div>
                </div>

                <div class="border border-gray-700/70 rounded-lg p-3">
                    <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Profile action</div>
                    @php
                        $ar = $applyReadiness ?? [];
                        $ready = (bool) ($ar['can_admin_apply'] ?? false);
                        $missing = [];
                        if (! ($ar['user_approved'] ?? false)) {
                            $missing[] = 'user approval';
                        }
                        if (! ($ar['attached_profile'] ?? false)) {
                            $missing[] = 'attached profile';
                        }
                        if (! ($ar['has_snapshot'] ?? false)) {
                            $missing[] = 'approval snapshot';
                        }
                        if (! ($ar['admin_required'] ?? false)) {
                            $missing[] = 'admin apply mode enabled in settings';
                        }
                    @endphp
                    @if ($ready)
                        <p class="mb-2 text-[11px] text-emerald-200/90">All preconditions for admin apply are met. Submitting will run the approval pipeline.</p>
                    @else
                        <p class="mb-2 text-[11px] text-amber-200/80">Not ready: {{ $missing === [] ? 'check intake state and settings.' : 'missing: '.implode(', ', $missing).'.' }}</p>
                    @endif
                    <form method="POST" action="{{ route('admin.biodata-intakes.apply', $intake) }}">
                        @csrf
                        <button type="submit" class="w-full inline-flex items-center justify-center px-3 py-2 rounded bg-emerald-600 hover:bg-emerald-700 text-xs font-semibold text-white">
                            Apply intake to profile
                        </button>
                    </form>
                    <p class="mt-2 text-[11px] text-gray-400">
                        Apply action is effective only when user has approved the intake and admin approval is required in settings.
                    </p>
                </div>

                <div class="border border-gray-700/70 rounded-lg p-3">
                    <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">View</div>
                    <a href="{{ route('intake.preview', $intake) }}"
                       target="_blank"
                       class="w-full inline-flex items-center justify-center px-3 py-2.5 rounded border border-gray-600 bg-transparent hover:bg-gray-800 text-xs font-semibold text-gray-100">
                        Open user preview →
                    </a>
                    <p class="mt-1 text-[11px] text-gray-400">Open the user-facing preview for this intake.</p>
                </div>
            </div>
        </div>
    </div>

    @if (! empty($unresolvedLocationOptions) && is_array($unresolvedLocationOptions))
        <div class="bg-amber-900/20 border border-amber-700 rounded-xl p-5 mb-6">
            <h2 class="text-sm font-semibold text-amber-100 mb-1">Resolve unresolved locations</h2>
            <p class="text-xs text-amber-200/80 mb-3">Quick resolve candidates from search. This updates intake approval snapshot only.</p>
            <div class="space-y-4">
                @foreach ($unresolvedLocationOptions as $loc)
                    @php
                        $opts = is_array($loc['options'] ?? null) ? $loc['options'] : [];
                    @endphp
                    <div class="rounded-lg border border-amber-700/70 bg-gray-900/40 p-3">
                        <div class="text-xs text-gray-400 mb-1">{{ $loc['label'] ?? ($loc['field_key'] ?? 'Location') }}</div>
                        <div class="text-sm font-semibold text-gray-100 mb-2">"{{ $loc['raw_input'] ?? '' }}"</div>
                        <div class="flex gap-2 mb-2">
                            <input
                                type="text"
                                class="admin-intake-loc-search-input flex-1 px-2 py-1.5 text-xs rounded border border-gray-600 bg-gray-900 text-gray-100"
                                value="{{ $loc['raw_input'] ?? '' }}"
                                placeholder="Search more"
                            >
                            <button type="button" class="admin-intake-loc-search-btn px-2.5 py-1.5 text-xs rounded border border-gray-600">Search more</button>
                        </div>
                        @if ($opts !== [])
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-2 admin-intake-loc-options-list">
                                @foreach ($opts as $opt)
                                    <button
                                        type="button"
                                        class="admin-intake-loc-resolve-btn text-left px-3 py-2 rounded border border-gray-600 hover:bg-gray-800 text-xs text-gray-100"
                                        data-field="{{ $loc['field_key'] ?? '' }}"
                                        data-city-id="{{ $opt['city_id'] ?? '' }}"
                                    >
                                        {{ ($opt['display_label'] ?? $opt['name'] ?? $opt['city_name'] ?? '—') }}
                                    </button>
                                @endforeach
                            </div>
                        @else
                            <p class="text-xs text-gray-300 admin-intake-loc-empty-note">No quick matches. Use open place suggestions flow if needed.</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @php
        $govProfile = $attachedProfile ?? $intake->profile;
        $govPendingConflicts = (int) ($pendingConflictCount ?? 0);
        $govSuggestionsPresent = (bool) ($pendingSuggestionsPresent ?? false);
        $govSuggestionsCount = (int) ($pendingSuggestionsCount ?? 0);
        $govRequireAdmin = (bool) ($requireAdminBeforeAttach ?? false);
        $govReadiness = $applyReadiness ?? [];
        $mutationResult = session('mutation_result');
        $mutationResult = is_array($mutationResult) ? $mutationResult : null;
    @endphp
    <div class="bg-gray-800/70 border border-gray-700 rounded-xl p-6 mb-6">
        <h2 class="text-sm font-semibold text-gray-100 mb-1">Governance Status</h2>
        <p class="text-xs text-gray-400 mb-4">Read-only apply and conflict context for this intake.</p>
        <dl class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-4 text-sm">
            <div>
                <dt class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Attached profile</dt>
                <dd class="mt-1 text-gray-100">
                    @if ($govProfile)
                        <div><span class="font-mono text-gray-300">#{{ $govProfile->id }}</span> — {{ $govProfile->full_name ?? '—' }}</div>
                        <div class="text-xs text-gray-400 mt-0.5">Lifecycle: <span class="text-gray-200">{{ $govProfile->lifecycle_state ?? '—' }}</span></div>
                    @else
                        <span class="text-gray-400">Not attached</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs font-semibold text-gray-400 uppercase tracking-wide">User approval</dt>
                <dd class="mt-1 text-gray-100">
                    {{ ! empty($intake->approved_by_user) ? 'Approved' : 'Not approved' }}
                    @if (! empty($intake->approved_at))
                        <span class="text-xs text-gray-400 block mt-0.5">{{ $intake->approved_at instanceof \Illuminate\Support\Carbon ? $intake->approved_at->toDateTimeString() : (string) $intake->approved_at }}</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Snapshot readiness</dt>
                <dd class="mt-1 text-gray-100">{{ ! empty($govReadiness['has_snapshot']) ? 'Present' : 'Missing' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Admin apply mode</dt>
                <dd class="mt-1 text-gray-100">{{ $govRequireAdmin ? 'Admin required before apply' : 'Not required' }}</dd>
            </div>
            <div class="md:col-span-2">
                <dt class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Pending conflicts (attached profile)</dt>
                <dd class="mt-1">
                    @if ($govPendingConflicts > 0)
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold border border-amber-500/60 text-amber-100 bg-amber-600/20">{{ $govPendingConflicts }} pending</span>
                        @if (! empty($recentPendingConflicts) && $recentPendingConflicts->isNotEmpty())
                            <div class="mt-2 text-[11px] text-gray-400">
                                Recent:
                                @foreach ($recentPendingConflicts as $rc)
                                    <a href="{{ route('admin.conflict-records.show', $rc) }}" class="text-amber-200/90 hover:underline mr-2">#{{ $rc->id }}</a>
                                @endforeach
                            </div>
                        @endif
                    @else
                        <span class="text-gray-300">0</span>
                    @endif
                </dd>
            </div>
            <div class="md:col-span-2">
                <dt class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Pending suggestions (profile)</dt>
                <dd class="mt-1 text-gray-100">
                    @if ($govSuggestionsPresent)
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold border border-sky-500/50 text-sky-100 bg-sky-600/20">{{ $govSuggestionsCount }} non-empty bucket(s)</span>
                        <span class="block mt-1 text-[11px] text-gray-400">See <strong class="text-gray-300">Pending Suggestions Summary</strong> below for per-bucket detail.</span>
                    @else
                        None
                    @endif
                </dd>
            </div>
        </dl>
        <div class="mt-5 pt-4 border-t border-gray-700/70">
            <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Apply readiness</div>
            <ul class="space-y-1 text-xs text-gray-300">
                <li><span class="text-gray-500">User approved:</span> {{ ! empty($govReadiness['user_approved']) ? 'yes' : 'no' }}</li>
                <li><span class="text-gray-500">Attached profile:</span> {{ ! empty($govReadiness['attached_profile']) ? 'yes' : 'no' }}</li>
                <li><span class="text-gray-500">Approval snapshot:</span> {{ ! empty($govReadiness['has_snapshot']) ? 'yes' : 'no' }}</li>
                <li><span class="text-gray-500">Admin apply required:</span> {{ ! empty($govReadiness['admin_required']) ? 'yes' : 'no' }}</li>
                <li class="pt-1 font-semibold {{ ! empty($govReadiness['can_admin_apply']) ? 'text-emerald-200' : 'text-amber-200' }}">
                    {{ ! empty($govReadiness['can_admin_apply']) ? 'Ready to apply' : 'Not ready to apply' }}
                </li>
            </ul>
        </div>
        @if ($mutationResult)
            <div class="mt-5 pt-4 border-t border-gray-700/70">
                <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Last apply result</div>
                <ul class="text-xs text-gray-300 space-y-1">
                    <li><span class="text-gray-500">Mutation success:</span> {{ ! empty($mutationResult['mutation_success']) ? 'yes' : 'no' }}</li>
                    <li><span class="text-gray-500">Conflict detected:</span> {{ ! empty($mutationResult['conflict_detected']) ? 'yes' : 'no' }}</li>
                    <li><span class="text-gray-500">Blocked:</span> {{ isset($mutationResult['blocked']) && $mutationResult['blocked'] !== null && $mutationResult['blocked'] !== '' ? (string) $mutationResult['blocked'] : '—' }}</li>
                    <li><span class="text-gray-500">Already applied:</span> {{ ! empty($mutationResult['already_applied']) ? 'yes' : 'no' }}</li>
                </ul>
                <div class="mt-3 flex flex-wrap gap-2">
                    @if (! empty($mutationResult['conflict_detected']) && $govProfile)
                        <a href="{{ route('admin.conflict-records.index') }}" class="inline-flex items-center px-3 py-1.5 rounded border border-amber-600/50 text-amber-100 text-xs font-semibold hover:bg-amber-600/10">Open pending conflicts</a>
                    @endif
                    @if (! empty($mutationResult['mutation_success']) && $govProfile)
                        <a href="{{ route('admin.profiles.show', $govProfile->id) }}" class="inline-flex items-center px-3 py-1.5 rounded border border-emerald-600/50 text-emerald-100 text-xs font-semibold hover:bg-emerald-600/10">Open attached profile</a>
                    @endif
                    <a href="{{ route('intake.preview', $intake) }}" target="_blank" class="inline-flex items-center px-3 py-1.5 rounded border border-gray-600 text-gray-200 text-xs font-semibold hover:bg-gray-700/50">Open user preview</a>
                </div>
            </div>
        @endif
    </div>

    @php
        $sugSum = $pendingSuggestionsAdminSummary ?? ['has_any' => false, 'non_empty_bucket_count' => 0, 'buckets' => [], 'review_strip' => []];
        $sugBuckets = is_array($sugSum['buckets'] ?? null) ? $sugSum['buckets'] : [];
        $reviewStrip = is_array($sugSum['review_strip'] ?? null) ? $sugSum['review_strip'] : [];
    @endphp
    <div class="bg-gray-800/70 border border-gray-700 rounded-xl p-6 mb-6">
        <h2 class="text-sm font-semibold text-gray-100 mb-1">Pending Suggestions Summary</h2>
        <p class="text-xs text-gray-400 mb-3">Read-only view of <code class="text-gray-500">pending_intake_suggestions_json</code> on the attached profile (Phase-5 merge / partition). Empty buckets are omitted below.</p>

        <div class="mb-4 flex flex-wrap gap-2 text-[11px]" aria-label="Suggestion review summary">
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border border-gray-600 bg-gray-900/50 text-gray-200">
                <span class="text-gray-500">Non-empty buckets</span>
                <span class="font-semibold text-gray-100">{{ (int) ($reviewStrip['non_empty_bucket_count'] ?? 0) }}</span>
            </span>
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border border-gray-600 bg-gray-900/50 text-gray-200">
                <span class="text-gray-500">Core field suggestion rows</span>
                <span class="font-semibold text-gray-100">{{ (int) ($reviewStrip['core_field_suggestion_row_count'] ?? 0) }}</span>
            </span>
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border {{ ($reviewStrip['pending_conflict_count'] ?? 0) > 0 ? 'border-amber-600/60 bg-amber-900/20 text-amber-100' : 'border-gray-600 bg-gray-900/50 text-gray-200' }}">
                <span class="{{ ($reviewStrip['pending_conflict_count'] ?? 0) > 0 ? 'text-amber-200/80' : 'text-gray-500' }}">Pending conflicts</span>
                <span class="font-semibold">{{ (int) ($reviewStrip['pending_conflict_count'] ?? 0) }}</span>
            </span>
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border border-gray-600 bg-gray-900/50 text-gray-200">
                <span class="text-gray-500">Attached profile</span>
                <span class="font-semibold">{{ !empty($reviewStrip['profile_attached']) ? 'Yes' : 'No' }}</span>
            </span>
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border border-gray-600 bg-gray-900/50 text-gray-200">
                <span class="text-gray-500">Member suggestion page</span>
                <span class="font-semibold">{{ !empty($reviewStrip['member_suggestion_page_available']) ? 'Available' : '—' }}</span>
            </span>
        </div>

        <div class="mb-4 p-3 rounded-lg border border-gray-700/80 bg-gray-900/40 text-[11px] text-gray-400 leading-relaxed">
            Deferred suggestions were not auto-applied because they would replace existing data or need human review. They are not the same as conflict records—if conflicts are listed above, review them separately before trusting apply outcomes.
        </div>

        @if (!($sugSum['has_any'] ?? false))
            <p class="text-sm text-gray-400">No pending suggestion buckets on the attached profile.</p>
        @else
            <ul class="space-y-3">
                @foreach ($sugBuckets as $b)
                    @if (empty($b['exists']))
                        @continue
                    @endif
                    @php
                        $p = $b['preview'] ?? null;
                        $ptype = is_array($p) ? ($p['type'] ?? '') : '';
                    @endphp
                    <li class="border border-gray-700/80 rounded-lg bg-gray-900/30 overflow-hidden">
                        <div class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 border-b border-gray-700/60">
                            <span class="text-sm font-medium text-gray-100">{{ $b['label'] ?? $b['key'] ?? '—' }}</span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold border border-gray-600 text-gray-200 bg-gray-700/60">{{ (int) ($b['item_count'] ?? 0) }} items</span>
                        </div>
                        <details class="group">
                            <summary class="cursor-pointer select-none px-3 py-2 text-xs text-sky-300/90 hover:text-sky-200 list-none flex items-center gap-2">
                                <span class="text-gray-500 group-open:hidden">Show preview ▾</span>
                                <span class="text-gray-500 hidden group-open:inline">Hide preview ▴</span>
                            </summary>
                            <div class="px-3 pb-3 pt-1 border-t border-gray-700/40 text-xs text-gray-300">
                                @if ($ptype === 'key_value' && !empty($p['pairs']) && is_array($p['pairs']))
                                    <dl class="space-y-2 max-h-64 overflow-y-auto">
                                        @foreach ($p['pairs'] as $pair)
                                            <div class="border-b border-gray-700/30 pb-2 last:border-0">
                                                <div class="flex flex-wrap items-center gap-2 mb-0.5">
                                                    <dt class="font-mono text-gray-300 text-[11px] shrink-0">{{ $pair['key'] ?? '—' }}</dt>
                                                    @if (!empty($pair['badge']))
                                                        <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wide border border-sky-600/50 text-sky-200 bg-sky-900/30">{{ $pair['badge'] }}</span>
                                                    @endif
                                                </div>
                                                <dd class="text-gray-200 break-words whitespace-pre-wrap text-[11px] pl-0">
                                                    <span class="text-gray-500">Incoming:</span> {{ $pair['value'] ?? '—' }}
                                                    @if (array_key_exists('profile_value_preview', $pair) && $pair['profile_value_preview'] !== null && $pair['profile_value_preview'] !== '')
                                                        <span class="block mt-0.5 text-gray-500">Profile now: <span class="text-gray-300">{{ $pair['profile_value_preview'] }}</span></span>
                                                    @endif
                                                </dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                @elseif ($ptype === 'core_field_rows' && !empty($p['rows']) && is_array($p['rows']))
                                    <div class="overflow-x-auto max-h-[28rem] overflow-y-auto">
                                        <table class="min-w-full text-left text-[11px] border border-gray-700/60 rounded-md overflow-hidden">
                                            <thead class="bg-gray-900/80 text-gray-400 uppercase tracking-wide">
                                                <tr>
                                                    <th class="px-2 py-2 font-medium">Field</th>
                                                    <th class="px-2 py-2 font-medium">Current</th>
                                                    <th class="px-2 py-2 font-medium">Incoming</th>
                                                    <th class="px-2 py-2 font-medium whitespace-nowrap">Hint</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-700/50 text-gray-200">
                                                @foreach ($p['rows'] as $row)
                                                    <tr class="align-top bg-gray-900/20">
                                                        <td class="px-2 py-2 font-mono text-gray-300">{{ $row['field'] !== '' ? $row['field'] : '—' }}</td>
                                                        <td class="px-2 py-2 break-words max-w-[14rem] whitespace-pre-wrap">{{ ($row['current_profile_value'] ?? '') !== '' ? Str::limit($row['current_profile_value'], 160) : '—' }}</td>
                                                        <td class="px-2 py-2 break-words max-w-[14rem] whitespace-pre-wrap">{{ ($row['new_value'] ?? '') !== '' ? Str::limit($row['new_value'], 160) : '—' }}</td>
                                                        <td class="px-2 py-2 text-amber-100/90 whitespace-nowrap">{{ $row['hint'] ?? '—' }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                        <p class="mt-2 text-[10px] text-gray-500">Hints are operator-only labels from simple rules (fill vs overwrite vs conflict field overlap); they do not change server behavior.</p>
                                    </div>
                                @elseif ($ptype === 'entity_sections' && !empty($p['sections']) && is_array($p['sections']))
                                    <p class="text-gray-400 mb-2 text-[11px]">Deferred entity sections (counts from suggestion payload only):</p>
                                    <ul class="flex flex-wrap gap-1.5">
                                        @foreach ($p['sections'] as $sec)
                                            @php
                                                $secName = is_array($sec) ? ($sec['name'] ?? '') : (string) $sec;
                                                $secCount = is_array($sec) && isset($sec['row_count']) ? (int) $sec['row_count'] : null;
                                            @endphp
                                            <li class="px-2 py-1 rounded border border-gray-600 text-gray-200 font-mono text-[11px]">
                                                {{ $secName !== '' ? $secName : '—' }}@if ($secCount !== null)<span class="text-gray-500"> ({{ $secCount }} {{ $secCount === 1 ? 'row' : 'rows' }})</span>@endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @elseif ($ptype === 'json' && isset($p['text']))
                                    <pre class="text-[11px] font-mono text-gray-200 bg-gray-950/60 p-2 rounded border border-gray-700 max-h-64 overflow-auto whitespace-pre-wrap break-words">{{ $p['text'] }}</pre>
                                @elseif ($ptype === 'text' && isset($p['text']))
                                    <p class="text-gray-200 whitespace-pre-wrap break-words">{{ $p['text'] }}</p>
                                @else
                                    <p class="text-gray-500 italic">No preview available for this bucket.</p>
                                @endif
                            </div>
                        </details>
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="mt-6 pt-4 border-t border-gray-700/70">
            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Related views</h3>
            <p class="text-[11px] text-gray-500 mb-2">Task-oriented shortcuts (read-only unless noted). Member links may require acting as the uploader.</p>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('intake.status', $intake) }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center px-3 py-1.5 rounded border border-teal-500/50 text-teal-100 text-xs font-semibold hover:bg-teal-600/15">Open member suggestion page →</a>
                @if ($govProfile)
                    <a href="{{ route('admin.profiles.show', $govProfile->id) }}" class="inline-flex items-center px-3 py-1.5 rounded border border-indigo-500/50 text-indigo-100 text-xs font-semibold hover:bg-indigo-600/15">Open attached profile →</a>
                    <a href="{{ route('admin.suggestions.review', $intake) }}" class="inline-flex items-center px-3 py-1.5 rounded border border-violet-500/50 text-violet-100 text-xs font-semibold hover:bg-violet-600/15">Admin suggestion review →</a>
                @endif
                <a href="{{ route('admin.conflict-records.index') }}" class="inline-flex items-center px-3 py-1.5 rounded border border-amber-600/50 text-amber-100 text-xs font-semibold hover:bg-amber-600/10">Open pending conflicts@if ($govPendingConflicts > 0) ({{ $govPendingConflicts }})@endif →</a>
                <a href="{{ route('admin.governance-dashboard') }}" class="inline-flex items-center px-3 py-1.5 rounded border border-gray-600 text-gray-200 text-xs font-semibold hover:bg-gray-700/50">Open governance dashboard →</a>
            </div>
        </div>
    </div>

    <div class="bg-gray-900/40 border border-gray-700 rounded-xl p-5 mb-6">
        <h4 class="text-sm font-semibold text-gray-100 mb-2">Parse &amp; Extraction Diagnostics</h4>
        @if (! empty($diagnosticsUnavailableReason ?? null))
            <p class="text-xs text-amber-200">{{ $diagnosticsUnavailableReason }}</p>
            <div class="mt-3 flex flex-wrap gap-2">
                <form method="POST" action="{{ route('admin.biodata-intakes.reparse', $intake) }}">
                    @csrf
                    <button type="submit" class="btn btn-warning" title="Parse only; reuses cached or fingerprint/historical text — no new vision API call.">
                        Re-parse (no new AI extraction)
                    </button>
                </form>

                @if (! empty($showAdminReextractAction))
                    <form method="POST"
                          action="{{ route('admin.biodata-intakes.re-extract', $intake) }}"
                          onsubmit="return confirm('Run paid vision extraction again for this intake?');">
                        @csrf
                        <button type="submit" class="btn btn-secondary">
                            Re-extract (vision again)
                        </button>
                    </form>
                @endif
            </div>
            <p class="mt-2 text-xs text-gray-400">
                This page will not guess provider/source when debug metadata is missing. Use the shortcuts above to regenerate diagnostics.
            </p>
        @else
            @php
                $s = $diagnostics['summary'] ?? [];
                $row = function (string $label, $value) {
                    $v = is_bool($value) ? ($value ? 'Yes' : 'No') : (trim((string) $value) !== '' ? (string) $value : '—');
                    return [$label, $v];
                };
                $rows = [
                    $row('Parser mode', $s['parser_mode_label'] ?? null),
                    $row('Parser version (intake.parser_version)', $intake->parser_version ?? '—'),
                    $row('Active parser mode (resolved)', $mode ?? '—'),
                    $row('AI provider', $s['ai_provider_label'] ?? null),
                    $row('Autofill / parse input source', $s['autofill_source_label'] ?? null),
                    $row('Transcript used', $s['transcript_used_label'] ?? null),
                    $row('Extraction reused', $meta['parse_input_extraction_reused'] ?? null),
                    $row('Reused from (reason)', $meta['parse_input_extraction_reused_from'] ?? null),
                    $row('Reused source intake id', $meta['parse_input_reused_source_intake_id'] ?? null),
                    $row('Paid extraction API called', $meta['parse_input_paid_extraction_api_called'] ?? null),
                    $row('Re-parse parse-input-only', $meta['parse_input_parse_input_only_job'] ?? null),
                    $row('Provider source', $meta['parse_input_provider_source'] ?? null),
                    $row('Transcript source field', $meta['parse_input_source_field'] ?? null),
                    $row('Quality (ok)', $meta['parse_input_text_quality_ok'] ?? null),
                    $row('Quality (chars / lines)', ($meta['parse_input_text_chars'] ?? '—').' / '.($meta['parse_input_text_lines'] ?? '—')),
                    $row('Internal parse_input_source code', $s['internal_parse_input_source'] ?? null),
                ];
            @endphp

            <dl class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-2 text-sm">
                @foreach ($rows as [$k, $v])
                    <div class="flex items-baseline gap-2">
                        <dt class="text-xs font-semibold text-gray-400 uppercase tracking-wide w-48 shrink-0">{{ $k }}</dt>
                        <dd class="text-gray-100 break-words">{{ $v }}</dd>
                    </div>
                @endforeach
            </dl>

            @if (! empty($diagnostics['technical_note'] ?? null))
                <p class="mt-3 text-xs text-gray-400">{{ $diagnostics['technical_note'] }}</p>
            @endif
        @endif
    </div>

    <div class="bg-gray-800/70 border border-gray-700 rounded-lg p-4 mb-6">
        <details>
            <summary class="cursor-pointer text-sm font-semibold text-gray-100">
                Technical Details (Internal)
                <span class="text-xs font-normal text-gray-400 ml-2">Show internal diagnostics</span>
            </summary>

            @php
                $dbg = is_array($dbg ?? null) ? $dbg : [];
                $oq = is_array($ocrQuality ?? null) ? $ocrQuality : [];
                $get = function (array $a, string $k) {
                    if (! array_key_exists($k, $a)) {
                        return '—';
                    }
                    $v = $a[$k];
                    if (is_bool($v)) {
                        return $v ? 'true' : 'false';
                    }
                    if ($v === null) {
                        return '—';
                    }
                    $s = trim((string) $v);
                    return $s !== '' ? $s : '—';
                };
                $techRows = [
                    ['parse_input_source', $get($dbg, 'parse_input_source')],
                    ['provider', $get($dbg, 'provider')],
                    ['provider_source', $get($dbg, 'provider_source')],
                    ['paid_extraction_api_called', $get($dbg, 'paid_extraction_api_called')],
                    ['extraction_reused', $get($dbg, 'extraction_reused')],
                    ['reused_from', $get($dbg, 'extraction_reused_from')],
                    ['reused_source_intake_id', $get($dbg, 'reused_source_intake_id')],
                    ['source_field', $get($dbg, 'source_field')],
                    ['text_provenance', $get($dbg, 'text_provenance')],
                    ['model', $get($dbg, 'model')],
                    ['reason', $get($dbg, 'reason')],
                    ['quality_reason', $get($dbg, 'text_quality_reason')],
                    ['ocr_quality.score', $get($oq, 'score')],
                    ['text_chars', $get($dbg, 'text_chars')],
                    ['text_lines', $get($dbg, 'text_lines')],
                    ['text_alpha_ratio', $get($dbg, 'text_alpha_ratio')],
                ];
            @endphp

            <div class="mt-3">
                <pre class="bg-black/40 p-3 rounded overflow-auto max-h-80 text-xs whitespace-pre-wrap text-gray-100">@foreach ($techRows as [$k, $v]){{ $k }}: {{ $v }}
@endforeach</pre>
            </div>
        </details>
    </div>

    <div class="bg-gray-800/70 border border-gray-700 rounded-lg p-4 mb-6">
        <h4 class="text-sm font-semibold text-gray-100 mb-2">{{ __('intake.admin_parse_input_heading') }}</h4>
        <p class="text-xs text-gray-400 mb-2">{{ __('intake.admin_parse_input_subtitle') }}</p>
        <pre class="bg-black/40 p-3 rounded overflow-auto max-h-96 text-xs whitespace-pre-wrap text-gray-100">{{ $reviewParse['text'] !== '' ? $reviewParse['text'] : '(empty)' }}</pre>
    </div>

    @if (config('app.debug') && config('intake.debug_show_stored_raw_ocr'))
        <div class="bg-gray-900/80 border border-dashed border-amber-700 rounded-lg p-4 mb-6">
            <h4 class="text-sm font-semibold text-amber-200 mb-2">{{ __('intake.debug_stored_raw_ocr_heading') }}</h4>
            <pre class="bg-black/40 p-3 rounded overflow-auto max-h-48 text-xs whitespace-pre-wrap text-gray-200">{{ $intake->raw_ocr_text ?? '(empty)' }}</pre>
        </div>
    @endif

    <div class="bg-gray-800/70 border border-gray-700 rounded-lg p-4">
        <h4 class="text-sm font-semibold text-gray-100 mb-2">Parsed JSON</h4>
        <pre class="bg-black/40 p-3 rounded overflow-auto max-h-[32rem] text-xs text-gray-100">{{ json_encode($intake->parsed_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </div>
</div>

<script>
(function () {
    var resolveUrl = @json(route('admin.biodata-intakes.resolve-location', $intake));
    var locationSearchApiUrl = @json(url('/api/internal/location/search'));
    function bindResolveButtons(root) {
    root.querySelectorAll('.admin-intake-loc-resolve-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var field = btn.getAttribute('data-field');
            var cityId = btn.getAttribute('data-city-id');
            if (!field || !cityId) return;
            root.querySelectorAll('.admin-intake-loc-resolve-btn').forEach(function (other) {
                other.classList.remove('ring-2', 'ring-emerald-500', 'bg-emerald-900/20');
            });
            btn.classList.add('ring-2', 'ring-emerald-500', 'bg-emerald-900/20');
            btn.disabled = true;
            fetch(resolveUrl, {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                },
                body: JSON.stringify({ field: field, city_id: parseInt(cityId, 10) })
            }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, data: j }; }); })
                .then(function (res) {
                    if (res.ok && res.data && res.data.success) {
                        window.location.reload();
                    } else {
                        btn.disabled = false;
                        window.alert((res.data && res.data.message) ? res.data.message : 'Could not resolve this location.');
                    }
                }).catch(function () {
                    btn.disabled = false;
                    window.alert('Network error while resolving location.');
                });
        });
    });
    }

    document.querySelectorAll('.admin-intake-loc-search-btn').forEach(function (searchBtn) {
        searchBtn.addEventListener('click', function () {
            var card = searchBtn.closest('.rounded-lg');
            if (!card) return;
            var input = card.querySelector('.admin-intake-loc-search-input');
            var list = card.querySelector('.admin-intake-loc-options-list');
            var empty = card.querySelector('.admin-intake-loc-empty-note');
            var seedBtn = card.querySelector('.admin-intake-loc-resolve-btn');
            var field = seedBtn ? seedBtn.getAttribute('data-field') : '';
            var q = input ? String(input.value || '').trim() : '';
            if (!q || !field) return;
            var originalSearchLabel = searchBtn.textContent;
            searchBtn.disabled = true;
            searchBtn.textContent = 'Searching...';
            fetch(locationSearchApiUrl + '?q=' + encodeURIComponent(q), {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) { return r.json(); })
                .then(function (data) {
                    var results = Array.isArray(data && data.results) ? data.results.slice(0, 10) : [];
                    if (!list) {
                        list = document.createElement('div');
                        list.className = 'grid grid-cols-1 md:grid-cols-2 gap-2 admin-intake-loc-options-list';
                        card.appendChild(list);
                    }
                    list.innerHTML = '';
                    if (results.length === 0) {
                        if (!empty) {
                            empty = document.createElement('p');
                            empty.className = 'text-xs text-gray-300 admin-intake-loc-empty-note';
                            card.appendChild(empty);
                        }
                        empty.textContent = 'ही जागा सापडली नाही. नवीन म्हणून सबमिट करा';
                        return;
                    }
                    if (empty) empty.textContent = '';
                    results.forEach(function (opt) {
                        var b = document.createElement('button');
                        b.type = 'button';
                        b.className = 'admin-intake-loc-resolve-btn text-left px-3 py-2 rounded border border-gray-600 hover:bg-gray-800 text-xs text-gray-100';
                        b.setAttribute('data-field', field);
                        b.setAttribute('data-city-id', String(opt.city_id || ''));
                        b.textContent = String(opt.display_label || opt.name || opt.city_name || '—');
                        list.appendChild(b);
                    });
                    bindResolveButtons(card);
                }).finally(function () {
                    searchBtn.disabled = false;
                    searchBtn.textContent = originalSearchLabel;
                });
        });
    });
    bindResolveButtons(document);
})();
</script>
@endsection

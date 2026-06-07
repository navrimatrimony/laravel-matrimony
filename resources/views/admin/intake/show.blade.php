@extends('layouts.admin')

@section('content')
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
    $govProfile = $attachedProfile ?? $intake->profile;
    $govPendingConflicts = (int) ($pendingConflictCount ?? 0);
    $govSuggestionsPresent = (bool) ($pendingSuggestionsPresent ?? false);
    $govSuggestionsCount = (int) ($pendingSuggestionsCount ?? 0);
    $govRequireAdmin = (bool) ($requireAdminBeforeAttach ?? false);
    $govReadiness = $applyReadiness ?? [];
    $mutationResult = session('mutation_result');
    $mutationResult = is_array($mutationResult) ? $mutationResult : null;
    $sugSum = $pendingSuggestionsAdminSummary ?? ['has_any' => false, 'non_empty_bucket_count' => 0, 'buckets' => [], 'review_strip' => []];
    $sugBuckets = is_array($sugSum['buckets'] ?? null) ? $sugSum['buckets'] : [];
    $reviewStrip = is_array($sugSum['review_strip'] ?? null) ? $sugSum['review_strip'] : [];
    $card = 'bg-white border border-gray-200 rounded-xl shadow-sm';
    $cardTitle = 'text-sm font-semibold text-gray-900';
    $cardHint = 'text-xs text-gray-500';
    $label = 'text-xs font-semibold text-gray-500 uppercase tracking-wide';
    $value = 'text-sm text-gray-900';
@endphp

<div class="max-w-6xl mx-auto text-gray-900" x-data="{ tab: 'review' }">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Intake #{{ $intake->id }}</h1>
            <p class="text-gray-600 text-sm mt-1">Review parsed biodata — start with the <strong class="font-semibold text-indigo-700">Parse review</strong> tab to spot mistakes.</p>
        </div>
        <a href="{{ route('admin.biodata-intakes.index') }}" class="text-sm text-indigo-700 hover:text-indigo-900 underline">← Back to intakes</a>
    </div>

    @if (session('success'))
        <div class="mb-3 px-4 py-2 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-3 px-4 py-2 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm">{{ session('error') }}</div>
    @endif
    @if (session('warning'))
        <div class="mb-3 px-4 py-2 rounded-lg bg-amber-50 border border-amber-200 text-amber-900 text-sm">{{ session('warning') }}</div>
    @endif
    @if ($errors->any())
        <div class="mb-3 px-4 py-2 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm">
            <ul class="list-disc list-inside">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    {{-- Compact status strip --}}
    <div class="{{ $card }} p-4 mb-4 flex flex-wrap items-center gap-3 text-sm">
        @php
            $parse = (string) ($intake->parse_status ?? '');
            $parseChip = $parse === 'parsed'
                ? 'bg-emerald-50 text-emerald-800 border-emerald-200'
                : ($parse === 'error' ? 'bg-red-50 text-red-800 border-red-200' : 'bg-gray-100 text-gray-700 border-gray-200');
        @endphp
        <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold border border-gray-200 bg-gray-50">{{ $intake->intake_status }}</span>
        <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold border {{ $parseChip }}">{{ $parse !== '' ? $parse : '—' }}</span>
        <span class="text-gray-600">Owner: <strong class="text-gray-900">{{ $intake->uploadedByUser->name ?? '—' }}</strong></span>
        @if ($intake->profile)
            <span class="text-gray-600">Profile: <strong class="text-gray-900">#{{ $intake->profile->id }}</strong> {{ $intake->profile->full_name }}</span>
        @else
            <span class="text-amber-700 text-xs font-medium">Not attached to profile</span>
        @endif
    </div>

    @include('admin.intake.show._tab-nav')

    {{-- TAB: Parse review --}}
    <div x-show="tab === 'review'" x-cloak class="space-y-6">
        @include('intake.partials.normalized-draft-preview', [
            'normalizedDraftPreview' => $normalizedDraftPreview ?? null,
            'intakePhotoPreview' => $intakePhotoPreview ?? null,
            'adminLightMode' => true,
            'hideEmptySections' => true,
            'draftCorrectionApplyEnabled' => ! $intake->approved_by_user && ! $intake->intake_locked,
            'draftCorrectionApplyRoute' => route('admin.biodata-intakes.apply-draft-correction', $intake),
        ])

        @if (! empty($unresolvedLocationOptions) && is_array($unresolvedLocationOptions))
            <div class="rounded-xl border border-amber-300 bg-amber-50 p-5">
                <h2 class="{{ $cardTitle }} text-amber-900 mb-1">Resolve locations</h2>
                <p class="{{ $cardHint }} text-amber-800 mb-3">Parsed place names without a matched location ID — pick the correct match.</p>
                <div class="space-y-4">
                    @foreach ($unresolvedLocationOptions as $loc)
                        @php $opts = is_array($loc['options'] ?? null) ? $loc['options'] : []; @endphp
                        <div class="rounded-lg border border-amber-200 bg-white p-3">
                            <div class="{{ $label }} mb-1">{{ $loc['label'] ?? ($loc['field_key'] ?? 'Location') }}</div>
                            @if (! empty($loc['raw_input']))
                                <p class="text-sm text-gray-900 mb-2">From biodata: <strong>"{{ $loc['raw_input'] }}"</strong></p>
                            @endif
                            @if ($opts !== [])
                                <div class="space-y-2 admin-intake-loc-options-list">
                                    @foreach ($opts as $opt)
                                        <div class="flex flex-wrap items-center gap-2 text-xs rounded-md border border-indigo-200 bg-indigo-50 px-2 py-1.5">
                                            <span class="font-semibold text-gray-900">{{ $opt['display_label'] ?? $opt['name'] ?? $opt['city_name'] ?? '—' }}</span>
                                            <button type="button" class="admin-intake-loc-apply-btn px-2 py-0.5 rounded bg-indigo-600 hover:bg-indigo-700 text-white text-[11px] font-semibold"
                                                data-field="{{ $loc['field_key'] ?? '' }}" data-city-id="{{ $opt['city_id'] ?? '' }}">{{ __('intake.parse_suggestion_apply') }}</button>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <p class="text-xs text-gray-600">No quick matches.</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    {{-- TAB: Source & file --}}
    <div x-show="tab === 'source'" x-cloak class="space-y-6">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="{{ $card }} p-5">
                <h2 class="{{ $cardTitle }} mb-1">Uploaded file</h2>
                <p class="{{ $cardHint }} mb-3">{{ $display }}</p>
                <dl class="space-y-3 text-sm">
                    <div><dt class="{{ $label }}">Uploaded</dt><dd class="{{ $value }} mt-0.5">{{ $uploadedAt ? $uploadedAt->toDateTimeString() : '—' }}</dd></div>
                    @if ($fp !== '')
                        <div><dt class="{{ $label }}">Stored path</dt><dd class="font-mono text-xs text-gray-700 break-all mt-0.5">{{ $fp }}</dd></div>
                    @endif
                </dl>
                <div class="mt-4 flex flex-wrap gap-2">
                    @if ($openUrl)
                        <a href="{{ $openUrl }}" target="_blank" class="inline-flex px-3 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold">Open biodata image →</a>
                        <button type="button" class="inline-flex px-3 py-2 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-xs font-semibold text-gray-800"
                            onclick="document.getElementById('uploadPreviewDialog')?.showModal();">Preview</button>
                    @elseif ($isPdf)
                        <span class="text-xs text-gray-500">PDF preview not available here.</span>
                    @else
                        <span class="text-xs text-gray-500">No file preview for this type.</span>
                    @endif
                </div>
            </div>
            <div class="{{ $card }} p-5">
                <h2 class="{{ $cardTitle }} mb-1">Compare tip</h2>
                <p class="text-sm text-gray-700 leading-relaxed">Open the biodata image on the left, then compare it with the parse input text below. If the normalized draft looks wrong, check whether the parser received the expected text.</p>
                <a href="{{ route('intake.preview', $intake) }}" target="_blank" class="mt-3 inline-flex text-xs font-semibold text-indigo-700 hover:underline">User preview (side-by-side) →</a>
            </div>
        </div>

        <div class="{{ $card }} p-5">
            <h2 class="{{ $cardTitle }} mb-1">{{ __('intake.admin_parse_input_heading') }}</h2>
            <p class="{{ $cardHint }} mb-3">{{ __('intake.admin_parse_input_subtitle') }}</p>
            <pre class="bg-gray-50 border border-gray-200 p-3 rounded-lg overflow-auto max-h-[28rem] text-xs whitespace-pre-wrap text-gray-900 font-mono leading-relaxed">{{ $reviewParse['text'] !== '' ? $reviewParse['text'] : '(empty)' }}</pre>
        </div>
    </div>

    {{-- TAB: Actions & apply --}}
    <div x-show="tab === 'actions'" x-cloak class="space-y-6">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="{{ $card }} p-5 space-y-4">
                <div>
                    <h2 class="{{ $cardTitle }} mb-1">Parsing actions</h2>
                    <p class="{{ $cardHint }}">Same extracted text — re-runs rules/parser only.</p>
                </div>
                <form method="POST" action="{{ route('admin.biodata-intakes.reparse', $intake) }}">
                    @csrf
                    <button type="submit" class="w-full px-3 py-2.5 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-sm font-semibold text-gray-900">Re-parse (no new AI extraction)</button>
                </form>
                @if (! empty($showAdminReextractAction))
                    <form method="POST" action="{{ route('admin.biodata-intakes.re-extract', $intake) }}" onsubmit="return confirm('Run paid vision extraction again?');">
                        @csrf
                        <button type="submit" class="w-full px-3 py-2.5 rounded-lg bg-amber-500 hover:bg-amber-600 text-sm font-semibold text-gray-900">Re-extract (vision again)</button>
                        <p class="mt-1 text-[11px] text-amber-800">Paid API — re-reads text from the uploaded file.</p>
                    </form>
                @endif
            </div>

            <div class="{{ $card }} p-5">
                <h2 class="{{ $cardTitle }} mb-1">Apply to profile</h2>
                @php
                    $ready = (bool) ($govReadiness['can_admin_apply'] ?? false);
                    $missing = [];
                    if (! ($govReadiness['user_approved'] ?? false)) { $missing[] = 'user approval'; }
                    if (! ($govReadiness['attached_profile'] ?? false)) { $missing[] = 'attached profile'; }
                    if (! ($govReadiness['has_snapshot'] ?? false)) { $missing[] = 'approval snapshot'; }
                    if (! ($govReadiness['admin_required'] ?? false)) { $missing[] = 'admin apply mode in settings'; }
                @endphp
                <p class="text-sm mb-3 {{ $ready ? 'text-emerald-700' : 'text-amber-800' }}">
                    {{ $ready ? 'Ready to apply.' : 'Not ready: '.($missing === [] ? 'check intake state.' : implode(', ', $missing).'.') }}
                </p>
                <form method="POST" action="{{ route('admin.biodata-intakes.apply', $intake) }}">
                    @csrf
                    <button type="submit" class="w-full px-3 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-sm font-semibold text-white">Apply intake to profile</button>
                </form>
            </div>
        </div>

        <div class="{{ $card }} p-5">
            <h2 class="{{ $cardTitle }} mb-3">Governance checklist</h2>
            <ul class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
                <li class="flex items-center gap-2"><span class="{{ ! empty($govReadiness['user_approved']) ? 'text-emerald-600' : 'text-gray-400' }}">●</span> User approved</li>
                <li class="flex items-center gap-2"><span class="{{ ! empty($govReadiness['attached_profile']) ? 'text-emerald-600' : 'text-gray-400' }}">●</span> Profile attached</li>
                <li class="flex items-center gap-2"><span class="{{ ! empty($govReadiness['has_snapshot']) ? 'text-emerald-600' : 'text-gray-400' }}">●</span> Approval snapshot</li>
                <li class="flex items-center gap-2"><span class="{{ $govRequireAdmin ? 'text-emerald-600' : 'text-gray-400' }}">●</span> Admin apply mode enabled</li>
                <li class="flex items-center gap-2"><span class="{{ $govPendingConflicts === 0 ? 'text-emerald-600' : 'text-amber-600' }}">●</span> Pending conflicts: {{ $govPendingConflicts }}</li>
            </ul>
            @if ($govPendingConflicts > 0 && ! empty($recentPendingConflicts) && $recentPendingConflicts->isNotEmpty())
                <div class="mt-3 text-xs text-gray-600">
                    @foreach ($recentPendingConflicts as $rc)
                        <a href="{{ route('admin.conflict-records.show', $rc) }}" class="text-indigo-700 hover:underline mr-2">Conflict #{{ $rc->id }}</a>
                    @endforeach
                </div>
            @endif
            @if ($mutationResult)
                <div class="mt-4 pt-4 border-t border-gray-200 text-xs text-gray-700 space-y-1">
                    <div>Last apply — success: {{ ! empty($mutationResult['mutation_success']) ? 'yes' : 'no' }}, conflict: {{ ! empty($mutationResult['conflict_detected']) ? 'yes' : 'no' }}</div>
                </div>
            @endif
        </div>

        @if ($govSuggestionsPresent)
            <div class="{{ $card }} p-5">
                <h2 class="{{ $cardTitle }} mb-1">Pending suggestions on profile</h2>
                <p class="{{ $cardHint }} mb-3">{{ $govSuggestionsCount }} bucket(s) — open profile review for details.</p>
                <div class="flex flex-wrap gap-2">
                    @if ($govProfile)
                        <a href="{{ route('admin.suggestions.review', $intake) }}" class="text-xs font-semibold text-indigo-700 hover:underline">Admin suggestion review →</a>
                        <a href="{{ route('admin.profiles.show', $govProfile->id) }}" class="text-xs font-semibold text-indigo-700 hover:underline">Open profile →</a>
                    @endif
                    <a href="{{ route('intake.status', $intake) }}" target="_blank" class="text-xs font-semibold text-indigo-700 hover:underline">Member suggestion page →</a>
                </div>
            </div>
        @endif
    </div>

    {{-- TAB: Technical --}}
    <div x-show="tab === 'technical'" x-cloak class="space-y-6">
        <div class="{{ $card }} p-5">
            <h2 class="{{ $cardTitle }} mb-1">Parse &amp; extraction diagnostics</h2>
            @if (! empty($diagnosticsUnavailableReason ?? null))
                <p class="text-sm text-amber-800 mb-2">{{ $diagnosticsUnavailableReason }}</p>
                <p class="text-xs text-gray-500">Run Re-parse to refresh debug metadata.</p>
            @else
                @php
                    $s = $diagnostics['summary'] ?? [];
                    $row = fn (string $label, $value) => [$label, is_bool($value) ? ($value ? 'Yes' : 'No') : (trim((string) $value) !== '' ? (string) $value : '—')];
                    $rows = [
                        $row('Parser mode', $s['parser_mode_label'] ?? null),
                        $row('Parser version', $intake->parser_version ?? '—'),
                        $row('AI provider', $s['ai_provider_label'] ?? null),
                        $row('Parse input source', $s['autofill_source_label'] ?? null),
                        $row('Transcript used', $s['transcript_used_label'] ?? null),
                        $row('Quality ok', $meta['parse_input_text_quality_ok'] ?? null),
                        $row('Chars / lines', ($meta['parse_input_text_chars'] ?? '—').' / '.($meta['parse_input_text_lines'] ?? '—')),
                    ];
                @endphp
                <dl class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-2 text-sm mt-2">
                    @foreach ($rows as [$k, $v])
                        <div><dt class="{{ $label }}">{{ $k }}</dt><dd class="{{ $value }} mt-0.5 break-words">{{ $v }}</dd></div>
                    @endforeach
                </dl>
            @endif
        </div>

        <details class="{{ $card }} p-5">
            <summary class="{{ $cardTitle }} cursor-pointer select-none">Technical details (internal codes)</summary>
            @php
                $dbgArr = is_array($dbg ?? null) ? $dbg : [];
                $oq = is_array($ocrQuality ?? null) ? $ocrQuality : [];
                $get = function (array $a, string $k) {
                    if (! array_key_exists($k, $a)) { return '—'; }
                    $v = $a[$k];
                    if (is_bool($v)) { return $v ? 'true' : 'false'; }
                    if ($v === null) { return '—'; }
                    $s = trim((string) $v);
                    return $s !== '' ? $s : '—';
                };
            @endphp
            <pre class="mt-3 bg-gray-50 border border-gray-200 p-3 rounded-lg overflow-auto max-h-64 text-xs text-gray-800 font-mono">parse_input_source: {{ $get($dbgArr, 'parse_input_source') }}
provider: {{ $get($dbgArr, 'provider') }}
model: {{ $get($dbgArr, 'model') }}
ocr_quality.score: {{ $get($oq, 'score') }}</pre>
        </details>

        <details class="{{ $card }} p-5">
            <summary class="{{ $cardTitle }} cursor-pointer select-none">Parsed JSON (stored snapshot)</summary>
            <pre class="mt-3 bg-gray-50 border border-gray-200 p-3 rounded-lg overflow-auto max-h-[32rem] text-xs text-gray-800 font-mono">{{ json_encode($intake->parsed_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </details>

        @if (config('app.debug') && config('intake.debug_show_stored_raw_ocr'))
            <details class="{{ $card }} p-5 border-dashed border-amber-300">
                <summary class="{{ $cardTitle }} cursor-pointer text-amber-900">Debug: stored raw OCR</summary>
                <pre class="mt-3 bg-gray-50 border border-gray-200 p-3 rounded-lg overflow-auto max-h-48 text-xs font-mono">{{ $intake->raw_ocr_text ?? '(empty)' }}</pre>
            </details>
        @endif
    </div>

    @if ($openUrl)
        <dialog id="uploadPreviewDialog" class="backdrop:bg-black/50 rounded-lg p-0 w-[min(900px,95vw)]">
            <div class="bg-white border border-gray-200 rounded-lg">
                <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200">
                    <div class="text-sm font-semibold text-gray-900 truncate">{{ $display }}</div>
                    <button type="button" class="text-xs font-semibold text-gray-600 hover:text-gray-900" onclick="document.getElementById('uploadPreviewDialog')?.close();">Close</button>
                </div>
                <div class="p-3 bg-gray-50">
                    <img src="{{ $openUrl }}" alt="Biodata preview" class="max-h-[75vh] w-auto mx-auto rounded">
                </div>
            </div>
        </dialog>
    @endif
</div>

<style>[x-cloak]{display:none!important}</style>

<script>
(function () {
    var resolveUrl = @json(route('admin.biodata-intakes.resolve-location', $intake));
    document.querySelectorAll('.admin-intake-loc-apply-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var field = btn.getAttribute('data-field');
            var cityId = btn.getAttribute('data-city-id');
            if (!field || !cityId) return;
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
                    if (res.ok && res.data && res.data.success) { window.location.reload(); }
                    else { btn.disabled = false; window.alert((res.data && res.data.message) ? res.data.message : 'Could not resolve.'); }
                }).catch(function () { btn.disabled = false; window.alert('Network error.'); });
        });
    });
})();
</script>
@endsection

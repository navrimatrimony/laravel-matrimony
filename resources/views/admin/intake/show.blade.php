@extends('layouts.admin')

@section('content')
<div class="max-w-5xl mx-auto">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-100">Admin Intake Page</h1>
            <p class="text-gray-400 text-sm">Phase-5 Admin Intake Sandbox</p>
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
@endsection

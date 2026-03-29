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

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-gray-800/70 border border-gray-700 rounded-lg p-4 space-y-1">
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Intake ID</p>
            <p class="text-sm text-gray-100">#{{ $intake->id }}</p>
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mt-3">Status</p>
            <p class="text-sm text-gray-100">
                Intake: <span class="font-semibold">{{ $intake->intake_status }}</span><br>
                Parse: <span class="font-semibold">{{ $intake->parse_status }}</span>
            </p>
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mt-3">Owner</p>
            <p class="text-sm text-gray-100">
                {{ $intake->uploadedByUser->name ?? '—' }}<br>
                <span class="text-gray-400">{{ $intake->uploadedByUser->email ?? '' }}</span>
            </p>
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mt-3">Attached profile</p>
            <p class="text-sm text-gray-100">
                @if ($intake->profile)
                    #{{ $intake->profile->id }} — {{ $intake->profile->full_name }}
                @else
                    <span class="text-gray-400">Not attached</span>
                @endif
            </p>
        </div>
        <div class="bg-gray-800/70 border border-gray-700 rounded-lg p-4 space-y-2 md:col-span-2">
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Admin actions</p>
            <div class="flex flex-wrap gap-2">
                <form method="POST"
                      action="{{ route('admin.biodata-intakes.reparse', $intake) }}">
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

                <form method="POST" action="{{ route('admin.biodata-intakes.apply', $intake) }}">
                    @csrf
                    <button type="submit" class="inline-flex items-center px-3 py-1.5 rounded bg-emerald-600 hover:bg-emerald-700 text-xs font-semibold text-white">
                        Apply intake to profile
                    </button>
                </form>

                <a href="{{ route('intake.preview', $intake) }}" target="_blank" class="inline-flex items-center px-3 py-1.5 rounded bg-gray-700 hover:bg-gray-600 text-xs font-semibold text-gray-100">
                    Open user preview →
                </a>
            </div>
            <p class="text-xs text-gray-400 mt-2">
                Apply action is effective only when user has approved the intake and admin approval is required in settings.
            </p>
        </div>
    </div>

    <div class="bg-gray-800/70 border border-gray-700 rounded-lg p-4 mb-6">
        <h4 class="text-sm font-semibold text-gray-100 mb-2">Raw OCR text <span class="text-xs font-normal text-gray-400">(what was extracted from PDF/image)</span></h4>
        <pre class="bg-black/40 p-3 rounded overflow-auto max-h-96 text-xs whitespace-pre-wrap text-gray-100">{{ $intake->raw_ocr_text ?? '(empty)' }}</pre>
    </div>

    <div class="bg-gray-800/70 border border-gray-700 rounded-lg p-4">
        <h4 class="text-sm font-semibold text-gray-100 mb-2">Parsed JSON</h4>
        <pre class="bg-black/40 p-3 rounded overflow-auto max-h-[32rem] text-xs text-gray-100">{{ json_encode($intake->parsed_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </div>
</div>
@endsection

@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <div class="flex justify-between items-center mb-6">
        <div>
            <a href="{{ route('admin.biodata-intakes.index') }}" class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 mb-2 inline-block">← Biodata intakes</a>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Intake #{{ $intake->id }} — Sandbox</h1>
        </div>
        <div class="flex items-center gap-3">
            <span class="px-2.5 py-1 rounded text-xs font-medium
                @if ($intake->intake_status === 'DRAFT') bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300
                @elseif ($intake->intake_status === 'ATTACHED') bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300
                @else bg-gray-200 text-gray-700 dark:bg-gray-600 dark:text-gray-300
                @endif">{{ $intake->intake_status ?? 'DRAFT' }}</span>
            <span class="text-sm text-gray-500 dark:text-gray-400">Read-only</span>
        </div>
    </div>

    {{-- Day-4: Clear warning banner --}}
    <div class="mb-6 p-4 rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20">
        <p class="text-sm font-medium text-red-800 dark:text-red-200">⚠️ NO PROFILE DATA IS MODIFIED</p>
        <p class="text-xs text-red-600 dark:text-red-400 mt-0.5">This is a sandbox view. Profile is NOT modified. No parsing. No data transfer.</p>
    </div>

    @if (session('success'))
        <div class="mb-4 p-3 rounded-lg bg-emerald-100 dark:bg-emerald-900/30 text-emerald-800 dark:text-emerald-200 text-sm">{{ session('success') }}</div>
    @endif

    @if ($errors->any())
        <div class="mb-4 px-4 py-3 rounded-lg bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-200 text-sm">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="space-y-4">
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
            <div>
                <dt class="font-medium text-gray-500 dark:text-gray-400">File path / reference</dt>
                <dd class="mt-0.5 text-gray-900 dark:text-gray-100 font-mono break-all">{{ $intake->file_path ?? '—' }}</dd>
            </div>
            <div>
                <dt class="font-medium text-gray-500 dark:text-gray-400">Original filename</dt>
                <dd class="mt-0.5 text-gray-900 dark:text-gray-100">{{ $intake->original_filename ?? '—' }}</dd>
            </div>
            <div>
                <dt class="font-medium text-gray-500 dark:text-gray-400">File type</dt>
                <dd class="mt-0.5 text-gray-900 dark:text-gray-100">{{ $intake->file_type ?? '—' }}</dd>
            </div>
            <div>
                <dt class="font-medium text-gray-500 dark:text-gray-400">OCR mode</dt>
                <dd class="mt-0.5 text-gray-900 dark:text-gray-100">{{ $intake->ocr_mode ?? '—' }}</dd>
            </div>
            <div>
                <dt class="font-medium text-gray-500 dark:text-gray-400">Uploaded by</dt>
                <dd class="mt-0.5 text-gray-900 dark:text-gray-100">
                    @if ($intake->uploadedByUser)
                        {{ $intake->uploadedByUser->name ?? $intake->uploadedByUser->email }} (ID: {{ $intake->uploaded_by }})
                    @else
                        —
                    @endif
                </dd>
            </div>
            <div>
                <dt class="font-medium text-gray-500 dark:text-gray-400">Matrimony profile</dt>
                <dd class="mt-0.5 text-gray-900 dark:text-gray-100">
                    @if ($intake->profile)
                        <a href="{{ route('admin.profiles.show', $intake->profile->id) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ $intake->profile->full_name }} (#{{ $intake->profile->id }})</a>
                    @else
                        —
                    @endif
                </dd>
            </div>
            <div>
                <dt class="font-medium text-gray-500 dark:text-gray-400">Created at</dt>
                <dd class="mt-0.5 text-gray-900 dark:text-gray-100">{{ $intake->created_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
            </div>
            <div>
                <dt class="font-medium text-gray-500 dark:text-gray-400">Updated at</dt>
                <dd class="mt-0.5 text-gray-900 dark:text-gray-100">{{ $intake->updated_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
            </div>
        </dl>

        {{-- Raw text display (read-only) --}}
        @if ($intake->raw_ocr_text !== null && $intake->raw_ocr_text !== '')
            <div class="border border-gray-200 dark:border-gray-600 rounded-lg p-4 bg-gray-50 dark:bg-gray-700/30">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Raw text (read-only)</h3>
                <pre class="text-xs text-gray-800 dark:text-gray-200 whitespace-pre-wrap break-words max-h-96 overflow-y-auto font-mono">{{ $intake->raw_ocr_text }}</pre>
            </div>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">No raw text stored (file reference only: {{ $intake->file_path ?? '—' }}).</p>
        @endif

        {{-- Attach form (only for DRAFT intakes) --}}
        @if (($intake->intake_status ?? 'DRAFT') === 'DRAFT' && !$intake->matrimony_profile_id)
            <div class="border border-gray-200 dark:border-gray-600 rounded-lg p-4 bg-gray-50 dark:bg-gray-700/30">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Attach to profile (reference only)</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Links this intake to an existing profile. No profile data is modified.</p>
                <form method="POST" action="{{ route('admin.biodata-intakes.attach', $intake) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    @method('PATCH')
                    <div>
                        <label for="matrimony_profile_id" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Profile ID</label>
                        <input type="number" name="matrimony_profile_id" id="matrimony_profile_id" value="{{ old('matrimony_profile_id') }}" min="1" required
                            class="rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 px-3 py-1.5 text-sm w-32">
                        @error('matrimony_profile_id')
                            <p class="text-xs text-red-600 dark:text-red-400 mt-0.5">{{ $message }}</p>
                        @enderror
                    </div>
                    <a href="{{ route('admin.profiles.index') }}" target="_blank" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline">Browse profiles</a>
                    <button type="submit" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded">Attach</button>
                </form>
            </div>
        @endif
    </div>
</div>
@endsection

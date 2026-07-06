@extends('layouts.admin')

@section('content')
@php
    $activeAdminProfileTab = 'bulk';
@endphp

<div class="mx-auto max-w-4xl space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Manual Transcript Rescue</h1>
            <p class="mt-1 text-sm text-gray-600">Bulk Intake #{{ $batch->id }}{{ $batch->batch_name ? ' · '.$batch->batch_name : '' }}</p>
        </div>
        <a href="{{ route('admin.bulk-intakes.show', $batch) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Back to bulk intake</a>
    </div>

    @include('admin.intake._tabs')

    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm font-medium text-amber-900">
        This does not overwrite raw OCR text. It stores an admin-provided transcript as parse input evidence.
    </div>

    <div class="rounded-lg bg-white p-6 shadow">
        <dl class="grid gap-4 text-sm md:grid-cols-2">
            <div>
                <dt class="font-semibold text-gray-700">Item</dt>
                <dd class="mt-1 text-gray-600">#{{ $item->item_sequence }} · {{ $item->item_status }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-700">Original filename</dt>
                <dd class="mt-1 text-gray-600">{{ $item->original_filename ?: ($intake->original_filename ?: '-') }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-700">Linked intake</dt>
                <dd class="mt-1 text-gray-600">#{{ $intake->id }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-700">Parse status</dt>
                <dd class="mt-1 text-gray-600">{{ $intake->parse_status ?: '-' }}</dd>
            </div>
            <div class="md:col-span-2">
                <dt class="font-semibold text-gray-700">Last error</dt>
                <dd class="mt-1 break-words text-gray-600">{{ $intake->last_error ?: '-' }}</dd>
            </div>
        </dl>
    </div>

    <div class="rounded-lg bg-white p-6 shadow">
        <form method="POST" action="{{ route('admin.bulk-intakes.items.manual-transcript.store', [$batch, $item]) }}" class="space-y-5">
            @csrf
            <div>
                <label for="transcript" class="block text-sm font-semibold text-gray-800">Manual transcript</label>
                <textarea id="transcript" name="transcript" rows="14" required minlength="20" maxlength="30000" class="mt-2 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('transcript') }}</textarea>
                @error('transcript')
                    <p class="mt-2 text-sm text-red-700">{{ $message }}</p>
                @enderror
            </div>

            <label class="flex items-start gap-2 text-sm text-gray-700">
                <input type="checkbox" name="queue_parse" value="1" class="mt-1 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" @checked(old('queue_parse', true))>
                <span>Queue free parse after saving</span>
            </label>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                    Save manual transcript
                </button>
                <a href="{{ route('admin.bulk-intakes.show', $batch) }}" class="text-sm font-medium text-gray-600 hover:text-gray-900">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection

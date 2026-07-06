@extends('layouts.admin')

@section('content')
@php
    $activeAdminProfileTab = 'bulk';
    $actor = auth()->user();
@endphp
<div class="mx-auto max-w-4xl space-y-6">
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">New Bulk Intake</h1>
            <p class="mt-1 text-sm text-gray-600">Bulk intake is staging only. These biodata records are not attached to an existing member.</p>
        </div>
        <a href="{{ route('admin.bulk-intakes.index') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Back to bulk intakes</a>
    </div>

    @include('admin.intake._tabs')

    @if ($errors->any())
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <p class="font-semibold">Please fix the following:</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.bulk-intakes.store') }}" enctype="multipart/form-data" class="space-y-6 rounded-xl bg-white p-6 shadow">
        @csrf

        <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
            Bulk intake is staging only. These biodata records are not attached to an existing member. Profiles will be created/applied later only after candidate consent.
        </div>

        <div>
            <p class="text-sm font-medium text-gray-700">Uploaded by</p>
            <p class="mt-1 text-sm text-gray-600">{{ $actor?->name ?? 'Admin' }}{{ $actor?->email ? ' · '.$actor->email : '' }}</p>
        </div>

        <div>
            <label for="batch_name" class="block text-sm font-medium text-gray-700">Batch name</label>
            <input id="batch_name" name="batch_name" type="text" value="{{ old('batch_name') }}" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Optional label">
        </div>

        <div class="border-t border-gray-200 pt-6">
            <label for="files" class="block text-sm font-medium text-gray-700">Biodata files</label>
            <input id="files" name="files[]" type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.txt,.doc,.docx" class="mt-1 block w-full rounded-lg border border-gray-300 bg-white p-2 text-sm">
            <p class="mt-1 text-xs text-gray-500">Each file becomes one bulk item and one biodata intake row if extraction succeeds.</p>
        </div>

        <div>
            <label for="raw_text" class="block text-sm font-medium text-gray-700">Raw text items</label>
            <textarea id="raw_text" name="raw_text" rows="10" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Paste one or more biodata texts. Separate items with ---INTAKE--- on its own line.">{{ old('raw_text') }}</textarea>
        </div>

        <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
            Bulk intake creates biodata_intakes only. It does not auto-parse, approve, apply, or create profiles.
        </div>

        <div class="flex justify-end">
            <button type="submit" class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500">
                Create bulk intake
            </button>
        </div>
    </form>
</div>
@endsection

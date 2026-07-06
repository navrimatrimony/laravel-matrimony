@extends('layouts.admin')

@section('content')
@php
    $activeAdminProfileTab = 'bulk';
@endphp
<div class="mx-auto max-w-3xl space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Assign Intake Owner</h1>
            <p class="mt-1 text-sm text-gray-600">Bulk Intake #{{ $batch->id }}{{ $batch->batch_name ? ' · '.$batch->batch_name : '' }}</p>
        </div>
        <a href="{{ route('admin.bulk-intakes.show', $batch) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Back to bulk intake</a>
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

    <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
        This only assigns the owner of the intake. It does not create, approve, claim, or apply a profile.
    </div>

    <div class="rounded-lg bg-white p-6 shadow">
        <dl class="grid gap-4 text-sm md:grid-cols-2">
            <div>
                <dt class="font-semibold text-gray-700">Item</dt>
                <dd class="mt-1 text-gray-600">#{{ $item->item_sequence }} · {{ $item->input_type }} · {{ $item->original_filename ?: '-' }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-700">Linked intake</dt>
                <dd class="mt-1 text-gray-600">#{{ $intake->id }} · parse: {{ $intake->parse_status }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-700">Owner state</dt>
                <dd class="mt-1 text-amber-700">Unclaimed / consent pending</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-700">Uploaded</dt>
                <dd class="mt-1 text-gray-600">{{ $intake->created_at?->format('d-m-Y H:i') ?? '-' }}</dd>
            </div>
        </dl>
    </div>

    <form method="POST" action="{{ route('admin.bulk-intakes.items.assign-owner.store', [$batch, $item]) }}" class="space-y-6 rounded-lg bg-white p-6 shadow">
        @csrf

        <div>
            <label for="owner_user_id" class="block text-sm font-medium text-gray-700">Existing member user ID</label>
            <input id="owner_user_id" name="owner_user_id" type="number" min="1" value="{{ old('owner_user_id') }}" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Enter non-admin member user ID">
            <p class="mt-1 text-xs text-gray-500">Existing non-admin member only. New user creation is not available in this phase.</p>
        </div>

        <div>
            <label for="consent_note" class="block text-sm font-medium text-gray-700">Consent note</label>
            <textarea id="consent_note" name="consent_note" rows="4" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Optional consent reference or admin note">{{ old('consent_note') }}</textarea>
        </div>

        <label class="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <input type="checkbox" name="consent_confirmed" value="1" class="mt-1 rounded border-amber-400 text-indigo-600 focus:ring-indigo-500" @checked(old('consent_confirmed'))>
            <span>I confirm this person has consented to this biodata being linked to their account.</span>
        </label>

        <div class="flex justify-end gap-3">
            <a href="{{ route('admin.bulk-intakes.show', $batch) }}" class="rounded-lg border border-gray-300 px-5 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">Cancel</a>
            <button type="submit" class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500">
                Assign owner
            </button>
        </div>
    </form>
</div>
@endsection

@extends('layouts.admin')

@section('content')
@php
    $activeAdminProfileTab = 'intake';
@endphp
<div class="mx-auto max-w-4xl space-y-6">
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Create Profile</h1>
            <p class="mt-1 text-sm text-gray-600">This creates an intake only. Profile creation or changes remain behind approval and MutationService.</p>
        </div>
        <a href="{{ route('admin.biodata-intakes.index') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Back to intakes</a>
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

    <form
        method="POST"
        action="{{ route('admin.biodata-intakes.store') }}"
        enctype="multipart/form-data"
        class="space-y-6 rounded-xl bg-white p-6 shadow"
        x-data="{ mode: @js(old('user_mode', 'existing')) }"
    >
        @csrf

        <fieldset>
            <legend class="text-sm font-semibold text-gray-900">Member account</legend>
            <div class="mt-3 flex flex-wrap gap-5">
                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                    <input type="radio" name="user_mode" value="existing" x-model="mode">
                    Existing user
                </label>
                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                    <input type="radio" name="user_mode" value="new" x-model="mode">
                    New registration
                </label>
            </div>
        </fieldset>

        <div x-show="mode === 'existing'">
            <label for="existing_user_id" class="block text-sm font-medium text-gray-700">Existing user ID</label>
            <input id="existing_user_id" name="existing_user_id" type="number" min="1" list="recent-members" value="{{ old('existing_user_id') }}" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Enter any member user ID">
            <datalist id="recent-members">
                @foreach ($users as $user)
                    <option value="{{ $user->id }}">{{ $user->name }} · {{ $user->mobile ?: ($user->email ?: 'no contact') }}</option>
                @endforeach
            </datalist>
            <p class="mt-1 text-xs text-gray-500">Any valid non-admin user ID is accepted. The latest 500 accounts appear as suggestions.</p>
        </div>

        <div x-show="mode === 'new'" x-cloak>
            @include('admin.intake._registration-fields', [
                'registrationHint' => 'Gender is recorded for the new member account. Governed profile data still comes from the reviewed intake snapshot.',
            ])
        </div>

        <div class="border-t border-gray-200 pt-6">
            <label for="raw_text" class="block text-sm font-medium text-gray-700">Biodata text</label>
            <textarea id="raw_text" name="raw_text" rows="8" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Paste biodata text here">{{ old('raw_text') }}</textarea>
        </div>

        <div class="text-center text-xs font-semibold uppercase tracking-wide text-gray-400">or</div>

        <div>
            <label for="file" class="block text-sm font-medium text-gray-700">Biodata file</label>
            <input id="file" name="file" type="file" accept=".pdf,.jpg,.jpeg,.png,.txt" class="mt-1 block w-full rounded-lg border border-gray-300 bg-white p-2 text-sm">
            <p class="mt-1 text-xs text-gray-500">PDF, JPG, PNG or TXT. Maximum request size: 20 MB; configured intake limits still apply.</p>
        </div>

        <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
            After upload: parse, preview, explicit approval, duplicate detection, and governed mutation use the existing intake pipeline.
        </div>

        <div class="flex justify-end">
            <button type="submit" class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500">
                Create intake
            </button>
        </div>
    </form>
</div>
@endsection

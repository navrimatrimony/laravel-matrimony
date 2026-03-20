@extends('layouts.app')

@section('content')
<div class="py-12">
    <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('Communication Preferences') }}</h1>
            <p class="mt-2 text-gray-600 dark:text-gray-400">
                {{ __('Choose when other users can unlock your contact details.') }}
            </p>
        </div>

        @if (session('status') === 'communication-updated')
            <div class="mb-6 p-4 bg-green-50 border border-green-200 text-green-800 rounded-lg">
                {{ __('Saved successfully.') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-6 p-4 bg-red-50 border border-red-200 text-red-800 rounded-lg">
                <div class="font-semibold mb-2">{{ __('Please fix the following:') }}</div>
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @php
            $currentMode = old('contact_unlock_mode', $profile->contact_unlock_mode ?? 'after_interest_accepted');
        @endphp

        <div class="bg-white dark:bg-gray-800 shadow-sm border border-gray-200 dark:border-gray-700 rounded-lg p-6">
            <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                {{ __('Contact unlock mode') }}
            </div>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                {{ __('This setting directly affects when the contact number can be shown to other profiles.') }}
            </p>
            <ul class="mt-4 space-y-2 text-sm text-gray-700 dark:text-gray-200">
                <li><span class="font-semibold">{{ __('after_interest_accepted') }}</span> — {{ __('Contact unlock happens after the interest is accepted.') }}</li>
                <li><span class="font-semibold">{{ __('never') }}</span> — {{ __('Contact details remain hidden (no unlock for other users).') }}</li>
                <li><span class="font-semibold">{{ __('admin_only') }}</span> — {{ __('Contact unlock is restricted (regular users will not see contacts).') }}</li>
            </ul>
        </div>

        <form method="POST" action="{{ route('user.settings.communication.update') }}" class="mt-6 space-y-6">
            @csrf

            <div class="bg-white dark:bg-gray-800 shadow-sm border border-gray-200 dark:border-gray-700 rounded-lg p-6">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    {{ __('Communication preference') }}
                </label>
                <select name="contact_unlock_mode"
                        class="mt-2 w-full rounded-md border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100"
                        required>
                    <option value="after_interest_accepted" {{ $currentMode === 'after_interest_accepted' ? 'selected' : '' }}>
                        {{ __('After interest accepted') }}
                    </option>
                    <option value="never" {{ $currentMode === 'never' ? 'selected' : '' }}>
                        {{ __('Never unlock contacts') }}
                    </option>
                    <option value="admin_only" {{ $currentMode === 'admin_only' ? 'selected' : '' }}>
                        {{ __('Admin only') }}
                    </option>
                </select>
            </div>

            <div class="flex items-center justify-end gap-3">
                <a href="{{ route('user.settings.index') }}"
                   class="px-4 py-2 rounded-md border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-900 transition">
                    {{ __('Back') }}
                </a>
                <button type="submit"
                        class="px-5 py-2 rounded-md bg-red-600 text-white hover:bg-red-700 transition font-medium">
                    {{ __('Save') }}
                </button>
            </div>
        </form>
    </div>
</div>
@endsection


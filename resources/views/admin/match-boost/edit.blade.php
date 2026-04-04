@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6 max-w-3xl">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">{{ __('match_boost.title') }}</h1>
    <p class="text-gray-500 dark:text-gray-400 text-sm mb-6">{{ __('match_boost.intro') }}</p>

    @if (session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <ul class="text-red-600 text-sm mb-4 space-y-1">
            @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
    @endif

    <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
        <p class="font-semibold mb-1">{{ __('match_boost.api_note_title') }}</p>
        <p>{{ __('match_boost.api_note_body') }}</p>
        <p class="mt-2 font-mono text-xs">SARVAM_API_SUBSCRIPTION_KEY</p>
    </div>

    <form method="POST" action="{{ route('admin.match-boost.update') }}" class="space-y-6">
        @csrf
        @method('PUT')

        <fieldset class="space-y-3 rounded-lg border border-gray-200 dark:border-gray-600 p-4">
            <legend class="px-1 text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('match_boost.ai_section') }}</legend>
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="hidden" name="use_ai" value="0" />
                <input type="checkbox" name="use_ai" value="1" class="rounded border-gray-300" @checked((string) old('use_ai', $settings->use_ai ? '1' : '0') === '1') />
                <span class="text-sm text-gray-700 dark:text-gray-300">{{ __('match_boost.use_ai') }}</span>
            </label>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('match_boost.ai_provider') }}</label>
                <select name="ai_provider" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                    <option value="">{{ __('match_boost.ai_provider_none') }}</option>
                    <option value="sarvam" @selected(old('ai_provider', $settings->ai_provider) === 'sarvam')>Sarvam</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('match_boost.ai_model') }}</label>
                <input type="text" name="ai_model" value="{{ old('ai_model', $settings->ai_model) }}" placeholder="sarvam-105b"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
            </div>
        </fieldset>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('match_boost.boost_active_weight') }}</label>
                <input type="number" name="boost_active_weight" value="{{ old('boost_active_weight', $settings->boost_active_weight) }}" required min="0" max="100"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                <p class="text-xs text-gray-500 mt-1">{{ __('match_boost.hint_active') }}</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('match_boost.active_within_days') }}</label>
                <input type="number" name="active_within_days" value="{{ old('active_within_days', $settings->active_within_days) }}" required min="1" max="365"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('match_boost.boost_premium_weight') }}</label>
                <input type="number" name="boost_premium_weight" value="{{ old('boost_premium_weight', $settings->boost_premium_weight) }}" required min="0" max="100"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                <p class="text-xs text-gray-500 mt-1">{{ __('match_boost.hint_premium') }}</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('match_boost.boost_similarity_weight') }}</label>
                <input type="number" name="boost_similarity_weight" value="{{ old('boost_similarity_weight', $settings->boost_similarity_weight) }}" required min="0" max="100"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                <p class="text-xs text-gray-500 mt-1">{{ __('match_boost.hint_similarity') }}</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('match_boost.boost_gold_extra') }}</label>
                <input type="number" name="boost_gold_extra" value="{{ old('boost_gold_extra', $settings->boost_gold_extra) }}" required min="0" max="100"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('match_boost.boost_silver_extra') }}</label>
                <input type="number" name="boost_silver_extra" value="{{ old('boost_silver_extra', $settings->boost_silver_extra) }}" required min="0" max="100"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
            </div>
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('match_boost.max_boost_limit') }}</label>
                <input type="number" name="max_boost_limit" value="{{ old('max_boost_limit', $settings->max_boost_limit) }}" required min="0" max="100"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                <p class="text-xs text-gray-500 mt-1">{{ __('match_boost.hint_max') }}</p>
            </div>
        </div>

        <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg">
            {{ __('match_boost.save') }}
        </button>
    </form>
</div>
@endsection

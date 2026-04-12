@extends('layouts.admin')

@section('content')
@php
    $rv = $runtimeRow?->config_value ?? [];
    $persistMode = 'default';
    if (array_key_exists('persist_cache', $rv) && $rv['persist_cache'] === true) {
        $persistMode = 'yes';
    } elseif (array_key_exists('persist_cache', $rv) && $rv['persist_cache'] === false) {
        $persistMode = 'no';
    }
@endphp
<div class="space-y-8">
    <div class="rounded-2xl border border-gray-200 dark:border-gray-700 bg-gradient-to-br from-rose-50 via-white to-indigo-50 dark:from-gray-900 dark:via-gray-800 dark:to-gray-900 p-8 shadow-sm">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white tracking-tight">{{ __('matching_engine.overview_title') }}</h1>
        <p class="mt-2 text-gray-600 dark:text-gray-300 max-w-3xl">{{ __('matching_engine.overview_intro') }}</p>
        <dl class="mt-6 grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="rounded-xl bg-white/80 dark:bg-gray-800/80 border border-gray-100 dark:border-gray-600 p-4">
                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('matching_engine.field_weight_total') }}</dt>
                <dd class="text-2xl font-bold text-rose-600 dark:text-rose-400">{{ $sumWeights }}</dd>
            </div>
            <div class="rounded-xl bg-white/80 dark:bg-gray-800/80 border border-gray-100 dark:border-gray-600 p-4">
                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Candidate pool limit</dt>
                <dd class="text-2xl font-bold text-indigo-600 dark:text-indigo-400">{{ $pool }}</dd>
            </div>
            <div class="rounded-xl bg-white/80 dark:bg-gray-800/80 border border-gray-100 dark:border-gray-600 p-4">
                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Persist matches</dt>
                <dd class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ $persist ? 'Yes' : 'No' }}</dd>
            </div>
        </dl>
    </div>

    @if (! $canEdit)
        <div class="rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/30 px-4 py-3 text-sm text-amber-900 dark:text-amber-100">
            {{ __('matching_engine.read_only') }}
        </div>
    @endif

    <div class="bg-white dark:bg-gray-800 shadow rounded-xl border border-gray-200 dark:border-gray-700 p-6 max-w-2xl">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">{{ __('matching_engine.runtime_heading') }}</h2>
        <form method="POST" action="{{ route('admin.matching-engine.runtime') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('matching_engine.candidate_pool') }}</label>
                <input type="number" name="candidate_pool_limit" value="{{ old('candidate_pool_limit', $rv['candidate_pool_limit'] ?? '') }}" min="1" max="2000" placeholder="200"
                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm {{ $canEdit ? '' : 'opacity-60 cursor-not-allowed' }}"
                    @disabled(! $canEdit) />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('matching_engine.persist_cache') }}</label>
                <select name="persist_cache_mode" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" @disabled(! $canEdit)>
                    <option value="default" @selected(old('persist_cache_mode', $persistMode) === 'default')>{{ __('matching_engine.use_config_placeholder') }}</option>
                    <option value="yes" @selected(old('persist_cache_mode', $persistMode) === 'yes')>Yes</option>
                    <option value="no" @selected(old('persist_cache_mode', $persistMode) === 'no')>No</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Note (audit)</label>
                <input type="text" name="note" value="{{ old('note') }}" maxlength="500" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" @disabled(! $canEdit) />
            </div>
            @if ($canEdit)
                <button type="submit" class="px-4 py-2 rounded-lg bg-rose-600 hover:bg-rose-700 text-white text-sm font-medium">Save runtime</button>
            @endif
        </form>
    </div>
</div>
@endsection

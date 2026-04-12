@extends('layouts.admin')

@section('content')
<div class="max-w-3xl space-y-6">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('matching_engine.nav_filters') }}</h1>
    <p class="text-sm text-amber-800 dark:text-amber-200 bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900 rounded-lg px-4 py-3">{{ __('matching_engine.strict_warning') }}</p>
    @if (! $canEdit)
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm">{{ __('matching_engine.read_only') }}</div>
    @endif
    <form method="POST" action="{{ route('admin.matching-engine.filters.save') }}" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-6">
        @csrf
        @foreach ($filters as $f)
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 items-end">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 capitalize">{{ str_replace('_', ' ', $f->filter_key) }}</label>
                    <select name="mode[{{ $f->filter_key }}]" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm" @disabled(! $canEdit)>
                        <option value="off" @selected(old('mode.'.$f->filter_key, $f->mode) === 'off')>Off</option>
                        <option value="preferred" @selected(old('mode.'.$f->filter_key, $f->mode) === 'preferred')>Preferred (score penalty)</option>
                        <option value="strict" @selected(old('mode.'.$f->filter_key, $f->mode) === 'strict')>Strict (query filter)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Penalty points (preferred)</label>
                    <input type="number" name="penalty[{{ $f->filter_key }}]" value="{{ old('penalty.'.$f->filter_key, $f->preferred_penalty_points) }}" min="0" max="50" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm" @disabled(! $canEdit) />
                </div>
            </div>
        @endforeach
        <div>
            <label class="block text-sm font-medium mb-1">Note (audit)</label>
            <input type="text" name="note" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm" @disabled(! $canEdit) />
        </div>
        @if ($canEdit)
            <button type="submit" class="px-5 py-2.5 rounded-lg bg-rose-600 text-white text-sm font-medium">Save filters</button>
        @endif
    </form>
</div>
@endsection

@extends('layouts.admin')

@section('content')
<div class="max-w-4xl space-y-6">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('matching_engine.nav_boosts') }}</h1>
    <p class="text-sm text-gray-500 dark:text-gray-400">Uses <code class="text-xs bg-gray-100 dark:bg-gray-700 px-1 rounded">aggregate_cap</code> as the global ceiling for rule + AI boost. Legacy <a href="{{ route('admin.match-boost.edit') }}" class="text-rose-600 hover:underline">Match boost</a> still controls AI on/off (Sarvam).</p>
    @if (! $canEdit)
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm">{{ __('matching_engine.read_only') }}</div>
    @endif
    <form method="POST" action="{{ route('admin.matching-engine.boosts.save') }}" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
        @csrf
        @php $activeRule = $rules->firstWhere('boost_type', 'active'); @endphp
        @if ($activeRule)
            <div class="pb-4 border-b border-gray-100 dark:border-gray-700">
                <label class="block text-sm font-medium mb-1">Active within days (recency window)</label>
                <input type="number" name="active_within_days" value="{{ old('active_within_days', $activeRule->meta['active_within_days'] ?? 7) }}" min="1" max="365" class="w-full max-w-xs rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm" @disabled(! $canEdit) />
            </div>
        @endif
        @foreach ($rules as $rule)
            <div class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-center border-b border-gray-100 dark:border-gray-700 pb-3">
                <div class="sm:col-span-3 font-mono text-sm text-gray-800 dark:text-gray-200">{{ $rule->boost_type }}</div>
                <div class="sm:col-span-2">
                    <label class="text-xs text-gray-500">Value</label>
                    <input type="number" name="value[{{ $rule->boost_type }}]" value="{{ old('value.'.$rule->boost_type, $rule->value) }}" class="w-full rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm" @disabled(! $canEdit) />
                </div>
                <div class="sm:col-span-2">
                    <label class="text-xs text-gray-500">Max cap</label>
                    <input type="number" name="max_cap[{{ $rule->boost_type }}]" value="{{ old('max_cap.'.$rule->boost_type, $rule->max_cap) }}" class="w-full rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm" @disabled(! $canEdit) />
                </div>
                <label class="sm:col-span-2 flex items-center gap-2 text-sm">
                    <input type="checkbox" name="active[{{ $rule->boost_type }}]" value="1" @checked(old('active.'.$rule->boost_type, $rule->is_active)) @disabled(! $canEdit) />
                    Active
                </label>
            </div>
        @endforeach
        <div>
            <label class="block text-sm font-medium mb-1">Note (audit)</label>
            <input type="text" name="note" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm" @disabled(! $canEdit) />
        </div>
        @if ($canEdit)
            <button type="submit" class="px-5 py-2.5 rounded-lg bg-rose-600 text-white text-sm font-medium">Save boost rules</button>
        @endif
    </form>
</div>
@endsection

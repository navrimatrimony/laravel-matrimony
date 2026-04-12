@extends('layouts.admin')

@section('content')
<div class="max-w-3xl space-y-6">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('matching_engine.nav_behavior') }}</h1>
    @if (! $canEdit)
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm">{{ __('matching_engine.read_only') }}</div>
    @endif
    <form method="POST" action="{{ route('admin.matching-engine.behavior.save') }}" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
        @csrf
        @foreach ($rows as $r)
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-center border-b border-gray-100 dark:border-gray-700 pb-4 last:border-0">
                <span class="font-medium capitalize text-gray-900 dark:text-white">{{ $r->action }}</span>
                <div>
                    <label class="text-xs text-gray-500">Weight</label>
                    <input type="number" name="weight[{{ $r->action }}]" value="{{ old('weight.'.$r->action, $r->weight) }}" class="w-full rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm" @disabled(! $canEdit) />
                </div>
                <div>
                    <label class="text-xs text-gray-500">Decay days</label>
                    <input type="number" name="decay[{{ $r->action }}]" value="{{ old('decay.'.$r->action, $r->decay_days) }}" min="1" class="w-full rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm" @disabled(! $canEdit) />
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="active[{{ $r->action }}]" value="1" @checked(old('active.'.$r->action, $r->is_active)) @disabled(! $canEdit) />
                    Active
                </label>
            </div>
        @endforeach
        <div>
            <label class="block text-sm font-medium mb-1">{{ __('matching_engine.behavior_cap') }}</label>
            <input type="number" name="behavior_max_points" value="{{ old('behavior_max_points', $behaviorCap) }}" min="0" max="50" class="w-full max-w-xs rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm" @disabled(! $canEdit) />
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Note (audit)</label>
            <input type="text" name="note" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm" @disabled(! $canEdit) />
        </div>
        @if ($canEdit)
            <button type="submit" class="px-5 py-2.5 rounded-lg bg-rose-600 text-white text-sm font-medium">Save behavior</button>
        @endif
    </form>
</div>
@endsection

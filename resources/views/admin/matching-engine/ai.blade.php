@extends('layouts.admin')

@section('content')
<div class="max-w-3xl space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('matching_engine.ai_title') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('matching_engine.ai_intro') }}</p>
    </div>
    <div class="rounded-xl border border-indigo-200 dark:border-indigo-900 bg-indigo-50/80 dark:bg-indigo-950/30 p-4 text-sm text-indigo-900 dark:text-indigo-100">
        <p class="font-medium">Meta</p>
        <pre class="mt-2 text-xs overflow-x-auto">{{ json_encode($payload['meta'] ?? [], JSON_PRETTY_PRINT) }}</pre>
    </div>
    <div class="space-y-3">
        @forelse ($payload['suggestions'] ?? [] as $s)
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 shadow-sm">
                <div class="flex items-center gap-2 mb-2">
                    <span class="text-xs font-bold uppercase tracking-wide text-rose-600">{{ $s['type'] ?? '' }}</span>
                    <h2 class="font-semibold text-gray-900 dark:text-white">{{ $s['title'] ?? '' }}</h2>
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ $s['detail'] ?? '' }}</p>
                <p class="mt-3 text-sm text-gray-800 dark:text-gray-200 border-t border-gray-100 dark:border-gray-700 pt-3"><span class="font-medium">Suggested:</span> {{ $s['suggested_action'] ?? '' }}</p>
            </div>
        @empty
            <p class="text-gray-500 text-sm">No suggestions right now — configuration looks balanced.</p>
        @endforelse
    </div>
    <a href="{{ route('admin.matching-engine.ai') }}" class="inline-flex items-center px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700">{{ __('matching_engine.ai_run') }}</a>
</div>
@endsection

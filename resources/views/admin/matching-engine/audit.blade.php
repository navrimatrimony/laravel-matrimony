@extends('layouts.admin')

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('matching_engine.audit_title') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('matching_engine.audit_intro') }}</p>
    </div>
    @if (! $canEdit)
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm">{{ __('matching_engine.read_only') }}</div>
    @endif
    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-900/50 text-left text-gray-600 dark:text-gray-300">
                <tr>
                    <th class="px-4 py-3">ID</th>
                    <th class="px-4 py-3">When</th>
                    <th class="px-4 py-3">Admin</th>
                    <th class="px-4 py-3">Note</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($versions as $v)
                    <tr class="border-t border-gray-100 dark:border-gray-700">
                        <td class="px-4 py-3 font-mono">{{ $v->id }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $v->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="px-4 py-3">{{ $v->editor?->email ?? '—' }}</td>
                        <td class="px-4 py-3 max-w-md truncate" title="{{ $v->note }}">{{ $v->note ?: '—' }}</td>
                        <td class="px-4 py-3 text-right">
                            @if ($canEdit)
                                <form method="POST" action="{{ route('admin.matching-engine.audit.rollback', ['matching_config_version' => $v->id]) }}" onsubmit="return confirm(@json(__('matching_engine.rollback_confirm')));" class="inline">
                                    @csrf
                                    <button type="submit" class="text-rose-600 hover:underline text-xs font-medium">{{ __('matching_engine.rollback') }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">No versions yet. Saving any engine page creates a snapshot.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

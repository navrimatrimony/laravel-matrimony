@extends('layouts.app')

@section('content')
<div class="py-10">
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
        <div class="px-4 sm:px-0 mb-8">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('matching.title') }}</h1>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('matching.subtitle') }}</p>
            @if (isset($subjectProfile))
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-500">
                    {{ __('matching.profile_label') }} #{{ $subjectProfile->id }}
                    @if ($subjectProfile->full_name)
                        — {{ $subjectProfile->full_name }}
                    @endif
                </p>
            @endif
        </div>

        @if ($matches->isEmpty())
            <div class="rounded-lg border border-dashed border-gray-300 dark:border-gray-600 bg-white/80 dark:bg-gray-800/80 px-6 py-12 text-center text-gray-600 dark:text-gray-400">
                {{ __('matching.empty') }}
            </div>
        @else
            <ul class="space-y-6">
                @foreach ($matches as $row)
                    @php
                        /** @var \App\Models\MatrimonyProfile $p */
                        $p = $row['profile'];
                        $score = (int) $row['score'];
                        $reasons = $row['reasons'] ?? [];
                    @endphp
                    <li class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm overflow-hidden">
                        <div class="p-5 sm:p-6 flex flex-col sm:flex-row sm:items-start gap-4">
                            <div class="flex-shrink-0">
                                <img src="{{ $p->profile_photo_url }}" alt="" class="h-20 w-20 rounded-lg object-cover border border-gray-100 dark:border-gray-600">
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2 gap-y-1">
                                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 truncate">
                                        {{ $p->full_name ?: __('matching.profile_label') . ' #' . $p->id }}
                                    </h2>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-indigo-100 text-indigo-800 dark:bg-indigo-900/50 dark:text-indigo-200">
                                        {{ __('matching.score') }}: {{ $score }}
                                    </span>
                                </div>
                                <dl class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 text-sm text-gray-600 dark:text-gray-400">
                                    @if ($p->gender)
                                        <div><span class="text-gray-500">{{ __('Gender') }}:</span> {{ $p->gender->label ?? '' }}</div>
                                    @endif
                                    @if ($p->city?->name || $p->state?->name)
                                        <div class="truncate">
                                            <span class="text-gray-500">{{ __('Location') }}:</span>
                                            {{ collect([$p->city?->name, $p->state?->name])->filter()->implode(', ') }}
                                        </div>
                                    @endif
                                </dl>
                                @if (is_array($reasons) && count($reasons) > 0)
                                    <div class="mt-4">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">{{ __('matching.reasons_heading') }}</p>
                                        <ul class="flex flex-wrap gap-2">
                                            @foreach ($reasons as $reason)
                                                <li class="text-xs px-2 py-1 rounded-md bg-emerald-50 text-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-100">{{ $reason }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                                <div class="mt-4">
                                    <a href="{{ route('matrimony.profile.show', ['matrimony_profile_id' => $p->id]) }}" class="inline-flex items-center text-sm font-medium text-indigo-600 dark:text-indigo-400 hover:underline">
                                        {{ __('matching.view_profile') }}
                                    </a>
                                </div>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
@endsection

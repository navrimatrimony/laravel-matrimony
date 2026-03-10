@extends('layouts.app')

@section('content')
@php
    $sections = $sections ?? [];
    $sectionLabels = $sectionLabels ?? [];
    $currentSection = $currentSection ?? 'basic-info';
    $completionPct = $completionPct ?? 0;
    $sectionStatuses = $sectionStatuses ?? [];
    $nextSection = $nextSection ?? null;
    $previousSection = $previousSection ?? null;
@endphp
<div class="py-4 md:py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        {{-- Progress bar (top) --}}
        <div class="mb-4 md:mb-6">
            <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400 mb-1">
                <span>{{ __('Profile completion') }}</span>
                <span>{{ $completionPct }}%</span>
            </div>
            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2.5">
                <div class="bg-indigo-600 h-2.5 rounded-full transition-all duration-300" style="width: {{ min(100, $completionPct) }}%"></div>
            </div>
        </div>

        {{-- Flash messages --}}
        @if (session('success'))
            <div class="mb-4 px-4 py-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/30 text-emerald-800 dark:text-emerald-200 text-sm">{{ session('success') }}</div>
        @endif
        @if (session('warning'))
            <div class="mb-4 px-4 py-3 rounded-lg bg-amber-50 dark:bg-amber-900/30 text-amber-800 dark:text-amber-200 text-sm">{{ session('warning') }}</div>
        @endif
        @if (session('error'))
            <div class="mb-4 px-4 py-3 rounded-lg bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200 text-sm">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="mb-4 px-4 py-3 rounded-lg bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-200 text-sm">
                {{ $errors->first() }}
            </div>
        @endif

        <div class="flex flex-col lg:flex-row gap-6 lg:gap-8">
            {{-- Desktop: sticky left sidebar nav --}}
            <aside class="hidden lg:block lg:w-56 xl:w-64 shrink-0">
                <div class="lg:sticky lg:top-4 space-y-0.5 rounded-lg border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 p-2 shadow-sm">
                    <p class="px-2 py-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Sections') }}</p>
                    @foreach ($sections as $s)
                        @php
                            $label = $sectionLabels[$s] ?? str_replace('-', ' ', ucfirst($s));
                            $status = $sectionStatuses[$s] ?? 'incomplete';
                            $isActive = $currentSection === $s;
                        @endphp
                        <a href="{{ route('matrimony.profile.wizard.section', ['section' => $s]) }}"
                            class="flex items-center gap-2 rounded-md px-2 py-2 text-sm font-medium transition-colors {{ $isActive ? 'bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700' }}">
                            <span class="shrink-0 w-2 h-2 rounded-full
                                @if($status === 'completed') bg-emerald-500
                                @elseif($status === 'warning') bg-amber-500
                                @else bg-gray-300 dark:bg-gray-500
                                @endif" aria-hidden="true"></span>
                            <span class="truncate">{{ __($label) }}</span>
                        </a>
                    @endforeach
                </div>
            </aside>

            {{-- Mobile: horizontal scrollable chips --}}
            <div class="lg:hidden overflow-x-auto pb-2 -mx-4 px-4">
                <div class="flex gap-2 min-w-max">
                    @foreach ($sections as $s)
                        @php
                            $label = $sectionLabels[$s] ?? str_replace('-', ' ', ucfirst($s));
                            $status = $sectionStatuses[$s] ?? 'incomplete';
                            $isActive = $currentSection === $s;
                        @endphp
                        <a href="{{ route('matrimony.profile.wizard.section', ['section' => $s]) }}"
                            class="shrink-0 px-3 py-2 rounded-full text-sm font-medium border transition-colors whitespace-nowrap
                                {{ $isActive ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:border-indigo-400' }}">
                            {{ __($label) }}
                        </a>
                    @endforeach
                </div>
            </div>

            {{-- Main content: one section at a time --}}
            <main class="flex-1 min-w-0">
                <div class="bg-white dark:bg-gray-800 shadow rounded-lg border border-gray-200 dark:border-gray-600 overflow-hidden">
                    <div class="px-4 sm:px-6 py-4 border-b border-gray-200 dark:border-gray-600">
                        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">
                            {{ __($sectionLabels[$currentSection] ?? str_replace('-', ' ', ucfirst($currentSection))) }}
                        </h1>
                    </div>
                    <form method="POST" action="{{ route('matrimony.profile.wizard.store', ['section' => $currentSection]) }}" enctype="{{ in_array($currentSection, ['photo', 'full'], true) ? 'multipart/form-data' : 'application/x-www-form-urlencoded' }}" class="p-4 sm:p-6">
                        @csrf

                        @include('matrimony.profile.wizard.sections.' . str_replace('-', '_', $currentSection))

                        <div class="flex flex-wrap gap-3 pt-6 mt-6 border-t border-gray-200 dark:border-gray-700">
                            @if ($previousSection)
                                <a href="{{ route('matrimony.profile.wizard.section', ['section' => $previousSection]) }}" class="px-4 py-2 bg-gray-200 dark:bg-gray-600 text-gray-800 dark:text-gray-200 rounded-lg font-medium hover:bg-gray-300 dark:hover:bg-gray-500 transition-colors">
                                    {{ __('Previous') }}
                                </a>
                            @endif
                            <button type="submit" name="save_only" value="1" class="px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200 rounded-lg font-medium hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors border border-gray-300 dark:border-gray-600">
                                {{ __('Save') }}
                            </button>
                            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-medium transition-colors">
                                {{ $nextSection ? __('Save & Next') : __('Save & Finish') }}
                            </button>
                            @if ($nextSection)
                                <a href="{{ route('matrimony.profile.wizard.section', ['section' => $nextSection]) }}" class="px-4 py-2 text-gray-600 dark:text-gray-400 text-sm font-medium hover:text-indigo-600 dark:hover:text-indigo-400">
                                    {{ __('Skip for now') }}
                                </a>
                            @endif
                        </div>
                    </form>
                </div>
            </main>
        </div>
    </div>
</div>
@endsection

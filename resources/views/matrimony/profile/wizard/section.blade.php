@extends('layouts.app')

@section('content')
<div class="py-8">
    <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-2">Complete your profile</h1>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">Section-based wizard. All changes are saved via MutationService.</p>

            {{-- Progress bar --}}
            <div class="mb-6">
                <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400 mb-1">
                    <span>Progress</span>
                    <span>{{ $completionPct ?? 0 }}%</span>
                </div>
                <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2.5">
                    <div class="bg-indigo-600 h-2.5 rounded-full transition-all" style="width: {{ $completionPct ?? 0 }}%"></div>
                </div>
                <div class="flex justify-between mt-1 text-xs text-gray-500 dark:text-gray-400">
                    @foreach($sections ?? [] as $s)
                        <span class="{{ ($currentSection ?? '') === $s ? 'font-semibold text-indigo-600 dark:text-indigo-400' : '' }}">{{ str_replace('-', ' ', ucfirst($s)) }}</span>
                    @endforeach
                </div>
            </div>

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
                <div class="mb-4 px-4 py-3 rounded-lg bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-200 text-sm">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('matrimony.profile.wizard.store', ['section' => $currentSection]) }}" enctype="{{ ($currentSection ?? '') === 'photo' ? 'multipart/form-data' : 'application/x-www-form-urlencoded' }}">
                @csrf

                @include('matrimony.profile.wizard.sections.' . str_replace('-', '_', $currentSection ?? 'basic_info'))

                <div class="flex gap-4 pt-6 border-t border-gray-200 dark:border-gray-700 mt-6">
                    <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded font-medium">
                        {{ ($nextSection ?? null) ? 'Save & Next' : 'Save & Finish' }}
                    </button>
                    @if($nextSection ?? null)
                        <a href="{{ route('matrimony.profile.wizard.section', ['section' => $nextSection]) }}" class="px-4 py-2 bg-gray-200 dark:bg-gray-600 text-gray-800 dark:text-gray-200 rounded font-medium">Skip for now</a>
                    @endif
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

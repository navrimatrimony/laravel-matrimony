@extends('layouts.admin')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-2">{{ __('admin.duplicate_phones.title') }}</h1>
    <p class="text-sm text-gray-600 dark:text-gray-400 mb-6">{{ __('admin.duplicate_phones.intro') }}</p>

    @if (session('success'))
        <div class="mb-4 rounded-md bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800 px-4 py-3 text-sm text-emerald-800 dark:text-emerald-200">
            {{ session('success') }}
        </div>
    @endif

    <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden border border-gray-200 dark:border-gray-700">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-900/50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Mobile (stored)</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Backup</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.duplicate_phones.primary') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.duplicate_phones.secondaries') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fix mobile</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($users as $u)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-900/30">
                        <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{{ $u->id }}</td>
                        <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{{ $u->name }}</td>
                        <td class="px-4 py-3 text-sm font-mono text-gray-800 dark:text-gray-200">{{ $u->mobile ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm font-mono text-gray-600 dark:text-gray-400">{{ $u->mobile_backup ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm">
                            @if ($u->mobileDuplicatePrimary)
                                <span class="text-gray-800 dark:text-gray-200">#{{ $u->mobileDuplicatePrimary->id }} {{ $u->mobileDuplicatePrimary->name }}</span>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">
                            @if ($u->mobileDuplicateSecondaries->isNotEmpty())
                                <ul class="list-disc list-inside space-y-1">
                                    @foreach ($u->mobileDuplicateSecondaries as $s)
                                        <li>#{{ $s->id }} — {{ $s->mobile }}</li>
                                    @endforeach
                                </ul>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm align-top">
                            <form method="post" action="{{ route('admin.duplicate-phones.update-mobile', $u) }}" class="flex flex-col gap-2 max-w-xs">
                                @csrf
                                <input type="text" name="mobile" value="{{ old('mobile', $u->mobile) }}" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm shadow-sm" placeholder="10-digit mobile" />
                                @error('mobile')
                                    <span class="text-xs text-red-600">{{ $message }}</span>
                                @enderror
                                <button type="submit" class="inline-flex justify-center rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-indigo-500">{{ __('admin.duplicate_phones.save_mobile') }}</button>
                            </form>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <button type="button" disabled class="text-xs rounded border border-gray-300 dark:border-gray-600 px-2 py-1 opacity-50 cursor-not-allowed" title="{{ __('admin.duplicate_phones.merge_hint') }}">{{ __('admin.duplicate_phones.merge_coming') }}</button>
                                <button type="button" disabled class="text-xs rounded border border-gray-300 dark:border-gray-600 px-2 py-1 opacity-50 cursor-not-allowed" title="{{ __('admin.duplicate_phones.merge_hint') }}">{{ __('admin.duplicate_phones.mark_primary_coming') }}</button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">No duplicate-phone rows to show.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">
        {{ $users->links() }}
    </div>
</div>
@endsection

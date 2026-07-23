@extends('layouts.admin')

@section('content')
<div class="space-y-6">
    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Clean up abandoned signups</h1>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
            Permanently deletes Suchak signups that never became anything. Nothing is deleted until you press the button below.
        </p>

        <ul class="mt-4 space-y-1 text-sm text-gray-700 dark:text-gray-300">
            <li>• Still <strong>pending</strong> — a verified, rejected, suspended or archived Suchak can never be deleted here.</li>
            <li>• Registration was <strong>never completed</strong>.</li>
            <li>• Older than <strong>{{ $minimumAgeDays }} days</strong> (this minimum cannot be lowered).</li>
            <li>• Has <strong>no records at all</strong> across the {{ $relatedTableCount }} related tables — no customers, consents, payments, documents or activity.</li>
        </ul>

        <a href="{{ route('admin.suchak.accounts.index') }}" class="mt-4 inline-block text-sm font-medium text-indigo-600 underline dark:text-indigo-300">← Back to accounts</a>
    </div>

    @if ($eligible->isEmpty())
        <div class="rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-6 text-center dark:border-emerald-500/40 dark:bg-emerald-500/10">
            <p class="text-sm font-semibold text-emerald-800 dark:text-emerald-300">Nothing to clean up.</p>
            <p class="mt-1 text-sm text-emerald-700 dark:text-emerald-300">
                No abandoned signup is both older than {{ $minimumAgeDays }} days and completely empty.
            </p>
        </div>
    @else
        <div class="overflow-hidden rounded-lg border border-rose-300 bg-white shadow-sm dark:border-rose-500/40 dark:bg-gray-800">
            <div class="border-b border-rose-200 bg-rose-50 px-4 py-3 dark:border-rose-500/30 dark:bg-rose-500/10">
                <p class="text-sm font-semibold text-rose-800 dark:text-rose-300">
                    {{ $eligible->count() }} signup(s) will be permanently deleted. This cannot be undone.
                </p>
            </div>

            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">ID</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Name</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Mobile</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Stopped at</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ($eligible as $account)
                        <tr>
                            <td class="px-4 py-2 text-gray-500 dark:text-gray-400">#{{ $account->id }}</td>
                            <td class="px-4 py-2 font-medium text-gray-900 dark:text-gray-100">{{ $account->suchak_name ?: '(no name)' }}</td>
                            <td class="px-4 py-2 text-gray-700 dark:text-gray-300">{{ $account->mobile_number ?: '—' }}</td>
                            <td class="px-4 py-2 text-gray-700 dark:text-gray-300">{{ $account->onboarding_step ?: '—' }}</td>
                            <td class="px-4 py-2 text-gray-700 dark:text-gray-300">{{ $account->created_at?->format('d M Y') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="border-t border-gray-200 px-4 py-4 dark:border-gray-700">
                <form method="POST" action="{{ route('admin.suchak.accounts.cleanup.destroy') }}"
                      onsubmit="return confirm('Permanently delete {{ $eligible->count() }} abandoned signup(s)? This cannot be undone.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="rounded-md bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700">
                        Delete {{ $eligible->count() }} abandoned signup(s)
                    </button>
                </form>
            </div>
        </div>
    @endif
</div>
@endsection

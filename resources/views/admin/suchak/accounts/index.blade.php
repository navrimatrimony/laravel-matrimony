@extends('layouts.admin')

@section('content')
<div class="space-y-6">
    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Suchak Accounts</h1>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Review authenticated Suchak account requests and verification state.</p>
            </div>

            <form method="GET" action="{{ route('admin.suchak.accounts.index') }}" class="flex items-center gap-2">
                <label for="verification_status" class="text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                <select id="verification_status" name="verification_status" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">All</option>
                    @foreach ($allowedStatuses as $allowedStatus)
                        <option value="{{ $allowedStatus }}" @selected($status === $allowedStatus)>{{ ucfirst($allowedStatus) }}</option>
                    @endforeach
                </select>
                <button type="submit" class="rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white hover:bg-gray-700 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">Filter</button>
            </form>
        </div>
    </div>

    <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-900">
                <tr>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Suchak</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Business</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Verification</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Public</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Submitted</th>
                    <th class="px-4 py-3 text-right font-semibold text-gray-700 dark:text-gray-200">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($accounts as $account)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-900 dark:text-gray-100">{{ $account->suchak_name }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $account->user?->email }}</div>
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ ucfirst($account->business_type) }}</td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ ucfirst($account->verification_status) }}</td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ ucfirst($account->public_status) }}</td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $account->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.suchak.accounts.show', $account) }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-300 dark:hover:text-indigo-200">Review</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">No Suchak accounts found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="border-t border-gray-200 px-4 py-3 dark:border-gray-700">
            {{ $accounts->links() }}
        </div>
    </div>
</div>
@endsection

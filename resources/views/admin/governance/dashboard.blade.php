@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6" x-data="{ activeTab: 'analytics' }">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-4">Governance Dashboard</h1>

    {{-- Date filter --}}
    <form method="GET" action="{{ route('admin.governance-dashboard') }}" class="mb-6 flex flex-wrap items-end gap-3">
        <div class="flex items-center gap-2">
            <span class="text-sm text-gray-600 dark:text-gray-400">Period:</span>
            <label class="inline-flex items-center gap-1">
                <input type="radio" name="period" value="7d" {{ $period === '7d' ? 'checked' : '' }} class="rounded border-gray-300">
                <span class="text-sm">7d</span>
            </label>
            <label class="inline-flex items-center gap-1">
                <input type="radio" name="period" value="30d" {{ $period === '30d' ? 'checked' : '' }} class="rounded border-gray-300">
                <span class="text-sm">30d</span>
            </label>
            <label class="inline-flex items-center gap-1">
                <input type="radio" name="period" value="custom" {{ $period === 'custom' ? 'checked' : '' }} class="rounded border-gray-300">
                <span class="text-sm">Custom</span>
            </label>
        </div>
        @if($period === 'custom')
        <div class="flex items-center gap-2">
            <input type="date" name="from" value="{{ $from->format('Y-m-d') }}" class="rounded border-gray-300 dark:bg-gray-700 dark:border-gray-600 text-sm">
            <span class="text-gray-500">to</span>
            <input type="date" name="to" value="{{ $to->format('Y-m-d') }}" class="rounded border-gray-300 dark:bg-gray-700 dark:border-gray-600 text-sm">
        </div>
        @endif
        <button type="submit" class="px-3 py-1.5 bg-gray-700 hover:bg-gray-600 text-white text-sm rounded">Apply</button>
    </form>

    {{-- Summary cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600 p-4">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total Mutations</p>
            <p class="text-xl font-bold text-gray-800 dark:text-gray-100 mt-1">{{ $totalMutations }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">In selected period</p>
        </div>
        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600 p-4">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Conflict Pending</p>
            <p class="text-xl font-bold text-amber-600 dark:text-amber-400 mt-1">{{ $conflictPending }}</p>
        </div>
        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600 p-4">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">High Risk Profiles</p>
            <p class="text-xl font-bold text-red-600 dark:text-red-400 mt-1">{{ $highRiskProfileCount }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Suspicious flags</p>
        </div>
        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600 p-4">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Duplicate Conflicts</p>
            <p class="text-xl font-bold text-gray-800 dark:text-gray-100 mt-1">{{ $duplicateConflictCount }}</p>
        </div>
    </div>

    {{-- Section tabs --}}
    <div class="border-b border-gray-200 dark:border-gray-600 mb-4">
        <nav class="flex gap-4">
            <button type="button" @click="activeTab = 'analytics'" :class="activeTab === 'analytics' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'" class="py-2 px-1 border-b-2 font-medium text-sm">Analytics</button>
            <button type="button" @click="activeTab = 'suspicious'" :class="activeTab === 'suspicious' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'" class="py-2 px-1 border-b-2 font-medium text-sm">Suspicious Flags</button>
            <button type="button" @click="activeTab = 'batch'" :class="activeTab === 'batch' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'" class="py-2 px-1 border-b-2 font-medium text-sm">Batch Audit</button>
        </nav>
    </div>

    @php
        $profileUrl = fn ($id) => route('admin.profiles.show', (int) $id);
    @endphp

    {{-- Tab: Analytics --}}
    <div x-show="activeTab === 'analytics'" x-transition>
        <section class="mb-6">
            <h2 class="text-lg font-semibold text-gray-700 dark:text-gray-200 mb-3">Mutation counts</h2>
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-600">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Source</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Count</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-600">
                    @forelse($mutationCounts as $row)
                    <tr>
                        <td class="px-4 py-2 text-gray-800 dark:text-gray-200">{{ $row['source'] ?? '-' }}</td>
                        <td class="px-4 py-2 text-right text-gray-800 dark:text-gray-200">{{ $row['count'] }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="2" class="px-4 py-2 text-gray-500">No data</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="mb-6">
            <h2 class="text-lg font-semibold text-gray-700 dark:text-gray-200 mb-3">Conflict metrics</h2>
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-600">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Metric</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Value</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-600">
                    <tr>
                        <td class="px-4 py-2 text-gray-800 dark:text-gray-200">Total</td>
                        <td class="px-4 py-2 text-right">{{ $conflictMetrics['total'] ?? 0 }}</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-2 text-gray-800 dark:text-gray-200">Pending</td>
                        <td class="px-4 py-2 text-right">{{ $conflictMetrics['pending'] ?? 0 }}</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-2 text-gray-800 dark:text-gray-200">Resolved</td>
                        <td class="px-4 py-2 text-right">{{ $conflictMetrics['resolved'] ?? 0 }}</td>
                    </tr>
                    @foreach(($conflictMetrics['by_field_type'] ?? []) as $fieldType => $counts)
                    <tr>
                        <td class="px-4 py-2 text-gray-800 dark:text-gray-200">By type: {{ $fieldType }}</td>
                        <td class="px-4 py-2 text-right">P: {{ $counts['pending'] ?? 0 }} / R: {{ $counts['resolved'] ?? 0 }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <section class="mb-6">
            <h2 class="text-lg font-semibold text-gray-700 dark:text-gray-200 mb-3">High mutation profiles</h2>
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-600">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Profile</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Name</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Mutations</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-600">
                    @forelse($highMutationProfiles as $row)
                    <tr>
                        <td class="px-4 py-2">
                            <a href="{{ $profileUrl($row['profile_id']) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ $row['profile_id'] }}</a>
                        </td>
                        <td class="px-4 py-2 text-gray-800 dark:text-gray-200">{{ $row['full_name'] ?? '-' }}</td>
                        <td class="px-4 py-2 text-right">{{ $row['mutation_count'] }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="3" class="px-4 py-2 text-gray-500">No data</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </div>

    {{-- Tab: Suspicious Flags --}}
    <div x-show="activeTab === 'suspicious'" x-transition style="display: none;">
        <section class="mb-6">
            <h2 class="text-lg font-semibold text-gray-700 dark:text-gray-200 mb-3">
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400">income_spike</span>
                Income spikes (ratio ≥ 2x)
            </h2>
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-600">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Profile</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Old</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">New</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Changed at</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-600">
                    @forelse($incomeSpikes as $row)
                    <tr>
                        <td class="px-4 py-2"><a href="{{ $profileUrl($row['profile_id']) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ $row['profile_id'] }}</a></td>
                        <td class="px-4 py-2 text-gray-800 dark:text-gray-200">{{ $row['old_value'] ?? '-' }}</td>
                        <td class="px-4 py-2 text-gray-800 dark:text-gray-200">{{ $row['new_value'] ?? '-' }}</td>
                        <td class="px-4 py-2 text-gray-800 dark:text-gray-200">{{ $row['changed_at'] ?? '-' }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="px-4 py-2 text-gray-500">No data</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="mb-6">
            <h2 class="text-lg font-semibold text-gray-700 dark:text-gray-200 mb-3">
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400">caste_flip</span>
                Caste flip after serious intent
            </h2>
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-600">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Profile</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Old</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">New</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Changed at</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-600">
                    @forelse($casteFlips as $row)
                    <tr>
                        <td class="px-4 py-2"><a href="{{ $profileUrl($row['profile_id']) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ $row['profile_id'] }}</a></td>
                        <td class="px-4 py-2 text-gray-800 dark:text-gray-200">{{ $row['old_value'] ?? '-' }}</td>
                        <td class="px-4 py-2 text-gray-800 dark:text-gray-200">{{ $row['new_value'] ?? '-' }}</td>
                        <td class="px-4 py-2 text-gray-800 dark:text-gray-200">{{ $row['changed_at'] ?? '-' }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="px-4 py-2 text-gray-500">No data</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="mb-6">
            <h2 class="text-lg font-semibold text-gray-700 dark:text-gray-200 mb-3">
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400">dob_change</span>
                DOB change after active
            </h2>
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-600">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Profile</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Old</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">New</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Changed at</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-600">
                    @forelse($dobAfterActive as $row)
                    <tr>
                        <td class="px-4 py-2"><a href="{{ $profileUrl($row['profile_id']) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ $row['profile_id'] }}</a></td>
                        <td class="px-4 py-2 text-gray-800 dark:text-gray-200">{{ $row['old_value'] ?? '-' }}</td>
                        <td class="px-4 py-2 text-gray-800 dark:text-gray-200">{{ $row['new_value'] ?? '-' }}</td>
                        <td class="px-4 py-2 text-gray-800 dark:text-gray-200">{{ $row['changed_at'] ?? '-' }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="px-4 py-2 text-gray-500">No data</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="mb-6">
            <h2 class="text-lg font-semibold text-gray-700 dark:text-gray-200 mb-3">
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400">frequent_contact</span>
                Frequent contact changes (≥ 3)
            </h2>
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-600">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Profile</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Contact change count</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-600">
                    @forelse($frequentContactChanges as $row)
                    <tr>
                        <td class="px-4 py-2"><a href="{{ $profileUrl($row['profile_id']) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ $row['profile_id'] }}</a></td>
                        <td class="px-4 py-2 text-right">{{ $row['contact_change_count'] }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="2" class="px-4 py-2 text-gray-500">No data</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </div>

    {{-- Tab: Batch Audit --}}
    <div x-show="activeTab === 'batch'" x-transition style="display: none;">
        <section class="mb-6">
            <h2 class="text-lg font-semibold text-gray-700 dark:text-gray-200 mb-3">Changes by profile and date</h2>
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-600">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Profile</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Date</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Count</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-600">
                    @forelse($profileDateGroups as $row)
                    <tr>
                        <td class="px-4 py-2"><a href="{{ $profileUrl($row['profile_id']) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ $row['profile_id'] }}</a></td>
                        <td class="px-4 py-2 text-gray-800 dark:text-gray-200">{{ $row['change_date'] ?? '-' }}</td>
                        <td class="px-4 py-2 text-right">{{ $row['change_count'] }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="3" class="px-4 py-2 text-gray-500">No data</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="mb-6">
            <h2 class="text-lg font-semibold text-gray-700 dark:text-gray-200 mb-3">Batch change summary</h2>
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-600">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Batch ID</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Profile</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Source</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Count</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-600">
                    @forelse($batchSummaries as $row)
                    <tr>
                        <td class="px-4 py-2 text-gray-800 dark:text-gray-200">{{ $row['batch_id'] ?? '-' }}</td>
                        <td class="px-4 py-2">
                            @if(isset($row['profile_id']) && $row['profile_id'] !== null)
                                <a href="{{ $profileUrl($row['profile_id']) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ $row['profile_id'] }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-2 text-gray-800 dark:text-gray-200">{{ $row['source'] ?? '-' }}</td>
                        <td class="px-4 py-2 text-right">{{ $row['change_count'] }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="px-4 py-2 text-gray-500">No data</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </div>
</div>
@endsection

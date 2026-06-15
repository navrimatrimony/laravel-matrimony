@php
    $rows = $customerListRows ?? [];
@endphp

<section class="{{ ($activeDashboardTab ?? '') !== 'profiles' ? 'hidden ' : '' }}rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
    <div class="flex flex-col gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-700 md:flex-row md:items-center md:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('suchak.dashboard.customer_list_title') }}</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('suchak.dashboard.customer_list_intro') }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('suchak.intakes.create') }}" class="inline-flex rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                {{ __('suchak.dashboard.add_biodata') }}
            </a>
            <a href="{{ route('suchak.manual-profiles.create') }}" class="inline-flex rounded-md border border-indigo-300 px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 dark:border-indigo-700 dark:text-indigo-200 dark:hover:bg-indigo-950/40">
                {{ __('suchak.dashboard.add_manual_profile') }}
            </a>
        </div>
    </div>

    @if ($rows === [])
        <div class="p-6 text-sm text-gray-600 dark:text-gray-300">
            {{ __('suchak.dashboard.customer_list_empty') }}
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3">{{ __('suchak.dashboard.customer_col_photo') }}</th>
                        <th class="px-4 py-3">{{ __('suchak.dashboard.customer_col_id') }}</th>
                        <th class="px-4 py-3">{{ __('suchak.dashboard.customer_col_name') }}</th>
                        <th class="px-4 py-3">{{ __('suchak.dashboard.customer_col_age') }}</th>
                        <th class="px-4 py-3">{{ __('suchak.dashboard.customer_col_gender') }}</th>
                        <th class="px-4 py-3">{{ __('suchak.dashboard.customer_col_address') }}</th>
                        <th class="px-4 py-3">{{ __('suchak.dashboard.customer_col_status') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('suchak.dashboard.customer_col_actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ($rows as $row)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-900/40">
                            <td class="px-4 py-3">
                                <img src="{{ $row['photo_url'] }}" alt="" class="h-12 w-12 rounded-full border border-gray-200 object-cover dark:border-gray-700">
                            </td>
                            <td class="px-4 py-3 align-top">
                                @if ($row['profile_id'])
                                    <div class="font-semibold text-gray-900 dark:text-gray-100">#{{ $row['profile_id'] }}</div>
                                    @if ($row['representation_id'])
                                        <div class="text-xs text-gray-500 dark:text-gray-400">Rep #{{ $row['representation_id'] }}</div>
                                    @endif
                                @elseif ($row['intake_id'])
                                    <div class="font-semibold text-gray-900 dark:text-gray-100">{{ __('suchak.dashboard.customer_intake_label', ['id' => $row['intake_id']]) }}</div>
                                    @if ($row['source_link_id'])
                                        <div class="text-xs text-gray-500 dark:text-gray-400">Source #{{ $row['source_link_id'] }}</div>
                                    @endif
                                @else
                                    <span class="text-gray-500 dark:text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 align-top font-medium text-gray-900 dark:text-gray-100">{{ $row['name'] }}</td>
                            <td class="px-4 py-3 align-top text-gray-700 dark:text-gray-300">{{ $row['age'] ?? '—' }}</td>
                            <td class="px-4 py-3 align-top text-gray-700 dark:text-gray-300">{{ $row['gender'] ?? '—' }}</td>
                            <td class="px-4 py-3 align-top text-gray-700 dark:text-gray-300">{{ $row['address'] }}</td>
                            <td class="px-4 py-3 align-top">
                                <div class="flex flex-col gap-1">
                                    <span class="inline-flex w-fit rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-gray-900 dark:text-gray-200">{{ $row['status_label'] }}</span>
                                    @if ($row['consent_label'])
                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('suchak.dashboard.customer_consent') }}: {{ $row['consent_label'] }}</span>
                                    @endif
                                    @if ($row['lifecycle_label'])
                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $row['lifecycle_label'] }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3 align-top">
                                <div class="flex flex-wrap justify-end gap-2">
                                    @if ($row['view_url'])
                                        <a href="{{ $row['view_url'] }}" class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                                            {{ __('suchak.dashboard.customer_view') }}
                                        </a>
                                    @endif
                                    @if ($row['edit_url'])
                                        <a href="{{ $row['edit_url'] }}" class="rounded-md border border-indigo-300 px-3 py-1.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-50 dark:border-indigo-700 dark:text-indigo-200 dark:hover:bg-indigo-950/40">
                                            {{ __('suchak.dashboard.customer_edit_profile') }}
                                        </a>
                                    @endif
                                    @if ($row['manage_url'])
                                        <a href="{{ $row['manage_url'] }}" class="rounded-md bg-gray-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">
                                            {{ __('suchak.dashboard.customer_manage') }}
                                        </a>
                                    @endif
                                    @if ($row['review_url'])
                                        <a href="{{ $row['review_url'] }}" class="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">
                                            {{ __('suchak.dashboard.customer_review') }}
                                        </a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

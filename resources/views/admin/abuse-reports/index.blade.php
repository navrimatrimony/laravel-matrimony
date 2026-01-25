@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
    <div class="p-6">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-6">Abuse Reports</h1>
            <table class="w-full border-collapse">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50">
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">ID</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Profile</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Reporter</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Reason</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Status</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Profile status</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Actions</th>
                    </tr>
                </thead>
                <tbody>
            @forelse ($reports as $report)
                @php
                    // Determine profile status using existing fields
                    $profileStatus = 'Active';
                    $profileStatusColor = '#059669'; // green
                    
                    if ($report->reportedProfile) {
                        if ($report->reportedProfile->trashed()) {
                            $profileStatus = 'Deleted';
                            $profileStatusColor = '#6b7280'; // gray
                        } elseif ($report->reportedProfile->is_suspended) {
                            $profileStatus = 'Suspended';
                            $profileStatusColor = '#f59e0b'; // orange/amber
                        }
                    } else {
                        // Profile not found (shouldn't happen per SSOT, but handle gracefully)
                        $profileStatus = 'Not Found';
                        $profileStatusColor = '#ef4444'; // red
                    }
                @endphp
                <tr class="border-b border-gray-100 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700/30">
                    <td class="py-3 px-4">{{ $report->id }}</td>
                    <td class="py-3 px-4">
                        <a href="{{ route('admin.profiles.show', $report->reported_profile_id) }}" class="text-indigo-600 hover:underline font-medium">
                            {{ $report->reported_profile_id }}
                        </a>
                    </td>
                    <td class="py-3 px-4">{{ $report->reporter_user_id }}</td>
                    <td class="py-3 px-4 text-gray-700">{{ Str::limit($report->reason, 60) }}</td>
                    <td class="py-3 px-4">{{ $report->status }}</td>
                    <td class="py-3 px-4">
                        <span class="inline-block px-2 py-1 rounded text-xs font-medium text-white" style="background:{{ $profileStatusColor }};">{{ $profileStatus }}</span>
                    </td>
                    <td class="py-3 px-4">
                        @if ($report->status === 'open')
                            <form method="POST" action="{{ route('admin.abuse-reports.resolve', $report) }}" class="space-y-2">
                                @csrf
                                <textarea name="reason" rows="2" required placeholder="Resolution reason (min 10 chars)" class="w-full border border-gray-300 rounded px-2 py-1 text-sm"></textarea>
                                <button type="submit" style="background-color: #059669; color: white; padding: 6px 12px; border-radius: 4px; font-weight: 600; font-size: 14px; border: none; cursor: pointer;">Mark resolved</button>
                                <p class="text-xs text-gray-500">Closes report only. Profile moderation is separate.</p>
                            </form>
                        @else
                            <span class="text-emerald-600 font-medium">Resolved</span>
                            @if ($report->resolution_reason)
                                <br><span class="text-sm text-gray-500">{{ Str::limit($report->resolution_reason, 50) }}</span>
                            @endif
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="py-8 px-4 text-center text-gray-500">No abuse reports.</td>
                </tr>
            @endforelse
                </tbody>
            </table>
    </div>
    <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/30">
        {{ $reports->links() }}
    </div>
</div>
@endsection

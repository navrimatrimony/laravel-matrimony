@extends('layouts.app')

@section('content')
<div class="max-w-5xl mx-auto py-8">
    <h1 class="text-2xl font-bold mb-6">Admin — Abuse Reports</h1>

    @if (session('success'))
        <p style="color:green; margin-bottom:1rem;">{{ session('success') }}</p>
    @endif
    @if (session('error'))
        <p style="color:red; margin-bottom:1rem;">{{ session('error') }}</p>
    @endif

    <table border="1" cellpadding="8" cellspacing="0" style="width:100%; border-collapse:collapse;">
        <thead>
            <tr>
                <th>ID</th>
                <th>Reported Profile ID</th>
                <th>Reporter User ID</th>
                <th>Reason</th>
                <th>Report Status</th>
                <th>Profile Status</th>
                <th>Actions</th>
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
                <tr>
                    <td>{{ $report->id }}</td>
                    <td>
                        <a href="{{ route('admin.profiles.show', $report->reported_profile_id) }}" 
                           style="color:#2563eb; text-decoration:underline;" 
                           target="_blank">
                            {{ $report->reported_profile_id }}
                        </a>
                    </td>
                    <td>{{ $report->reporter_user_id }}</td>
                    <td>{{ $report->reason }}</td>
                    <td>{{ $report->status }}</td>
                    <td>
                        <span style="display:inline-block; padding:4px 8px; background:{{ $profileStatusColor }}; color:white; border-radius:4px; font-size:0.875em; font-weight:500;">
                            {{ $profileStatus }}
                        </span>
                    </td>
                    <td>
                        @if ($report->status === 'open')
                            <form method="POST" action="{{ route('admin.abuse-reports.resolve', $report) }}" style="margin:0;">
                                @csrf
                                <textarea name="reason" rows="2" required placeholder="Resolution reason (min 10 chars)" style="width:100%; margin-bottom:6px;"></textarea>
                                <button type="submit" style="padding:6px 12px; background:#059669; color:white; border:none; cursor:pointer;">Mark as Resolved</button>
                                <p style="font-size:0.85em; color:#666; margin-top:4px; margin-bottom:0;">
                                    Note: This closes the report only. Profile moderation (suspend/delete) is separate.
                                </p>
                            </form>
                        @else
                            <span style="color:#059669;">Resolved</span>
                            @if ($report->resolution_reason)
                                <br><small style="color:#666;">{{ Str::limit($report->resolution_reason, 50) }}</small>
                            @endif
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">No abuse reports.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div style="margin-top:1rem;">
        {{ $reports->links() }}
    </div>
</div>
@endsection

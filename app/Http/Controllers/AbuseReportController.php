<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AbuseReport;
use App\Models\MatrimonyProfile;
use App\Services\AuditLogService;

/*
|--------------------------------------------------------------------------
| AbuseReportController
|--------------------------------------------------------------------------
|
| 👉 Handles abuse reporting workflow
| 👉 Users can submit reports, admins can resolve them
|
*/
class AbuseReportController extends Controller
{
    /**
     * Submit an abuse report (user action)
     */
    public function store(Request $request, MatrimonyProfile $matrimonyProfile)
    {
        $request->validate([
            'reason' => 'required|string|min:10',
        ]);

        $user = $request->user();

        // Check if user already reported this profile
        $existingReport = AbuseReport::where('reporter_user_id', $user->id)
            ->where('reported_profile_id', $matrimonyProfile->id)
            ->where('status', 'open')
            ->first();

        if ($existingReport) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'You have already reported this profile. Our team is reviewing it.',
                ], 422);
            }
            return redirect()->back()->with('error', 'You have already reported this profile. Our team is reviewing it.');
        }

        // Create report
        AbuseReport::create([
            'reporter_user_id' => $user->id,
            'reported_profile_id' => $matrimonyProfile->id,
            'reason' => $request->reason,
            'status' => 'open',
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Abuse report submitted successfully.',
            ]);
        }

        return redirect()->back()->with('success', 'Abuse report submitted successfully.');
    }

    /**
     * List all abuse reports (admin only)
     */
    public function index(Request $request)
    {
        $reports = AbuseReport::with(['reporter', 'reportedProfile', 'resolvedBy'])
            ->latest()
            ->paginate(20);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'data' => $reports,
            ]);
        }

        return view('admin.abuse-reports.index', compact('reports'));
    }

    /**
     * Resolve an abuse report (admin action)
     */
    public function resolve(Request $request, AbuseReport $report)
    {
        $request->validate([
            'reason' => 'required|string|min:10',
        ]);

        $admin = $request->user();

        // Update report
        $report->update([
            'status' => 'resolved',
            'resolution_reason' => $request->reason,
            'resolved_by_admin_id' => $admin->id,
            'resolved_at' => now(),
        ]);

        // Audit log
        AuditLogService::log(
            $admin,
            'abuse_resolve',
            'AbuseReport',
            $report->id,
            $request->reason,
            $report->reportedProfile->is_demo ?? false
        );

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Abuse report resolved successfully.',
            ]);
        }

        return redirect()->back()->with('success', 'Abuse report resolved successfully.');
    }
}
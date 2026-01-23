<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MatrimonyProfile;
use App\Services\AuditLogService;
use App\Notifications\ProfileSuspendedNotification;
use App\Notifications\ProfileUnsuspendedNotification;
use App\Notifications\ProfileSoftDeletedNotification;
use App\Notifications\ImageApprovedNotification;
use App\Notifications\ImageRejectedNotification;

/*
|--------------------------------------------------------------------------
| AdminController
|--------------------------------------------------------------------------
|
| 👉 Handles admin moderation actions
| 👉 All actions require mandatory reasons and audit logging
| 👉 All actions operate on MatrimonyProfile (not User)
|
*/
class AdminController extends Controller
{
    /**
     * View a MatrimonyProfile (admin only - bypasses suspension checks)
     */
    public function showProfile($id)
    {
        // Admin can view profiles regardless of suspension or soft-delete status
        // Load with trashed to include soft-deleted profiles
        $matrimonyProfile = MatrimonyProfile::withTrashed()->findOrFail($id);

        // Admin viewing someone else's profile
        $isOwnProfile = false;
        $interestAlreadySent = false;
        $hasAlreadyReported = false; // Admin does not submit abuse reports

        return view(
            'matrimony.profile.show',
            [
                'matrimonyProfile' => $matrimonyProfile,
                'isOwnProfile' => $isOwnProfile,
                'interestAlreadySent' => $interestAlreadySent,
                'hasAlreadyReported' => $hasAlreadyReported,
            ]
        );
    }

    /**
     * Suspend a MatrimonyProfile
     */
    public function suspendProfile(Request $request, MatrimonyProfile $profile)
    {
        $request->validate([
            'reason' => 'required|string|min:10',
        ]);

        $admin = $request->user();

        // Update profile
        $profile->update([
            'is_suspended' => true,
        ]);

        // Audit log
        AuditLogService::log(
            $admin,
            'suspend',
            'MatrimonyProfile',
            $profile->id,
            $request->reason,
            $profile->is_demo ?? false
        );

        // Notify user
        if ($profile->user) {
            $profile->user->notify(new ProfileSuspendedNotification($request->reason));
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Profile suspended successfully.',
            ]);
        }

        return redirect()->back()->with('success', 'Profile suspended successfully.');
    }

    /**
     * Unsuspend a MatrimonyProfile
     */
    public function unsuspendProfile(Request $request, MatrimonyProfile $profile)
    {
        $request->validate([
            'reason' => 'required|string|min:10',
        ]);

        $admin = $request->user();

        // Update profile
        $profile->update([
            'is_suspended' => false,
        ]);

        // Audit log
        AuditLogService::log(
            $admin,
            'unsuspend',
            'MatrimonyProfile',
            $profile->id,
            $request->reason,
            $profile->is_demo ?? false
        );

        // Notify user
        if ($profile->user) {
            $profile->user->notify(new ProfileUnsuspendedNotification($request->reason));
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Profile unsuspended successfully.',
            ]);
        }

        return redirect()->back()->with('success', 'Profile unsuspended successfully.');
    }

    /**
     * Soft delete a MatrimonyProfile (real users only)
     */
    public function softDeleteProfile(Request $request, MatrimonyProfile $profile)
    {
        $request->validate([
            'reason' => 'required|string|min:10',
        ]);

        $admin = $request->user();

        // Check if demo profile (soft delete only for real users)
        if ($profile->is_demo ?? false) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot soft delete demo profiles.',
                ], 403);
            }
            return redirect()->back()->with('error', 'Cannot soft delete demo profiles.');
        }

        // Soft delete profile
        $profile->delete();

        // Audit log
        AuditLogService::log(
            $admin,
            'soft_delete',
            'MatrimonyProfile',
            $profile->id,
            $request->reason,
            false
        );

        // Notify user
        if ($profile->user) {
            $profile->user->notify(new ProfileSoftDeletedNotification($request->reason));
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Profile soft deleted successfully.',
            ]);
        }

        return redirect()->back()->with('success', 'Profile soft deleted successfully.');
    }

    /**
     * Approve profile image
     */
    public function approveImage(Request $request, MatrimonyProfile $profile)
    {
        $request->validate([
            'reason' => 'required|string|min:10',
        ]);

        $admin = $request->user();

        // Update profile
        $profile->update([
            'photo_approved' => true,
            'photo_rejected_at' => null,
            'photo_rejection_reason' => null,
        ]);

        // Audit log
        AuditLogService::log(
            $admin,
            'image_approve',
            'MatrimonyProfile',
            $profile->id,
            $request->reason,
            $profile->is_demo ?? false
        );

        // Notify user
        if ($profile->user) {
            $profile->user->notify(new ImageApprovedNotification($request->reason));
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Image approved successfully.',
            ]);
        }

        return redirect()->back()->with('success', 'Image approved successfully.');
    }

    /**
     * Reject profile image
     */
    public function rejectImage(Request $request, MatrimonyProfile $profile)
    {
        $request->validate([
            'reason' => 'required|string|min:10',
        ]);

        $admin = $request->user();

        // Update profile (hide image immediately)
        $profile->update([
            'photo_approved' => false,
            'photo_rejected_at' => now(),
            'photo_rejection_reason' => $request->reason,
        ]);

        // Audit log
        AuditLogService::log(
            $admin,
            'image_reject',
            'MatrimonyProfile',
            $profile->id,
            $request->reason,
            $profile->is_demo ?? false
        );

        // Notify user
        if ($profile->user) {
            $profile->user->notify(new ImageRejectedNotification($request->reason));
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Image rejected successfully.',
            ]);
        }

        return redirect()->back()->with('success', 'Image rejected successfully.');
    }
}
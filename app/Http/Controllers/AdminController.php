<?php

namespace App\Http\Controllers;

use App\Models\AbuseReport;
use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Models\Shortlist;
use App\Models\User;
use App\Models\AdminSetting;
use App\Models\ProfileFieldConfig;
use App\Notifications\ImageRejectedNotification;
use App\Services\ViewTrackingService;
use App\Notifications\ProfileSoftDeletedNotification;
use App\Notifications\ProfileSuspendedNotification;
use App\Notifications\ProfileUnsuspendedNotification;
use App\Services\AuditLogService;
use App\Services\ProfileCompletenessService;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| AdminController (SSOT Day-6 — Recovery-Day-R1)
|--------------------------------------------------------------------------
|
| Admin moderation: suspend, unsuspend, soft delete, image approve/reject.
| Mandatory reason, audit log, and SSOT-allowed user notifications only.
|
*/
class AdminController extends Controller
{
    private const REASON_RULES = ['required', 'string', 'min:10'];

    /**
     * Admin view profile (bypasses suspension / soft-delete checks).
     */
    public function showProfile(string $id)
    {
        $profile = MatrimonyProfile::withTrashed()->findOrFail($id);
        $user = auth()->user();
        $isOwnProfile = $user->matrimonyProfile && $user->matrimonyProfile->id === (int) $id;

        $interestAlreadySent = false;
        if ($user->matrimonyProfile) {
            $interestAlreadySent = Interest::where('sender_profile_id', $user->matrimonyProfile->id)
                ->where('receiver_profile_id', $profile->id)
                ->exists();
        }

        $hasAlreadyReported = AbuseReport::where('reporter_user_id', $user->id)
            ->where('reported_profile_id', $profile->id)
            ->where('status', 'open')
            ->exists();

        $inShortlist = false;
        if (!$isOwnProfile && $user->matrimonyProfile) {
            $inShortlist = Shortlist::where('owner_profile_id', $user->matrimonyProfile->id)
                ->where('shortlisted_profile_id', $profile->id)
                ->exists();
        }

        if (!$isOwnProfile && $user->matrimonyProfile) {
            ViewTrackingService::recordView($user->matrimonyProfile, $profile);
            ViewTrackingService::maybeTriggerViewBack($user->matrimonyProfile, $profile);
        }

        // Profile completeness (from service, passed to view)
        $completenessPct = ProfileCompletenessService::percentage($profile);

        return view('matrimony.profile.show', [
            'matrimonyProfile' => $profile,
            'isOwnProfile' => $isOwnProfile,
            'interestAlreadySent' => $interestAlreadySent,
            'hasAlreadyReported' => $hasAlreadyReported,
            'inShortlist' => $inShortlist,
            'completenessPct' => $completenessPct,
        ]);
    }

    /**
     * Suspend profile. Audit log + notify user.
     */
    public function suspendProfile(Request $request, MatrimonyProfile $profile): \Illuminate\Http\RedirectResponse
    {
        $request->validate(['reason' => self::REASON_RULES]);

        $profile->update(['is_suspended' => true]);

        AuditLogService::log(
            $request->user(),
            'suspend',
            'MatrimonyProfile',
            $profile->id,
            $request->reason,
            (bool) ($profile->is_demo ?? false)
        );

        $owner = $profile->user;
        if ($owner) {
            $owner->notify(new ProfileSuspendedNotification($request->reason));
        }

        return redirect()->route('admin.profiles.show', $profile->id)->with('success', 'Profile suspended.');
    }

    /**
     * Unsuspend profile. Audit log + notify user.
     */
    public function unsuspendProfile(Request $request, MatrimonyProfile $profile): \Illuminate\Http\RedirectResponse
    {
        $request->validate(['reason' => self::REASON_RULES]);

        $profile->update(['is_suspended' => false]);

        AuditLogService::log(
            $request->user(),
            'unsuspend',
            'MatrimonyProfile',
            $profile->id,
            $request->reason,
            (bool) ($profile->is_demo ?? false)
        );

        $owner = $profile->user;
        if ($owner) {
            $owner->notify(new ProfileUnsuspendedNotification($request->reason));
        }

        return redirect()->route('admin.profiles.show', $profile->id)->with('success', 'Profile unsuspended.');
    }

    /**
     * Soft delete profile. Audit log + notify user. No hard delete.
     */
    public function softDeleteProfile(Request $request, MatrimonyProfile $profile): \Illuminate\Http\RedirectResponse
    {
        $request->validate(['reason' => self::REASON_RULES]);

        $profileId = $profile->id;
        $owner = $profile->user;
        $isDemo = (bool) ($profile->is_demo ?? false);

        $profile->delete();

        AuditLogService::log(
            $request->user(),
            'soft_delete',
            'MatrimonyProfile',
            $profileId,
            $request->reason,
            $isDemo
        );

        if ($owner) {
            $owner->notify(new ProfileSoftDeletedNotification($request->reason));
        }

        return redirect()->route('admin.profiles.show', $profileId)->with('success', 'Profile soft deleted.');
    }

    /**
     * Approve profile image. Audit log only. NO user notification (SSOT-forbidden).
     */
    public function approveImage(Request $request, MatrimonyProfile $profile): \Illuminate\Http\RedirectResponse
    {
        $request->validate(['reason' => self::REASON_RULES]);

        $profile->update([
            'photo_approved' => true,
            'photo_rejected_at' => null,
            'photo_rejection_reason' => null,
        ]);

        AuditLogService::log(
            $request->user(),
            'image_approve',
            'MatrimonyProfile',
            $profile->id,
            $request->reason,
            (bool) ($profile->is_demo ?? false)
        );

        return redirect()->route('admin.profiles.show', $profile->id)->with('success', 'Image approved.');
    }

    /**
     * Reject profile image. Audit log + notify user.
     */
    public function rejectImage(Request $request, MatrimonyProfile $profile): \Illuminate\Http\RedirectResponse
    {
        $request->validate(['reason' => self::REASON_RULES]);

        $profile->update([
            'photo_approved' => false,
            'photo_rejected_at' => now(),
            'photo_rejection_reason' => $request->reason,
        ]);

        AuditLogService::log(
            $request->user(),
            'image_reject',
            'MatrimonyProfile',
            $profile->id,
            $request->reason,
            (bool) ($profile->is_demo ?? false)
        );

        $owner = $profile->user;
        if ($owner) {
            $owner->notify(new ImageRejectedNotification($request->reason));
        }

        return redirect()->route('admin.profiles.show', $profile->id)->with('success', 'Image rejected.');
    }

    /**
     * Override visibility: force profile visible in search even if <70% complete.
     * Mandatory reason, audit log, is_demo. Affects search only; interest rules unchanged.
     */
    public function overrideVisibility(Request $request, MatrimonyProfile $profile): \Illuminate\Http\RedirectResponse
    {
        $request->validate(['reason' => self::REASON_RULES]);

        $profile->update([
            'visibility_override' => true,
            'visibility_override_reason' => $request->reason,
        ]);

        AuditLogService::log(
            $request->user(),
            'visibility_override',
            'MatrimonyProfile',
            $profile->id,
            $request->reason,
            (bool) ($profile->is_demo ?? false)
        );

        return redirect()->route('admin.profiles.show', $profile->id)->with('success', 'Visibility override applied.');
    }

    /**
     * View-back settings (Day-9). Enable/disable, probability 0–100, delay min/max.
     */
    public function viewBackSettings()
    {
        $enabled = AdminSetting::getBool('view_back_enabled', false);
        $probability = (int) AdminSetting::getValue('view_back_probability', '0');
        $probability = max(0, min(100, $probability));
        $delayMin = (int) AdminSetting::getValue('view_back_delay_min', '0');
        $delayMax = (int) AdminSetting::getValue('view_back_delay_max', '0');
        return view('admin.view-back-settings.index', [
            'viewBackEnabled' => $enabled,
            'viewBackProbability' => $probability,
            'viewBackDelayMin' => max(0, $delayMin),
            'viewBackDelayMax' => max(0, $delayMax),
        ]);
    }

    /**
     * Update view-back settings. Persisted via AdminSetting.
     */
    public function updateViewBackSettings(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'view_back_enabled' => 'nullable|in:0,1',
            'view_back_probability' => 'required|integer|min:0|max:100',
            'view_back_delay_min' => 'required|integer|min:0|max:1440',
            'view_back_delay_max' => 'required|integer|min:0|max:1440',
        ]);

        $delayMin = (int) $request->input('view_back_delay_min', 0);
        $delayMax = (int) $request->input('view_back_delay_max', 0);

        // Ensure max >= min
        if ($delayMax < $delayMin) {
            $delayMax = $delayMin;
        }

        $enabled = $request->has('view_back_enabled') ? '1' : '0';
        $probability = (string) $request->input('view_back_probability', 0);

        AdminSetting::setValue('view_back_enabled', $enabled);
        AdminSetting::setValue('view_back_probability', $probability);
        AdminSetting::setValue('view_back_delay_min', (string) $delayMin);
        AdminSetting::setValue('view_back_delay_max', (string) $delayMax);

        AuditLogService::log(
            $request->user(),
            'update_view_back_settings',
            'AdminSetting',
            null,
            "enabled={$enabled}, probability={$probability}%, delay={$delayMin}-{$delayMax}min",
            false
        );

        return redirect()->route('admin.view-back-settings.index')
            ->with('success', 'View-back settings updated.');
    }

    /**
     * Demo search visibility (Day-8). Global toggle: show/hide demo profiles in search.
     */
    public function demoSearchSettings()
    {
        $visible = AdminSetting::getBool('demo_profiles_visible_in_search', true);
        return view('admin.demo-search-settings.index', [
            'demoProfilesVisibleInSearch' => $visible,
        ]);
    }

    /**
     * Update demo search visibility. Persisted via AdminSetting.
     */
    public function updateDemoSearchSettings(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'demo_profiles_visible_in_search' => 'nullable|in:0,1',
        ]);

        $visible = $request->has('demo_profiles_visible_in_search') ? '1' : '0';
        AdminSetting::setValue('demo_profiles_visible_in_search', $visible);

        AuditLogService::log(
            $request->user(),
            'update_demo_search_settings',
            'AdminSetting',
            null,
            "demo_profiles_visible_in_search={$visible}",
            false
        );

        return redirect()->route('admin.demo-search-settings.index')
            ->with('success', 'Demo search visibility updated.');
    }

    /**
     * Admin debug: view notifications for any user (R5).
     * Form to enter user ID, then view that user's notifications (read-only).
     */
    public function userNotificationsIndex()
    {
        return view('admin.notifications.index');
    }

    /**
     * List all profile field configurations (Day-17).
     */
    public function profileFieldConfigIndex()
    {
        $fieldConfigs = ProfileFieldConfig::orderBy('field_key')->get();
        return view('admin.profile-field-config.index', [
            'fieldConfigs' => $fieldConfigs,
        ]);
    }

    /**
     * Update profile field configuration flags (Day-17).
     * Bulk update: updates all fields in single request.
     */
    public function profileFieldConfigUpdate(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'reason' => self::REASON_RULES,
            'fields' => 'required|array',
            'fields.*.id' => 'required|integer|exists:profile_field_configs,id',
            // Checkboxes are optional (absent = unchecked)
            'fields.*.is_enabled' => 'sometimes|in:1',
            'fields.*.is_visible' => 'sometimes|in:1',
            'fields.*.is_searchable' => 'sometimes|in:1',
            'fields.*.is_mandatory' => 'sometimes|in:1',
        ]);

        $updatedCount = 0;
        $changes = [];

        foreach ($request->input('fields', []) as $fieldData) {
            $field = ProfileFieldConfig::findOrFail($fieldData['id']);
            $original = [
                'is_enabled' => $field->is_enabled,
                'is_visible' => $field->is_visible,
                'is_searchable' => $field->is_searchable,
                'is_mandatory' => $field->is_mandatory,
            ];

            // HTML checkboxes: present = checked (value='1'), absent = unchecked
            $updates = [
                'is_enabled' => isset($fieldData['is_enabled']) && $fieldData['is_enabled'] == '1',
                'is_visible' => isset($fieldData['is_visible']) && $fieldData['is_visible'] == '1',
                'is_searchable' => isset($fieldData['is_searchable']) && $fieldData['is_searchable'] == '1',
                'is_mandatory' => isset($fieldData['is_mandatory']) && $fieldData['is_mandatory'] == '1',
            ];

            // Only update if there are actual changes
            if ($updates !== $original) {
                $field->update($updates);
                $updatedCount++;
                $fieldChanges = [];
                foreach ($updates as $key => $value) {
                    if ($value !== $original[$key]) {
                        $fieldChanges[] = "{$key}: " . ($original[$key] ? 'true' : 'false') . " → " . ($value ? 'true' : 'false');
                    }
                }
                $changes[] = $field->field_key . ' (' . implode(', ', $fieldChanges) . ')';
            }
        }

        if ($updatedCount > 0) {
            AuditLogService::log(
                $request->user(),
                'profile_field_config_update',
                'profile_field_configs',
                null,
                "Updated {$updatedCount} field(s). Changes: " . implode('; ', $changes) . ". Reason: {$request->reason}",
                false
            );
        }

        return redirect()->route('admin.profile-field-config.index')
            ->with('success', "Updated {$updatedCount} field configuration(s).");
    }

    /**
     * Admin debug: list notifications for user (user_id query). Read-only, no actions.
     */
    public function userNotificationsShow(Request $request)
    {
        $request->validate(['user_id' => 'required|integer|min:1']);
        $user = User::findOrFail($request->user_id);
        $notifications = $user->notifications()->orderByDesc('created_at')->paginate(50)->withQueryString();
        return view('admin.notifications.user', [
            'targetUser' => $user,
            'notifications' => $notifications,
        ]);
    }

    /**
     * Admin update profile (edit mode)
     * Updates profile fields and tracks which fields were edited by admin
     */
    public function updateProfile(Request $request, MatrimonyProfile $profile)
    {
        // Guard: Admin only
        if (!auth()->check() || !auth()->user()->is_admin) {
            abort(403, 'Admin access required');
        }

        // Validate edit reason (mandatory)
        $request->validate([
            'edit_reason' => self::REASON_RULES,
            'full_name' => 'nullable|string|max:255',
            'date_of_birth' => 'nullable|date',
            'marital_status' => 'nullable|in:single,divorced,widowed',
            'education' => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255',
            'caste' => 'nullable|string|max:255',
        ]);

        $admin = auth()->user();
        $originalData = $profile->only(['full_name', 'date_of_birth', 'marital_status', 'education', 'location', 'caste']);
        $editedFields = [];

        // Track which fields were actually changed
        $updateData = [];
        $editableFields = ['full_name', 'date_of_birth', 'marital_status', 'education', 'location', 'caste'];
        
        foreach ($editableFields as $field) {
            if ($request->has($field)) {
                $newValue = $request->input($field);
                $oldValue = $originalData[$field] ?? null;
                
                // Normalize for comparison
                $newValue = $newValue === '' ? null : $newValue;
                $oldValue = $oldValue === '' ? null : $oldValue;
                
                if ($newValue != $oldValue) {
                    $updateData[$field] = $newValue;
                    $editedFields[] = $field;
                }
            }
        }

        // If no fields changed, return with error
        if (empty($editedFields)) {
            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'No changes detected. Please modify at least one field.');
        }

        // Merge with existing admin_edited_fields
        $existingAdminEditedFields = $profile->admin_edited_fields ?? [];
        $mergedAdminEditedFields = array_unique(array_merge($existingAdminEditedFields, $editedFields));

        // Update profile with edited fields and metadata
        $updateData['edited_by'] = $admin->id;
        $updateData['edited_at'] = now();
        $updateData['edit_reason'] = $request->input('edit_reason');
        $updateData['edited_source'] = 'admin';
        $updateData['admin_edited_fields'] = $mergedAdminEditedFields;

        $profile->update($updateData);

        // Create audit log entry
        AuditLogService::log(
            $admin,
            'profile_edit',
            'MatrimonyProfile',
            $profile->id,
            $request->input('edit_reason'),
            $profile->is_demo ?? false
        );

        return redirect()
            ->route('admin.profiles.show', $profile)
            ->with('success', 'Profile updated successfully. Edited fields: ' . implode(', ', $editedFields));
    }
}

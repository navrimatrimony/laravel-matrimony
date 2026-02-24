<?php

namespace App\Http\Controllers;

use App\Models\AbuseReport;
use App\Models\ConflictRecord;
use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Models\Shortlist;
use App\Models\User;
use App\Models\AdminSetting;
use App\Models\ProfileFieldConfig;
use App\Models\FieldRegistry;
use App\Models\VerificationTag;
use App\Notifications\ImageRejectedNotification;
use App\Services\ViewTrackingService;
use App\Notifications\ProfileSoftDeletedNotification;
use App\Notifications\ProfileSuspendedNotification;
use App\Notifications\ProfileUnsuspendedNotification;
use App\Services\AuditLogService;
use App\Services\ConflictDetectionService;
use App\Services\ConflictResolutionService;
use App\Services\OcrMode;
use App\Services\OcrModeDetectionService;
use App\Services\OcrGovernanceService;
use App\Services\ExtendedFieldDependencyService;
use App\Services\ExtendedFieldService;
use App\Services\ProfileCompletenessService;
use App\Services\ProfileFieldLockService;
use App\Services\ProfileLifecycleService;
use App\Services\FieldValueHistoryService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
     * Phase-5B: Build admin snapshot (same structure as manual). Only include changed core + optional extended.
     * No DB write. Used with MutationService::applyManualSnapshot(..., 'admin').
     */
    private function buildAdminSnapshot(MatrimonyProfile $profile, array $coreOverrides, array $extendedFields = []): array
    {
        $snapshot = [
            'core' => $coreOverrides,
        ];
        if ($extendedFields !== []) {
            $snapshot['extended_fields'] = $extendedFields;
        }
        return $snapshot;
    }

    /**
     * Admin profiles list (all profiles, includes suspended/trashed).
     */
    public function profilesIndex(Request $request)
    {
        $perPage = (int) $request->input('per_page', 15);
        $perPage = $perPage >= 1 && $perPage <= 100 ? $perPage : 15;
        $profiles = MatrimonyProfile::withTrashed()->with(['country', 'state', 'district', 'taluka', 'city'])->latest()->paginate($perPage)->withQueryString();
        return view('admin.profiles.index', compact('profiles'));
    }

    /**
     * Admin view profile (bypasses suspension / soft-delete checks).
     */
    public function showProfile(string $id)
    {
        $profile = MatrimonyProfile::withTrashed()->findOrFail($id);
        $profile->load(['country', 'state', 'district', 'taluka', 'city', 'religion', 'caste', 'subCaste']);

        $religions = \App\Models\Religion::where('is_active', true)
            ->orderBy('label')
            ->get();
        $castes = \App\Models\Caste::where('is_active', true)
            ->orderBy('label')
            ->get();
        $subCastes = \App\Models\SubCaste::where('is_active', true)
            ->orderBy('label')
            ->get();

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

        // STEP A: BASELINE - BEFORE any edit
        \Log::info('A_BASELINE', [
            'profile_id' => $profile->id,
            'db_caste' => $profile->caste,
            'pct' => \App\Services\ProfileCompletenessService::percentage($profile),
        ]);
        
        // STEP D: INTERNAL CHECK - Log enabled & mandatory inputs
        \Log::info('D_INPUTS', [
            'mandatory' => \App\Models\FieldRegistry::where('field_type', 'CORE')->where('is_mandatory', true)->pluck('field_key')->values(),
            'enabled' => \App\Services\ProfileFieldConfigurationService::getEnabledFieldKeys(),
            'used' => array_values(array_intersect(
                \App\Models\FieldRegistry::where('field_type', 'CORE')->where('is_mandatory', true)->pluck('field_key')->toArray(),
                \App\Services\ProfileFieldConfigurationService::getEnabledFieldKeys()
            )),
        ]);
        
        // Profile completeness (from service, passed to view)
        $completenessPct = ProfileCompletenessService::percentage($profile);

        // Day-6: Field lock info for admin visibility (read-only)
        $fieldLocks = ProfileFieldLockService::getLocksForProfile($profile);

        // Day 7: Lifecycle state — allowed transition targets
        $lifecycleAllowedTargets = ProfileLifecycleService::getAllowedTargets($profile->lifecycle_state ?? 'active');

        // Day 8: Field value history (read-only)
        $fieldHistory = FieldValueHistoryService::getHistoryForProfile($profile);

        $assignedTags = VerificationTag::query()
            ->select('verification_tags.*')
            ->join('profile_verification_tag', 'verification_tags.id', '=', 'profile_verification_tag.verification_tag_id')
            ->where('profile_verification_tag.matrimony_profile_id', $profile->id)
            ->whereNull('profile_verification_tag.deleted_at')
            ->whereNull('verification_tags.deleted_at')
            ->orderBy('verification_tags.name')
            ->get();

        $activeVerificationTags = VerificationTag::query()
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();

        return view('admin.profiles.show', [
            'matrimonyProfile' => $profile,
            'isOwnProfile' => $isOwnProfile,
            'interestAlreadySent' => $interestAlreadySent,
            'hasAlreadyReported' => $hasAlreadyReported,
            'inShortlist' => $inShortlist,
            'completenessPct' => $completenessPct,
            'fieldLocks' => $fieldLocks,
            'lifecycleAllowedTargets' => $lifecycleAllowedTargets,
            'fieldHistory' => $fieldHistory,
            'assignedTags' => $assignedTags,
            'activeVerificationTags' => $activeVerificationTags,
            'religions' => $religions,
            'castes' => $castes,
            'subCastes' => $subCastes,
        ]);
    }

    /**
     * Suspend profile. Audit log + notify user.
     */
    public function suspendProfile(Request $request, MatrimonyProfile $profile): \Illuminate\Http\RedirectResponse
    {
        $request->validate(['reason' => self::REASON_RULES]);

        $snapshot = $this->buildAdminSnapshot($profile, ['is_suspended' => true]);
        app(\App\Services\MutationService::class)->applyManualSnapshot($profile, $snapshot, (int) $request->user()->id, 'admin');

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

        $snapshot = $this->buildAdminSnapshot($profile, ['is_suspended' => false]);
        app(\App\Services\MutationService::class)->applyManualSnapshot($profile, $snapshot, (int) $request->user()->id, 'admin');

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

        $snapshot = $this->buildAdminSnapshot($profile, [
            'photo_approved' => true,
            'photo_rejected_at' => null,
            'photo_rejection_reason' => null,
        ]);
        app(\App\Services\MutationService::class)->applyManualSnapshot($profile, $snapshot, (int) $request->user()->id, 'admin');

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

        $snapshot = $this->buildAdminSnapshot($profile, [
            'photo_approved' => false,
            'photo_rejected_at' => now(),
            'photo_rejection_reason' => $request->reason,
        ]);
        app(\App\Services\MutationService::class)->applyManualSnapshot($profile, $snapshot, (int) $request->user()->id, 'admin');

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

        $snapshot = $this->buildAdminSnapshot($profile, [
            'visibility_override' => true,
            'visibility_override_reason' => $request->reason,
        ]);
        app(\App\Services\MutationService::class)->applyManualSnapshot($profile, $snapshot, (int) $request->user()->id, 'admin');

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
     * Day 7: Change profile lifecycle_state. Validates transitions via ProfileLifecycleService.
     * Day-7 Enhancement: Role guard (super_admin only) + mandatory reason enforcement.
     */
    public function updateLifecycleState(Request $request, MatrimonyProfile $profile): \Illuminate\Http\RedirectResponse
    {
        // Day-7: super_admin only
        if (!$request->user()->hasAdminRole(['super_admin'])) {
            abort(403, 'This action requires super_admin role');
        }

        // Day-7: Reason is mandatory
        $request->validate([
            'lifecycle_state' => ['required', 'string', 'in:' . implode(',', ProfileLifecycleService::getStates())],
            'reason' => ['required', 'string', 'min:10'],
        ]);

        ProfileLifecycleService::transitionTo($profile, $request->lifecycle_state, $request->user());

        // Day-7: Record audit log for lifecycle state change
        AuditLogService::log(
            $request->user(),
            'lifecycle_state_changed',
            'matrimony_profile',
            $profile->id,
            $request->reason,
            false
        );

        return redirect()->route('admin.profiles.show', $profile->id)->with('success', 'Lifecycle state updated to ' . $request->lifecycle_state);
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
     * Phase-3 Day 1 — Field Registry (read-only). CORE fields only.
     */
    public function fieldRegistryIndex()
    {
        $fields = FieldRegistry::where('field_type', 'CORE')
            ->orderBy('category')
            ->orderBy('display_order')
            ->get();
        return view('admin.field-registry.index', ['fields' => $fields]);
    }

    /**
     * Phase-3 Day 2 — EXTENDED Fields list (read-only).
     */
    public function extendedFieldsIndex()
    {
        $fields = FieldRegistry::where('field_type', 'EXTENDED')
            ->orderBy('category')
            ->orderBy('display_order')
            ->get();
        return view('admin.field-registry.extended.index', ['fields' => $fields]);
    }

    /**
     * Phase-3 Day 2 — EXTENDED Field creation form.
     */
    public function extendedFieldsCreate()
    {
        $extendedFields = FieldRegistry::where('field_type', 'EXTENDED')
            ->orderBy('field_key')
            ->get();
        return view('admin.field-registry.extended.create', ['extendedFields' => $extendedFields]);
    }

    /**
     * Phase-3 Day 2 — Store new EXTENDED field definition.
     */
    public function extendedFieldsStore(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'field_key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/', 'unique:field_registry,field_key'],
            'data_type' => ['required', 'in:text,number,date,boolean,select'],
            'display_label' => ['required', 'string', 'max:128'],
            'category' => ['nullable', 'string', 'max:64'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'parent_field_key' => ['nullable', 'string', 'max:64'],
            'dependency_type' => ['nullable', 'in:equals,present'],
            'dependency_value' => ['nullable', 'string', 'max:255'],
        ], [
            'field_key.regex' => 'Field key must contain only lowercase letters, numbers, and underscores.',
            'field_key.unique' => 'This field key already exists.',
            'data_type.in' => 'Data type must be one of: text, number, date, boolean, select.',
        ]);

        $parentKey = isset($validated['parent_field_key']) && $validated['parent_field_key'] !== '' ? trim($validated['parent_field_key']) : null;
        $depType = $validated['dependency_type'] ?? null;
        $depValue = $validated['dependency_value'] ?? null;
        if ($parentKey !== null) {
            $tempField = new FieldRegistry();
            $tempField->field_key = $validated['field_key'];
            $tempField->field_type = 'EXTENDED';
            ExtendedFieldDependencyService::validateDependency(
                $tempField,
                $parentKey,
                ExtendedFieldDependencyService::buildCondition($depType ?? 'present', $depValue),
                'Dependency '
            );
        }
        $condition = $parentKey !== null
            ? ExtendedFieldDependencyService::buildCondition($depType ?? 'present', $depType === 'equals' ? $depValue : null)
            : null;

        FieldRegistry::create([
            'field_key' => $validated['field_key'],
            'field_type' => 'EXTENDED',
            'data_type' => $validated['data_type'],
            'display_label' => $validated['display_label'],
            'category' => $validated['category'] ?? 'basic',
            'display_order' => $validated['display_order'] ?? 0,
            'is_enabled' => true,
            'is_mandatory' => false,
            'is_searchable' => false,
            'is_user_editable' => true,
            'is_system_overwritable' => true,
            'lock_after_user_edit' => true,
            'is_archived' => false,
            'parent_field_key' => $parentKey,
            'dependency_condition' => $condition,
        ]);

        return redirect()->route('admin.field-registry.extended.index')
            ->with('success', 'EXTENDED field created successfully.');
    }

    /**
     * Day 8: Archive field (soft). No delete. Hidden from new entry.
     */
    public function archiveFieldRegistry(FieldRegistry $field): \Illuminate\Http\RedirectResponse
    {
        if (!auth()->check() || !auth()->user()->is_admin) {
            abort(403, 'Admin access required');
        }
        $field->update(['is_archived' => true]);
        return redirect()->back()->with('success', 'Field archived. Existing profile values unchanged.');
    }

    /**
     * Day 8: Unarchive field. Reactivate for new entry.
     */
    public function unarchiveFieldRegistry(FieldRegistry $field): \Illuminate\Http\RedirectResponse
    {
        if (!auth()->check() || !auth()->user()->is_admin) {
            abort(403, 'Admin access required');
        }
        $field->update(['is_archived' => false]);
        return redirect()->back()->with('success', 'Field unarchived.');
    }

    /**
     * Day 9/10: Bulk update EXTENDED fields — display_order, is_enabled, dependency (Day 10). field_key not modified.
     */
    public function extendedFieldsUpdateBulk(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'fields' => 'required|array',
            'fields.*.id' => 'required|integer|exists:field_registry,id',
            'fields.*.display_order' => 'required|integer|min:0',
            'fields.*.is_enabled' => 'sometimes|in:0,1',
            'fields.*.parent_field_key' => 'nullable|string|max:64',
            'fields.*.dependency_type' => 'nullable|in:equals,present',
            'fields.*.dependency_value' => 'nullable|string|max:255',
        ]);

        foreach ($request->input('fields', []) as $row) {
            $field = FieldRegistry::find($row['id']);
            if (!$field || $field->field_type !== 'EXTENDED') {
                continue;
            }
            $displayOrder = (int) $row['display_order'];
            $isEnabled = isset($row['is_enabled']) && $row['is_enabled'] === '1';
            $parentKey = isset($row['parent_field_key']) && $row['parent_field_key'] !== '' ? trim($row['parent_field_key']) : null;
            $depType = $row['dependency_type'] ?? null;
            $depValue = $row['dependency_value'] ?? null;
            if ($parentKey !== null) {
                ExtendedFieldDependencyService::validateDependency(
                    $field,
                    $parentKey,
                    ExtendedFieldDependencyService::buildCondition($depType ?? 'present', $depValue),
                    'Dependency '
                );
            }
            $condition = $parentKey !== null
                ? ExtendedFieldDependencyService::buildCondition($depType ?? 'present', $depType === 'equals' ? $depValue : null)
                : null;
            $field->update([
                'display_order' => $displayOrder,
                'is_enabled' => $isEnabled,
                'parent_field_key' => $parentKey,
                'dependency_condition' => $condition,
            ]);
        }

        return redirect()->route('admin.field-registry.extended.index')
            ->with('success', 'EXTENDED field order and visibility updated.');
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

        $admin = auth()->user();
        // Phase-5: Resolve string lookups to *_id when form sends key (e.g. marital_status => single)
        if ($request->has('marital_status') && ! $request->has('marital_status_id')) {
            $key = $request->input('marital_status') === 'single' ? 'never_married' : $request->input('marital_status');
            $id = \App\Models\MasterMaritalStatus::where('key', $key)->value('id');
            if ($id) {
                $request->merge(['marital_status_id' => $id]);
            }
        }
        $originalData = $profile->only(['full_name', 'date_of_birth', 'marital_status_id', 'highest_education', 'location', 'religion_id', 'caste_id', 'sub_caste_id', 'height_cm']);
        $editedFields = [];

        // Track which fields were actually changed (Phase-5: *_id for master lookups)
        $updateData = [];
        $editableFields = ['full_name', 'date_of_birth', 'marital_status_id', 'highest_education', 'location', 'religion_id', 'caste_id', 'sub_caste_id', 'height_cm'];

        // Check if any CORE fields are being edited (before validation)
        $hasCoreFieldChanges = false;
        foreach ($editableFields as $field) {
            if ($request->exists($field)) {
                $newValue = $request->input($field);
                $oldValue = $originalData[$field] ?? null;
                $newValue = is_string($newValue) ? trim($newValue) : $newValue;
                $newValue = $newValue === '' ? null : $newValue;
                $oldValue = is_string($oldValue) ? trim($oldValue) : $oldValue;
                $oldValue = $oldValue === '' ? null : $oldValue;
                if ((string) $newValue !== (string) $oldValue) {
                    $hasCoreFieldChanges = true;
                    break;
                }
            }
        }

        // Validate edit reason (mandatory for all updates)
        $validationRules = [
            'edit_reason' => self::REASON_RULES,
        ];

        // Only validate CORE fields if they're being edited
        // This allows EXTENDED-only updates without requiring CORE field validation
        // SSOT: CORE vs EXTENDED separation - EXTENDED saves don't require CORE validation
        if ($hasCoreFieldChanges) {
            $validationRules['full_name'] = 'required|string|max:255';
            $validationRules['date_of_birth'] = 'nullable|date';
            $validationRules['marital_status_id'] = ['nullable', \Illuminate\Validation\Rule::exists('master_marital_statuses', 'id')];
            $validationRules['highest_education'] = 'nullable|string|max:255';
            $validationRules['location'] = 'nullable|string|max:255';
            $validationRules['religion_id'] = ['nullable', 'exists:religions,id'];
            $validationRules['caste_id'] = [
                'nullable',
                Rule::exists('castes', 'id')->where(function ($query) use ($request) {
                    if ($request->filled('religion_id')) {
                        $query->where('religion_id', $request->input('religion_id'));
                    }
                }),
            ];
            $validationRules['sub_caste_id'] = [
                'nullable',
                Rule::exists('sub_castes', 'id')->where(function ($query) use ($request) {
                    if ($request->filled('caste_id')) {
                        $query->where('caste_id', $request->input('caste_id'));
                    }
                }),
            ];
            $validationRules['height_cm'] = 'nullable|integer|min:50|max:250';
        }

        $request->validate($validationRules);
        
        // STEP 1: REQUEST PAYLOAD (SINGLE RUN) - BEFORE any logic
        \Log::info('STEP1_REQUEST', [
            'has' => request()->has('caste'),
            'exists' => request()->exists('caste'),
            'value' => request()->input('caste'),
            'all' => request()->all(),
        ]);
        
        foreach ($editableFields as $field) {
            if ($request->exists($field)) {
                $newValue = $request->input($field);
                $oldValue = $originalData[$field] ?? null;
                
                // Normalize for comparison (trim whitespace, treat empty as null)
                $newValue = is_string($newValue) ? trim($newValue) : $newValue;
                $newValue = $newValue === '' ? null : $newValue;
                $oldValue = is_string($oldValue) ? trim($oldValue) : $oldValue;
                $oldValue = $oldValue === '' ? null : $oldValue;
                
                if ($newValue != $oldValue) {
                    $updateData[$field] = $newValue;
                    $editedFields[] = $field;
                }
            }
        }

        // Day-6.2: Only treat actually-changed EXTENDED fields as overwrite attempts
        $hasExtendedFields = $request->has('extended_fields') && is_array($request->input('extended_fields'));
        $changedExtendedKeys = $hasExtendedFields
            ? ExtendedFieldService::getChangedExtendedFieldKeys($profile, $request->input('extended_fields'))
            : [];

        if (empty($editedFields) && empty($changedExtendedKeys)) {
            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'No changes detected. Please modify at least one field.');
        }

        // Day-6: Overwrite protection - authority-aware (equal/higher can edit locked)
        if (!empty($editedFields)) {
            ProfileFieldLockService::assertNotLocked($profile, $editedFields, $admin);
        }
        if (!empty($changedExtendedKeys)) {
            ProfileFieldLockService::assertNotLocked($profile, $changedExtendedKeys, $admin);
        }

        if (!empty($editedFields)) {
            // Merge with existing admin_edited_fields
            $existingAdminEditedFields = $profile->admin_edited_fields ?? [];
            $mergedAdminEditedFields = array_unique(array_merge($existingAdminEditedFields, $editedFields));

            $updateData['edited_by'] = $admin->id;
            $updateData['edited_at'] = now();
            $updateData['edit_reason'] = $request->input('edit_reason');
            $updateData['edited_source'] = 'admin';
            $updateData['admin_edited_fields'] = $mergedAdminEditedFields;

            // Manual-edit escalation: serious_intent + identity-critical field change → conflict, no update (Phase-5: *_id)
            $identityCriticalFields = [
                'full_name',
                'date_of_birth',
                'gender_id',
                'religion_id',
                'caste_id',
                'sub_caste_id',
                'marital_status_id',
                'primary_contact_number',
            ];
            $editedCritical = array_intersect($editedFields, $identityCriticalFields);
            if ($profile->serious_intent_id !== null && !empty($editedCritical)) {
                foreach ($editedCritical as $fieldKey) {
                    if (ConflictRecord::where('profile_id', $profile->id)->where('field_name', $fieldKey)->where('resolution_status', 'PENDING')->exists()) {
                        continue;
                    }
                    $oldVal = ($originalData[$fieldKey] ?? $profile->$fieldKey) === '' ? null : (string) ($originalData[$fieldKey] ?? $profile->$fieldKey ?? null);
                    $newVal = isset($updateData[$fieldKey]) ? ($updateData[$fieldKey] === '' ? null : (string) $updateData[$fieldKey]) : null;
                    ConflictRecord::create([
                        'profile_id' => $profile->id,
                        'field_name' => $fieldKey,
                        'field_type' => 'CORE',
                        'old_value' => $oldVal,
                        'new_value' => $newVal,
                        'source' => 'ADMIN',
                        'detected_at' => now(),
                        'resolution_status' => 'PENDING',
                    ]);
                }
                ProfileLifecycleService::syncLifecycleFromPendingConflicts($profile);
                return redirect()
                    ->route('admin.profiles.show', $profile)
                    ->with('warning', 'Identity-critical field(s) changed under serious intent. Conflict(s) created; profile not updated. Resolve via Conflict Records.');
            }

            // Phase-5B: All core updates via MutationService (source=admin, profile_change_history)
            $extendedInput = $hasExtendedFields ? $request->input('extended_fields') : [];
            $snapshot = $this->buildAdminSnapshot($profile, $updateData, is_array($extendedInput) ? $extendedInput : []);
            app(\App\Services\MutationService::class)->applyManualSnapshot($profile, $snapshot, (int) $admin->id, 'admin');

            $profile->refresh();

            if (in_array('caste_id', $editedFields) || in_array('caste', $editedFields)) {
                $casteValue = $updateData['caste_id'] ?? $updateData['caste'] ?? null;
                if ($casteValue !== null && (is_string($casteValue) ? trim($casteValue) !== '' : true)) {
                    \Log::info('B_AFTER_ADD', [
                        'db_caste' => $profile->caste,
                        'pct' => \App\Services\ProfileCompletenessService::percentage($profile),
                    ]);
                } else {
                    \Log::info('C_AFTER_REMOVE', [
                        'db_caste' => $profile->caste,
                        'pct' => \App\Services\ProfileCompletenessService::percentage($profile),
                    ]);
                }
            }

            ProfileFieldLockService::applyLocks($profile, $editedFields, 'CORE', $admin);
        } elseif (!empty($changedExtendedKeys)) {
            $snapshot = $this->buildAdminSnapshot($profile, [
                'edited_by' => $admin->id,
                'edited_at' => now(),
                'edit_reason' => $request->input('edit_reason'),
                'edited_source' => 'admin',
            ], $request->input('extended_fields'));
            app(\App\Services\MutationService::class)->applyManualSnapshot($profile, $snapshot, (int) $admin->id, 'admin');
        }

        // Create audit log entry
        AuditLogService::log(
            $admin,
            'profile_edit',
            'MatrimonyProfile',
            $profile->id,
            $request->input('edit_reason'),
            $profile->is_demo ?? false
        );

        // Phase-5B: Extended fields applied inside MutationService::applyManualSnapshot (same transaction)
        if (!empty($changedExtendedKeys)) {
            ProfileFieldLockService::applyLocks($profile, $changedExtendedKeys, 'EXTENDED', $admin);
        }

        $message = !empty($editedFields)
            ? 'Profile updated successfully. Edited fields: ' . implode(', ', $editedFields)
            : 'Profile updated successfully. Extended fields saved.';

        return redirect()
            ->route('admin.profiles.show', $profile)
            ->with('success', $message);
    }

    /**
     * Phase-3 Day-13: Manual conflict detection. Compares profile vs proposed data; creates ConflictRecords for mismatches.
     * Skips locked fields. Does NOT mutate profile.
     */
    public function detectConflicts(Request $request, MatrimonyProfile $profile)
    {
        if (!auth()->check() || !auth()->user()->is_admin) {
            abort(403, 'Admin access required');
        }

        $proposedCore = $request->input('proposed_core', []);
        $proposedExtended = $request->input('proposed_extended', []);
        if (!is_array($proposedCore)) {
            $proposedCore = [];
        }
        if (!is_array($proposedExtended)) {
            $proposedExtended = [];
        }

        $created = ConflictDetectionService::detect($profile, $proposedCore, $proposedExtended);
        $count = count($created);

        return redirect()
            ->route('admin.profiles.show', $profile->id)
            ->with('success', "Conflict detection complete. {$count} conflict(s) created (locked fields skipped).");
    }

    /**
     * Phase-3 Day-4: Conflict records list (read-only).
     */
    public function conflictRecordsIndex()
    {
        $records = ConflictRecord::with('profile')
            ->where('resolution_status', 'PENDING')
            ->orderByDesc('detected_at')
            ->paginate(50);
        return view('admin.conflict-records.index', compact('records'));
    }

    /**
     * Phase-5 Day-20: Conflict record show — detailed diff, profile context, resolution form.
     */
    public function conflictRecordShow(ConflictRecord $record)
    {
        $profile = MatrimonyProfile::find($record->profile_id);
        $canResolve = $record->resolution_status === 'PENDING' && auth()->user()?->hasAdminRole(['super_admin', 'data_admin']);
        return view('admin.conflict-records.show', compact('record', 'profile', 'canResolve'));
    }

    /**
     * Phase-3 Day-4: Form to create a conflict record manually (testing only).
     */
    public function conflictRecordsCreate()
    {
        $profiles = MatrimonyProfile::withTrashed()->orderBy('id')->get(['id', 'full_name']);
        return view('admin.conflict-records.create', compact('profiles'));
    }

    /**
     * Phase-3 Day-4: Store a conflict record (testing only, minimal validation).
     */
    public function conflictRecordsStore(Request $request)
    {
        $request->validate([
            'profile_id' => ['required', 'exists:matrimony_profiles,id'],
            'field_name' => ['required', 'string', 'max:255'],
            'field_type' => ['required', 'in:CORE,EXTENDED'],
            'old_value' => ['nullable', 'string'],
            'new_value' => ['nullable', 'string'],
            'source' => ['required', 'in:OCR,USER,ADMIN,MATCHMAKER,SYSTEM'],
        ]);

        if (!ConflictRecord::where('profile_id', $request->profile_id)->where('field_name', $request->field_name)->where('resolution_status', 'PENDING')->exists()) {
            ConflictRecord::create([
                'profile_id' => $request->profile_id,
                'field_name' => $request->field_name,
                'field_type' => $request->field_type,
                'old_value' => $request->old_value,
                'new_value' => $request->new_value,
                'source' => $request->source,
                'detected_at' => now(),
                'resolution_status' => 'PENDING',
            ]);
        }

        return redirect()->route('admin.conflict-records.index')->with('success', 'Conflict record created (testing).');
    }

    /**
     * Phase-3 Day-5: Approve conflict (service handles authority + validation).
     * Day-7: Role guard — super_admin, data_admin only.
     */
    public function conflictRecordApprove(Request $request, ConflictRecord $record)
    {
        if (!$request->user()->hasAdminRole(['super_admin', 'data_admin'])) {
            abort(403, 'This action requires super_admin or data_admin role');
        }

        $request->validate(['resolution_reason' => ['required', 'string', 'min:10']]);
        ConflictResolutionService::approveConflict($record, $request->user(), $request->resolution_reason);
        return redirect()->route('admin.conflict-records.show', $record)->with('success', 'Conflict approved.');
    }

    /**
     * Phase-3 Day-5: Reject conflict (service handles authority + validation).
     * Day-7: Role guard — super_admin, data_admin only.
     */
    public function conflictRecordReject(Request $request, ConflictRecord $record)
    {
        if (!$request->user()->hasAdminRole(['super_admin', 'data_admin'])) {
            abort(403, 'This action requires super_admin or data_admin role');
        }

        $request->validate(['resolution_reason' => ['required', 'string', 'min:10']]);
        ConflictResolutionService::rejectConflict($record, $request->user(), $request->resolution_reason);
        return redirect()->route('admin.conflict-records.show', $record)->with('success', 'Conflict rejected.');
    }

    /**
     * Phase-3 Day-5: Override conflict (service handles authority + validation).
     * Day-7: Role guard — super_admin, data_admin only.
     */
    public function conflictRecordOverride(Request $request, ConflictRecord $record)
    {
        if (!$request->user()->hasAdminRole(['super_admin', 'data_admin'])) {
            abort(403, 'This action requires super_admin or data_admin role');
        }

        $request->validate(['resolution_reason' => ['required', 'string', 'min:10']]);
        ConflictResolutionService::overrideConflict($record, $request->user(), $request->resolution_reason);
        return redirect()->route('admin.conflict-records.show', $record)->with('success', 'Conflict overridden.');
    }

    /**
     * Phase-3 Day-14: OCR mode simulation UI (admin-only, testing governance).
     * Shows form to manually select OCR mode and input dummy proposed data.
     */
    public function ocrSimulation()
    {
        if (!auth()->check() || !auth()->user()->is_admin) {
            abort(403, 'Admin access required');
        }

        $profiles = MatrimonyProfile::orderBy('id')->get(['id', 'full_name']);
        $modes = OcrMode::all();

        return view('admin.ocr-simulation.index', compact('profiles', 'modes'));
    }

    /**
     * Phase-3 Day-14: Execute OCR governance simulation (no persistence).
     * Processes dummy proposed data through governance logic only.
     * Returns decisions (ALLOW/SKIP/CREATE_CONFLICT) — does NOT mutate profile.
     */
    public function ocrSimulationExecute(Request $request)
    {
        if (!auth()->check() || !auth()->user()->is_admin) {
            abort(403, 'Admin access required');
        }

        $request->validate([
            'ocr_mode' => ['required', 'string', 'in:' . implode(',', OcrMode::all())],
            'profile_id' => ['nullable', 'exists:matrimony_profiles,id'],
            'proposed_core' => ['nullable', 'array'],
            'proposed_extended' => ['nullable', 'array'],
        ]);

        $mode = $request->input('ocr_mode');
        $profileId = $request->input('profile_id');
        $proposedCoreRaw = $request->input('proposed_core', []);
        $proposedExtendedRaw = $request->input('proposed_extended', []);

        // Filter out empty/null values from proposed data
        // SSOT: Only process fields that are explicitly provided with non-empty values
        // Empty form fields should not trigger conflict detection
        $proposedCore = [];
        foreach ($proposedCoreRaw as $key => $value) {
            if ($value !== null && $value !== '' && trim((string) $value) !== '') {
                $proposedCore[$key] = $value;
            }
        }
        $proposedExtended = [];
        foreach ($proposedExtendedRaw as $key => $value) {
            if ($value !== null && $value !== '' && trim((string) $value) !== '') {
                $proposedExtended[$key] = $value;
            }
        }

        $profile = $profileId ? MatrimonyProfile::find($profileId) : null;

        // Get governance decisions (no persistence)
        $decisions = OcrGovernanceService::decideBulk($profile, $proposedCore, $proposedExtended);

        // Get mode per field (for display)
        $fieldModes = [];
        foreach (array_merge(array_keys($proposedCore), array_keys($proposedExtended)) as $fieldKey) {
            $fieldModes[$fieldKey] = OcrModeDetectionService::detect($profile, $fieldKey);
        }

        // Execute decisions (create conflicts only, no profile mutation)
        $createdConflicts = OcrGovernanceService::executeDecisions($profile, $proposedCore, $proposedExtended);

        return redirect()
            ->route('admin.ocr-simulation.index')
            ->with('simulation_result', [
                'mode' => $mode,
                'profile_id' => $profileId,
                'decisions' => $decisions,
                'field_modes' => $fieldModes,
                'conflicts_created' => count($createdConflicts),
            ])
            ->with('success', 'OCR governance simulation complete. ' . count($createdConflicts) . ' conflict(s) created (if any).');
    }

    /**
     * Day-7: Unlock a locked field (super_admin only, mandatory reason + audit).
     */
    public function unlockProfileField(Request $request, MatrimonyProfile $profile)
    {
        // Day-7: super_admin only
        if (!$request->user()->hasAdminRole(['super_admin'])) {
            abort(403, 'This action requires super_admin role');
        }

        // Day-7: Reason is mandatory
        $request->validate([
            'field_key' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:10'],
        ]);

        $unlocked = ProfileFieldLockService::removeLock($profile, $request->field_key);

        if ($unlocked) {
            // Day-7: Record audit log for field unlock
            AuditLogService::log(
                $request->user(),
                'field_unlocked',
                'matrimony_profile',
                $profile->id,
                $request->reason,
                false
            );

            return redirect()->route('admin.profiles.show', $profile->id)
                ->with('success', "Field \"{$request->field_key}\" has been unlocked.");
        }

        return redirect()->route('admin.profiles.show', $profile->id)
            ->withErrors(['field_key' => 'Field is not locked or does not exist.']);
    }
}

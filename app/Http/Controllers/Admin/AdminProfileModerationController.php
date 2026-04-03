<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AbuseReport;
use App\Models\ConflictRecord;
use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Models\ProfileKycSubmission;
use App\Models\Shortlist;
use App\Models\VerificationTag;
use App\Notifications\ImageRejectedNotification;
use App\Notifications\ProfileSoftDeletedNotification;
use App\Notifications\ProfileSuspendedNotification;
use App\Notifications\ProfileUnsuspendedNotification;
use App\Services\AuditLogService;
use App\Services\ConflictDetectionService;
use App\Services\ExtendedFieldService;
use App\Services\FieldValueHistoryService;
use App\Services\ProfileCompletenessService;
use App\Services\ProfileFieldLockService;
use App\Services\ProfileLifecycleService;
use App\Services\ViewTrackingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/*
|--------------------------------------------------------------------------
| Admin profile moderation (moved from AdminController, Phase 2)
|--------------------------------------------------------------------------
*/
class AdminProfileModerationController extends Controller
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
        if (! $isOwnProfile && $user->matrimonyProfile) {
            $inShortlist = Shortlist::where('owner_profile_id', $user->matrimonyProfile->id)
                ->where('shortlisted_profile_id', $profile->id)
                ->exists();
        }

        if (! $isOwnProfile && $user->matrimonyProfile) {
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

        $pendingKyc = null;
        if (Schema::hasTable('profile_kyc_submissions')) {
            $pendingKyc = ProfileKycSubmission::query()
                ->where('matrimony_profile_id', $profile->id)
                ->where('status', ProfileKycSubmission::STATUS_PENDING)
                ->orderByDesc('id')
                ->first();
        }

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
            'pendingKyc' => $pendingKyc,
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
        if (! $request->user()->hasAdminRole(['super_admin'])) {
            abort(403, 'This action requires super_admin role');
        }

        // Day-7: Reason is mandatory
        $request->validate([
            'lifecycle_state' => ['required', 'string', 'in:'.implode(',', ProfileLifecycleService::getStates())],
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

        return redirect()->route('admin.profiles.show', $profile->id)->with('success', 'Lifecycle state updated to '.$request->lifecycle_state);
    }

    /**
     * Admin update profile (edit mode)
     * Updates profile fields and tracks which fields were edited by admin
     */
    public function updateProfile(Request $request, MatrimonyProfile $profile)
    {
        // Guard: Admin only
        if (! auth()->check() || ! auth()->user()->is_admin) {
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
        $originalData = $profile->only([
            'full_name', 'date_of_birth', 'marital_status_id', 'highest_education', 'location',
            'religion_id', 'caste_id', 'sub_caste_id', 'height_cm',
            'complexion_id', 'blood_group_id', 'physical_build_id', 'weight_kg', 'spectacles_lens', 'physical_condition',
        ]);
        $editedFields = [];

        // Track which fields were actually changed (Phase-5: *_id for master lookups)
        $updateData = [];
        $editableFields = [
            'full_name', 'date_of_birth', 'marital_status_id', 'highest_education', 'location',
            'religion_id', 'caste_id', 'sub_caste_id', 'height_cm',
            'complexion_id', 'blood_group_id', 'physical_build_id', 'weight_kg', 'spectacles_lens', 'physical_condition',
        ];

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
            $validationRules['marital_status_id'] = ['nullable', \Illuminate\Validation\Rule::exists('master_marital_statuses', 'id')->where(fn ($q) => $q->where('is_active', true))];
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
            $validationRules['complexion_id'] = ['nullable', Rule::exists('master_complexions', 'id')->where(fn ($q) => $q->where('is_active', true))];
            $validationRules['blood_group_id'] = ['nullable', Rule::exists('master_blood_groups', 'id')->where(fn ($q) => $q->where('is_active', true))];
            $validationRules['physical_build_id'] = ['nullable', Rule::exists('master_physical_builds', 'id')->where(fn ($q) => $q->where('is_active', true))];
            $validationRules['weight_kg'] = 'nullable|numeric|min:20|max:300';
            $validationRules['spectacles_lens'] = ['nullable', 'string', 'max:50', Rule::in(['no', 'spectacles', 'contact_lens', 'both'])];
            $validationRules['physical_condition'] = ['nullable', 'string', 'max:50', Rule::in(['none', 'physically_challenged', 'hearing_condition', 'vision_condition', 'other', 'prefer_not_to_say'])];
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
        if (! empty($editedFields)) {
            ProfileFieldLockService::assertNotLocked($profile, $editedFields, $admin);
        }
        if (! empty($changedExtendedKeys)) {
            ProfileFieldLockService::assertNotLocked($profile, $changedExtendedKeys, $admin);
        }

        if (! empty($editedFields)) {
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
            if ($profile->serious_intent_id !== null && ! empty($editedCritical)) {
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
        } elseif (! empty($changedExtendedKeys)) {
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
        if (! empty($changedExtendedKeys)) {
            ProfileFieldLockService::applyLocks($profile, $changedExtendedKeys, 'EXTENDED', $admin);
        }

        $message = ! empty($editedFields)
            ? 'Profile updated successfully. Edited fields: '.implode(', ', $editedFields)
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
        if (! auth()->check() || ! auth()->user()->is_admin) {
            abort(403, 'Admin access required');
        }

        $proposedCore = $request->input('proposed_core', []);
        $proposedExtended = $request->input('proposed_extended', []);
        if (! is_array($proposedCore)) {
            $proposedCore = [];
        }
        if (! is_array($proposedExtended)) {
            $proposedExtended = [];
        }

        $created = ConflictDetectionService::detect($profile, $proposedCore, $proposedExtended);
        $count = count($created);

        return redirect()
            ->route('admin.profiles.show', $profile->id)
            ->with('success', "Conflict detection complete. {$count} conflict(s) created (locked fields skipped).");
    }

    /**
     * Day-7: Unlock a locked field (super_admin only, mandatory reason + audit).
     */
    public function unlockProfileField(Request $request, MatrimonyProfile $profile)
    {
        // Day-7: super_admin only
        if (! $request->user()->hasAdminRole(['super_admin'])) {
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

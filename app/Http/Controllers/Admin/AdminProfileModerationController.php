<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AbuseReport;
use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Models\ProfileKycSubmission;
use App\Models\Shortlist;
use App\Models\VerificationTag;
use App\Notifications\ImageRejectedNotification;
use App\Notifications\ProfileSoftDeletedNotification;
use App\Notifications\ProfileSuspendedNotification;
use App\Notifications\ProfileUnsuspendedNotification;
use App\Services\AdminProfileEditGovernanceService;
use App\Services\AdminProfileSoftDeleteService;
use App\Services\AuditLogService;
use App\Services\ConflictDetectionService;
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

    public function __construct(
        private readonly AdminProfileEditGovernanceService $adminProfileEditGovernance,
    ) {}

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

        $snapshot = $this->adminProfileEditGovernance->buildAdminSnapshot($profile, ['is_suspended' => true]);
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

        $snapshot = $this->adminProfileEditGovernance->buildAdminSnapshot($profile, ['is_suspended' => false]);
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

        $result = AdminProfileSoftDeleteService::perform($profile);
        $profileId = $result['profile_id'];
        $owner = $result['owner'];
        $isDemo = $result['is_demo'];

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

        $snapshot = $this->adminProfileEditGovernance->buildAdminSnapshot($profile, [
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

        $snapshot = $this->adminProfileEditGovernance->buildAdminSnapshot($profile, [
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

        $snapshot = $this->adminProfileEditGovernance->buildAdminSnapshot($profile, [
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

        $this->adminProfileEditGovernance->mergeMaritalStatusFromLegacyRequest($request);
        $originalData = $this->adminProfileEditGovernance->buildOriginalCoreSnapshot($profile);
        $hasCoreFieldChanges = $this->adminProfileEditGovernance->hasCoreFieldChanges($request, $originalData);

        $validationRules = [
            'edit_reason' => self::REASON_RULES,
        ];

        if ($hasCoreFieldChanges) {
            $validationRules['full_name'] = 'required|string|max:255';
            $validationRules['date_of_birth'] = 'nullable|date';
            $validationRules['marital_status_id'] = ['nullable', Rule::exists('master_marital_statuses', 'id')->where(fn ($q) => $q->where('is_active', true))];
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

        $result = $this->adminProfileEditGovernance->applyAfterValidation($request, $profile, $admin, $originalData);

        if ($result->status === 'error') {
            return redirect()
                ->back()
                ->withInput()
                ->with('error', $result->message);
        }

        if ($result->status === 'warning') {
            return redirect()
                ->route('admin.profiles.show', $profile)
                ->with('warning', $result->message);
        }

        return redirect()
            ->route('admin.profiles.show', $profile)
            ->with('success', $result->message);
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

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AbuseReport;
use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Models\ProfileKycSubmission;
use App\Models\ProfilePhoto;
use App\Models\Shortlist;
use App\Models\User;
use App\Models\VerificationTag;
use App\Notifications\ImageRejectedNotification;
use App\Notifications\ProfileSoftDeletedNotification;
use App\Notifications\ProfileSuspendedNotification;
use App\Notifications\ProfileUnsuspendedNotification;
use App\Services\Admin\PhotoModerationAdminService;
use App\Services\Admin\UserModerationStatsService;
use App\Services\AdminProfileEditGovernanceService;
use App\Services\AdminProfileSoftDeleteService;
use App\Services\AuditLogService;
use App\Services\ConflictDetectionService;
use App\Services\FieldValueHistoryService;
use App\Services\Image\ProfileGalleryPhotoDeletionService;
use App\Services\Image\ProfilePhotoUrlService;
use App\Services\ProfileCompletenessService;
use App\Services\ProfileFieldLockService;
use App\Services\ProfileLifecycleService;
use App\Services\ViewTrackingService;
use App\Support\SafeNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        private readonly ProfileGalleryPhotoDeletionService $galleryPhotoDeletion,
        private readonly UserModerationStatsService $userModerationStats,
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
            if (ViewTrackingService::recordView($user->matrimonyProfile, $profile)) {
                ViewTrackingService::consumeDailyProfileViewUsageForViewer($user->matrimonyProfile);
            }
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

        $phase5MutationLog = $this->recentProfileChangeHistoryForProfile((int) $profile->id, 15);

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
            'phase5MutationLog' => $phase5MutationLog,
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
            $profile->isShowcaseProfile()
        );

        $owner = $profile->user;
        if ($owner) {
            SafeNotifier::notify($owner, new ProfileSuspendedNotification($request->reason));
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
            $profile->isShowcaseProfile()
        );

        $owner = $profile->user;
        if ($owner) {
            SafeNotifier::notify($owner, new ProfileUnsuspendedNotification($request->reason));
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
        $isShowcase = $result['is_showcase'];

        AuditLogService::log(
            $request->user(),
            'soft_delete',
            'MatrimonyProfile',
            $profileId,
            $request->reason,
            $isShowcase
        );

        if ($owner) {
            SafeNotifier::notify($owner, new ProfileSoftDeletedNotification($request->reason));
        }

        return redirect()->route('admin.profiles.show', $profileId)->with('success', 'Profile soft deleted.');
    }

    /**
     * Approve profile image. Audit log only. NO user notification (SSOT-forbidden).
     */
    public function approveImage(Request $request, MatrimonyProfile $profile): \Illuminate\Http\RedirectResponse
    {
        $request->validate(['reason' => self::REASON_RULES]);

        $scanJson = null;
        if (Schema::hasTable('profile_photos')) {
            $primaryRow = ProfilePhoto::query()
                ->where('profile_id', $profile->id)
                ->where('is_primary', true)
                ->first();
            $scanJson = $primaryRow?->moderation_scan_json;
        }
        if ($scanJson === null && Schema::hasColumn('matrimony_profiles', 'photo_moderation_snapshot')) {
            $snap = $profile->photo_moderation_snapshot;
            $scanJson = is_array($snap) ? $snap : null;
        }
        if (PhotoModerationAdminService::moderationScanIndicatesUnsafe($scanJson)) {
            return redirect()->back()->with('error', 'Cannot approve: NudeNet API classified this image as unsafe.');
        }

        $snapshot = $this->adminProfileEditGovernance->buildAdminSnapshot($profile, [
            'photo_approved' => true,
            'photo_rejected_at' => null,
            'photo_rejection_reason' => null,
        ]);
        app(\App\Services\MutationService::class)->applyManualSnapshot($profile, $snapshot, (int) $request->user()->id, 'admin');

        $this->syncPrimaryGalleryRowStatus($profile->fresh(), 'approved');

        AuditLogService::log(
            $request->user(),
            'image_approve',
            'MatrimonyProfile',
            $profile->id,
            $request->reason,
            $profile->isShowcaseProfile()
        );

        if ($profile->user_id) {
            $this->userModerationStats->recordModerationOutcome((int) $profile->user_id, 'approved');
        }

        return $this->redirectAfterImageModeration($request, $profile, 'Image approved.');
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

        $this->syncPrimaryGalleryRowStatus($profile->fresh(), 'rejected');

        AuditLogService::log(
            $request->user(),
            'image_reject',
            'MatrimonyProfile',
            $profile->id,
            $request->reason,
            $profile->isShowcaseProfile()
        );

        $owner = $profile->user;
        if ($owner) {
            SafeNotifier::notify($owner, new ImageRejectedNotification($request->reason));
        }

        if ($profile->user_id) {
            $this->userModerationStats->recordModerationOutcome((int) $profile->user_id, 'rejected');
        }

        return $this->redirectAfterImageModeration($request, $profile, 'Image rejected.');
    }

    /**
     * Remove the current primary photo (files + gallery when applicable). Mandatory reason; user notified like reject.
     */
    public function deletePrimaryPhoto(Request $request, MatrimonyProfile $profile): RedirectResponse
    {
        $request->validate(['reason' => self::REASON_RULES]);

        $oldPath = trim((string) ($profile->profile_photo ?? ''));
        if ($oldPath === '') {
            return redirect()->back()->with('error', 'No photo to remove.');
        }

        if (ProfilePhotoUrlService::isPendingPlaceholder($oldPath)) {
            $snapshot = $this->adminProfileEditGovernance->buildAdminSnapshot($profile, [
                'profile_photo' => null,
                'photo_approved' => false,
                'photo_rejected_at' => now(),
                'photo_rejection_reason' => $request->reason,
            ]);
            app(\App\Services\MutationService::class)->applyManualSnapshot($profile, $snapshot, (int) $request->user()->id, 'admin');
            $this->unlinkPendingTempIfExists($oldPath);

            AuditLogService::log(
                $request->user(),
                'image_delete',
                'MatrimonyProfile',
                $profile->id,
                $request->reason,
                $profile->isShowcaseProfile()
            );

            $owner = $profile->fresh()->user;
            if ($owner) {
                SafeNotifier::notify($owner, new ImageRejectedNotification($request->reason));
            }

            return $this->redirectAfterImageModeration($request, $profile, 'Photo removed.');
        }

        $fn = ltrim($oldPath, '/');
        $basename = basename($fn);

        $photoModel = null;
        if (Schema::hasTable('profile_photos')) {
            $photoModel = ProfilePhoto::query()
                ->where('profile_id', $profile->id)
                ->where(function ($q) use ($fn, $basename) {
                    $q->where('file_path', $fn)->orWhere('file_path', $basename);
                })
                ->orderByDesc('is_primary')
                ->first();
        }

        if ($photoModel !== null) {
            $this->deleteGalleryPhotoLikeMemberFlow($profile, $photoModel);

            AuditLogService::log(
                $request->user(),
                'image_delete',
                'MatrimonyProfile',
                $profile->id,
                $request->reason,
                $profile->isShowcaseProfile()
            );

            $owner = $profile->fresh()->user;
            if ($owner) {
                SafeNotifier::notify($owner, new ImageRejectedNotification($request->reason));
            }

            return $this->redirectAfterImageModeration($request, $profile, 'Photo deleted.');
        }

        $snapshot = $this->adminProfileEditGovernance->buildAdminSnapshot($profile, [
            'profile_photo' => null,
            'photo_approved' => false,
            'photo_rejected_at' => now(),
            'photo_rejection_reason' => $request->reason,
        ]);
        app(\App\Services\MutationService::class)->applyManualSnapshot($profile, $snapshot, (int) $request->user()->id, 'admin');
        $this->unlinkStoredMatrimonyPhotoFiles($basename);

        AuditLogService::log(
            $request->user(),
            'image_delete',
            'MatrimonyProfile',
            $profile->id,
            $request->reason,
            $profile->isShowcaseProfile()
        );

        $owner = $profile->fresh()->user;
        if ($owner) {
            SafeNotifier::notify($owner, new ImageRejectedNotification($request->reason));
        }

        return $this->redirectAfterImageModeration($request, $profile, 'Photo removed.');
    }

    /**
     * Approve a gallery row in profile_photos that is still pending.
     */
    public function approveGalleryPhoto(Request $request, ProfilePhoto $profilePhoto): RedirectResponse
    {
        $request->validate(['reason' => self::REASON_RULES]);

        $profile = MatrimonyProfile::query()->findOrFail($profilePhoto->profile_id);
        if ($profile->photo_rejected_at !== null) {
            return redirect()->back()->with('error', 'This profile is not eligible for gallery photo approval.');
        }
        if ($profilePhoto->approved_status !== 'pending') {
            return redirect()->back()->with('error', 'This photo is not pending review.');
        }

        if (PhotoModerationAdminService::moderationScanIndicatesUnsafe($profilePhoto->moderation_scan_json)) {
            return redirect()->back()->with('error', 'Cannot approve: NudeNet API classified this image as unsafe.');
        }

        MatrimonyProfile::$bypassGovernanceEnforcement = true;
        try {
            $profilePhoto->approved_status = 'approved';
            $profilePhoto->save();
        } finally {
            MatrimonyProfile::$bypassGovernanceEnforcement = false;
        }

        AuditLogService::log(
            $request->user(),
            'gallery_photo_approve',
            'ProfilePhoto',
            $profilePhoto->id,
            $request->reason,
            $profile->isShowcaseProfile()
        );

        if ($profile->user_id) {
            $this->userModerationStats->recordModerationOutcome((int) $profile->user_id, 'approved');
        }

        return $this->redirectAfterImageModeration($request, $profile, 'Gallery photo approved.');
    }

    /**
     * Reject a gallery row; user is notified (same as primary reject).
     */
    public function rejectGalleryPhoto(Request $request, ProfilePhoto $profilePhoto): RedirectResponse
    {
        $request->validate(['reason' => self::REASON_RULES]);

        $profile = MatrimonyProfile::query()->findOrFail($profilePhoto->profile_id);
        if ($profilePhoto->approved_status !== 'pending') {
            return redirect()->back()->with('error', 'This photo is not pending review.');
        }

        MatrimonyProfile::$bypassGovernanceEnforcement = true;
        try {
            $profilePhoto->approved_status = 'rejected';
            $profilePhoto->save();
        } finally {
            MatrimonyProfile::$bypassGovernanceEnforcement = false;
        }

        AuditLogService::log(
            $request->user(),
            'gallery_photo_reject',
            'ProfilePhoto',
            $profilePhoto->id,
            $request->reason,
            $profile->isShowcaseProfile()
        );

        $owner = $profile->user;
        if ($owner) {
            SafeNotifier::notify($owner, new ImageRejectedNotification($request->reason));
        }

        if ($profile->user_id) {
            $this->userModerationStats->recordModerationOutcome((int) $profile->user_id, 'rejected');
        }

        return $this->redirectAfterImageModeration($request, $profile, 'Gallery photo rejected.');
    }

    /**
     * Delete a gallery photo (member-equivalent removal + file unlink).
     */
    public function deleteGalleryPhoto(Request $request, ProfilePhoto $profilePhoto): RedirectResponse
    {
        $request->validate(['reason' => self::REASON_RULES]);

        $profile = MatrimonyProfile::query()->findOrFail($profilePhoto->profile_id);

        $this->deleteGalleryPhotoLikeMemberFlow($profile, $profilePhoto);

        AuditLogService::log(
            $request->user(),
            'gallery_photo_delete',
            'ProfilePhoto',
            $profilePhoto->id,
            $request->reason,
            $profile->isShowcaseProfile()
        );

        $owner = $profile->fresh()->user;
        if ($owner) {
            SafeNotifier::notify($owner, new ImageRejectedNotification($request->reason));
        }

        return $this->redirectAfterImageModeration($request, $profile, 'Gallery photo removed.');
    }

    private function syncPrimaryGalleryRowStatus(MatrimonyProfile $profile, string $status): void
    {
        if (! Schema::hasTable('profile_photos')) {
            return;
        }
        if (! in_array($status, ['approved', 'rejected', 'pending'], true)) {
            return;
        }
        ProfilePhoto::query()
            ->where('profile_id', $profile->id)
            ->where('is_primary', true)
            ->update(['approved_status' => $status]);
    }

    private function redirectAfterImageModeration(Request $request, MatrimonyProfile $profile, string $message): RedirectResponse
    {
        if ($request->input('return_to') === 'photo-moderation') {
            return redirect()->route('admin.photo-moderation.index')->with('success', $message);
        }

        return redirect()->route('admin.profiles.show', $profile->id)->with('success', $message);
    }

    /**
     * Same behaviour as member photo delete: DB row, primary promotion, then unlink files on disk.
     */
    private function deleteGalleryPhotoLikeMemberFlow(MatrimonyProfile $profile, ProfilePhoto $photo): void
    {
        $this->galleryPhotoDeletion->deleteWithPrimaryResync($profile, $photo);
    }

    private function unlinkPendingTempIfExists(string $pendingPath): void
    {
        $abs = ProfilePhotoUrlService::resolvePendingTempAbsolutePath($pendingPath);
        if ($abs !== null && is_file($abs)) {
            @unlink($abs);
        }
    }

    private function unlinkStoredMatrimonyPhotoFiles(string $filename): void
    {
        $filename = ltrim($filename, '/');
        foreach ([
            storage_path('app/public/matrimony_photos/'.$filename),
            public_path('uploads/matrimony_photos/'.$filename),
        ] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Override visibility: force profile visible in search even if <70% complete.
     * Mandatory reason, audit log, is_showcase. Affects search only; interest rules unchanged.
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
            $profile->isShowcaseProfile()
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
        if (\Illuminate\Support\Facades\Schema::hasColumn('matrimony_profiles', 'education_degree_id')) {
            app(\App\Services\EducationService::class)->mergeMultiselectEducationIntoRequest($request);
        }
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
            if (\Illuminate\Support\Facades\Schema::hasColumn('matrimony_profiles', 'education_degree_id')) {
                $validationRules['education_slots'] = 'nullable|string|max:8192';
                $validationRules['education_degree_id'] = ['nullable', 'integer', Rule::exists('education_degrees', 'id')];
                $validationRules['education_text'] = 'nullable|string|max:512';
            }
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

    /**
     * Read-only: recent rows from profile_change_history for admin profile show.
     *
     * @return list<array{changed_at:mixed,field_name:string,old_value:?string,new_value:?string,source:?string,actor:?string}>
     */
    private function recentProfileChangeHistoryForProfile(int $profileId, int $limit): array
    {
        if (! Schema::hasTable('profile_change_history')) {
            return [];
        }

        $limit = max(1, min(20, $limit));

        $rows = DB::table('profile_change_history')
            ->where('profile_id', $profileId)
            ->orderByDesc('changed_at')
            ->limit($limit)
            ->get(['field_name', 'old_value', 'new_value', 'source', 'changed_by', 'changed_at']);

        if ($rows->isEmpty()) {
            return [];
        }

        $ids = $rows->pluck('changed_by')->filter()->unique()->values()->all();
        $names = $ids === []
            ? []
            : User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();

        return $rows->map(static function ($r) use ($names): array {
            $by = $r->changed_by;

            return [
                'changed_at' => $r->changed_at,
                'field_name' => (string) $r->field_name,
                'old_value' => $r->old_value !== null ? (string) $r->old_value : null,
                'new_value' => $r->new_value !== null ? (string) $r->new_value : null,
                'source' => $r->source !== null ? (string) $r->source : null,
                'actor' => $by ? (string) ($names[$by] ?? ('user #'.$by)) : null,
            ];
        })->all();
    }
}

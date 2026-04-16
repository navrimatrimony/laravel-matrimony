<?php

namespace App\Services;

use App\Models\MatrimonyProfile;
use App\Models\ProfileKycSubmission;
use App\Models\ProfileVisibilitySetting;
use App\Models\User;
use App\Services\Matching\MatchingService;
use App\Support\ContactVisibilityDecision;
use App\Support\ContactVisibilityStrictness;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who may access another member's contact surface (refined rules + legacy unlock row).
 *
 * Match scores: {@see MatchingService::computeMatchBreakdown} only (no duplicate scoring).
 * Plan gate: {@see FeatureUsageService::planGrantsContactReveal} (same source as contact reveal).
 */
class ContactVisibilityPolicyService
{
    public function __construct(
        protected MatchingService $matching,
        protected FeatureUsageService $featureUsage,
        protected ContactRequestService $contactRequests,
    ) {}

    /**
     * Primary API: viewer (authenticated user) requesting access to owner's contact rules.
     */
    public function resolveContactAccess(User $viewer, User $owner): ContactVisibilityDecision
    {
        if ((int) $viewer->id === (int) $owner->id) {
            return ContactVisibilityDecision::Allowed;
        }

        $ownerProfile = $owner->matrimonyProfile;
        $viewerProfile = $viewer->matrimonyProfile;
        if (! $ownerProfile || ! $viewerProfile) {
            return ContactVisibilityDecision::Denied;
        }

        if ($ownerProfile->lifecycle_state !== 'active') {
            return ContactVisibilityDecision::Denied;
        }

        if ($ownerProfile->visibility_override === true) {
            return ContactVisibilityDecision::Allowed;
        }

        if (ViewTrackingService::isBlocked((int) $viewerProfile->id, (int) $ownerProfile->id)) {
            return ContactVisibilityDecision::Denied;
        }

        $settings = ProfileVisibilitySetting::query()->where('profile_id', $ownerProfile->id)->first();
        $cfg = $settings?->resolvedContactVisibility()
            ?? ProfileVisibilitySetting::defaultResolvedContactVisibility();

        $rule = $cfg['rule'];
        if ($rule === 'none') {
            return ContactVisibilityDecision::Denied;
        }

        if ($rule === 'interest') {
            if (! $this->contactRequests->hasAcceptedInterest($viewer, $owner)) {
                return ContactVisibilityDecision::Denied;
            }
        }

        if ($rule === 'matching') {
            $min = ContactVisibilityStrictness::minMatchScore($cfg['strictness']);
            $bd = $this->matching->computeMatchBreakdown($viewerProfile, $ownerProfile);
            $score = (int) ($bd['final_score'] ?? 0);
            if ($score < $min) {
                return ContactVisibilityDecision::Denied;
            }
        }

        if ($cfg['filters']['id_verified_only'] ?? false) {
            if (! $this->viewerHasApprovedGovtId($viewerProfile)) {
                return ContactVisibilityDecision::Denied;
            }
        }

        if ($cfg['filters']['photo_only'] ?? false) {
            if (! $this->viewerHasProfilePhoto($viewerProfile)) {
                return ContactVisibilityDecision::Denied;
            }
        }

        if (! $this->featureUsage->planGrantsContactReveal($viewer)) {
            return ContactVisibilityDecision::Denied;
        }

        $grant = $this->contactRequests->getEffectiveGrant($viewer, $owner);

        if (($cfg['approval_required'] ?? false) && $grant === null) {
            return ContactVisibilityDecision::RequiresApproval;
        }

        if (($cfg['require_contact_request'] ?? false) && $grant === null) {
            return ContactVisibilityDecision::RequiresApproval;
        }

        return ContactVisibilityDecision::Allowed;
    }

    /**
     * Narrow boolean: true only when contact access is fully allowed (not {@see ContactVisibilityDecision::RequiresApproval}).
     */
    public function canViewContact(User $viewer, User $owner): bool
    {
        return $this->resolveContactAccess($viewer, $owner) === ContactVisibilityDecision::Allowed;
    }

    /**
     * Legacy: row in profile_contact_visibility (interest-unlock), plus profile contact_unlock_mode.
     */
    public function canViewContactViaInterestUnlock(MatrimonyProfile $targetProfile, ?MatrimonyProfile $viewerProfile): bool
    {
        if (! $viewerProfile) {
            return false;
        }
        if ($viewerProfile->id === $targetProfile->id) {
            return true;
        }
        if ($targetProfile->lifecycle_state !== 'active') {
            return false;
        }
        if ($targetProfile->visibility_override === true) {
            return true;
        }
        $mode = $targetProfile->contact_unlock_mode ?? 'after_interest_accepted';
        if ($mode === 'never') {
            return false;
        }
        if ($mode === 'admin_only') {
            return false;
        }
        if ($mode === 'after_interest_accepted') {
            if (! Schema::hasTable('profile_contact_visibility')) {
                return false;
            }

            return DB::table('profile_contact_visibility')
                ->where('owner_profile_id', $targetProfile->id)
                ->where('viewer_profile_id', $viewerProfile->id)
                ->whereNull('revoked_at')
                ->exists();
        }

        return false;
    }

    private function viewerHasProfilePhoto(MatrimonyProfile $viewerProfile): bool
    {
        $path = trim((string) ($viewerProfile->profile_photo ?? ''));
        if ($path === '') {
            return false;
        }
        if (class_exists(\App\Services\Image\ProfilePhotoUrlService::class)
            && \App\Services\Image\ProfilePhotoUrlService::isPendingPlaceholder($path)) {
            return false;
        }

        return true;
    }

    private function viewerHasApprovedGovtId(MatrimonyProfile $viewerProfile): bool
    {
        if (! Schema::hasTable('profile_kyc_submissions')) {
            return false;
        }

        return ProfileKycSubmission::query()
            ->where('matrimony_profile_id', $viewerProfile->id)
            ->where('status', ProfileKycSubmission::STATUS_APPROVED)
            ->exists();
    }
}

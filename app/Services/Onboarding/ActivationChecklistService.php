<?php

namespace App\Services\Onboarding;

use App\Models\ConflictRecord;
use Illuminate\Support\Carbon;
use App\Models\Location;
use App\Models\MatrimonyProfile;
use App\Models\MobileOnboardingDraft;
use App\Models\ProfilePhoto;
use App\Models\User;
use App\Services\Image\ProfilePhotoUrlService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use App\Services\ProfileCompletenessService;
use App\Services\ProfileLifecycleService;
use App\Services\RuleEngineService;
use Illuminate\Support\Facades\Schema;

class ActivationChecklistService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function items(User $user, ?MatrimonyProfile $profile = null, ?MobileOnboardingDraft $draft = null): array
    {
        $profile ??= $user->matrimonyProfile;

        $mobileVerified = $user->mobile_verified_at !== null;
        $accountComplete = trim((string) ($user->name ?? '')) !== '';
        $emailPresent = trim((string) ($user->email ?? '')) !== '';
        $emailVerified = $user->email_verified_at !== null;
        $requiredComplete = $profile instanceof MatrimonyProfile && $this->requiredFieldsComplete($profile);
        $locationValid = $profile instanceof MatrimonyProfile && $this->locationValid($profile);
        $pendingLocation = $this->pendingLocationPayload($draft);
        $locationStatus = $locationValid ? 'complete' : ($pendingLocation !== null ? 'pending' : 'missing');
        $locationMessage = $locationValid
            ? 'Final active location selected'
            : ($pendingLocation !== null
                ? 'Location approval pending; profile will not be searchable until approved'
                : 'Add an approved final location');
        $photoUploaded = $profile instanceof MatrimonyProfile && $this->photoUploaded($profile);
        $photoApproved = $profile instanceof MatrimonyProfile && $this->photoApproved($profile);
        $governanceClear = ! ($profile instanceof MatrimonyProfile) || $this->governanceClear($profile);
        $profileActive = $profile instanceof MatrimonyProfile && ProfileLifecycleService::isVisibleToOthers($profile);
        $searchable = $this->isSearchable($user, $profile);

        return [
            $this->item('mobile_verified', 'Mobile verified', $mobileVerified, true, $mobileVerified ? 'complete' : 'missing', $mobileVerified ? 'Mobile verified' : 'Verify mobile number'),
            $this->item('account_details_complete', 'Account details complete', $accountComplete, true, $accountComplete ? 'complete' : 'missing', $accountComplete ? 'Creator name added' : 'Add creator name'),
            $this->item('email_added_optional', 'Email added', $emailPresent && $emailVerified, false, $emailPresent ? ($emailVerified ? 'complete' : 'unverified') : 'optional', $emailPresent ? ($emailVerified ? 'Email verified' : 'Email unverified; optional') : 'Email is optional'),
            $this->item(
                'required_fields_complete',
                'Required fields complete',
                $requiredComplete,
                true,
                $requiredComplete ? 'complete' : 'missing',
                $requiredComplete ? 'Required profile fields complete' : 'Required profile fields are missing',
                // Named, not counted. "Required fields are missing" sends a
                // member into eleven sections to guess which; the names send
                // them to one.
                ($requiredComplete || ! $profile instanceof MatrimonyProfile)
                    ? []
                    : ProfileCompletenessService::missingMandatoryFields($profile),
            ),
            $this->item('location_valid', 'Location valid', $locationValid, true, $locationStatus, $locationMessage),
            $this->item('photo_uploaded', 'Photo uploaded', $photoUploaded, true, $photoUploaded ? 'complete' : 'missing', $photoUploaded ? 'Photo uploaded' : 'Upload profile photo'),
            $this->item('photo_approved', 'Photo approved', $photoApproved, true, $photoApproved ? 'complete' : ($photoUploaded ? 'pending' : 'missing'), $photoApproved ? 'Photo approved' : ($photoUploaded ? 'Photo approval pending' : 'Upload a photo for approval')),
            $this->item('governance_clear', 'Governance clear', $governanceClear, true, $governanceClear ? 'complete' : 'pending', $governanceClear ? 'No pending governance conflict' : 'Governance review pending'),
            $this->item('profile_active', 'Profile active', $profileActive, false, $profileActive ? 'active' : 'draft', $profileActive ? 'Profile is active' : 'Profile is not active yet'),
            $this->item('profile_searchable', 'Profile searchable', $searchable, false, $searchable ? 'searchable' : 'not_searchable', $searchable ? 'Profile can appear in search' : 'Profile is not searchable yet'),
        ];
    }

    /**
     * Everything standing between this member and being findable, in the order
     * they meet it — first entry is the one to act on now.
     *
     * ONE loop, and the only ranking of blockers that exists. The app used to
     * keep its own, which is how a screen came to say "complete your profile"
     * while the ring beside it circled the photo button.
     *
     * The rows here are exactly the gates {@see isSearchable()} applies, no
     * more: `account_details_complete` is blocking on the checklist but is NOT
     * a search gate, and naming it would send a member to add a creator name
     * that changes nothing about why they cannot be found.
     *
     * @param  list<array<string, mixed>>|null  $items  reuse an already-built checklist
     * @return list<array{key: string, label: string, message: string, action: string, actionable_by_member: bool}>
     */
    public function blockerQueue(
        User $user,
        ?MatrimonyProfile $profile = null,
        ?MobileOnboardingDraft $draft = null,
        ?array $items = null
    ): array {
        $profile ??= $user->matrimonyProfile;

        if ($this->isSearchable($user, $profile)) {
            return [];
        }

        $rows = collect($items ?? $this->items($user, $profile, $draft))->keyBy('key');
        $queue = [];

        foreach (self::BLOCKER_ORDER as $key => $meta) {
            $row = $rows->get($key);
            if (! is_array($row) || ($row['complete'] ?? false) === true) {
                continue;
            }

            $queue[] = [
                'key' => $key,
                'label' => (string) ($row['label'] ?? $key),
                'message' => (string) ($row['message'] ?? ''),
                'action' => $meta['action'],
                'actionable_by_member' => $meta['actionable_by_member'],
                'missing_fields' => array_values((array) ($row['missing_fields'] ?? [])),
            ];
        }

        return $queue;
    }

    /**
     * The ONE thing to do next, or null when nothing is in the way.
     *
     * @return array{key: string, label: string, message: string, action: string, actionable_by_member: bool}|null
     */
    public function topBlocker(
        User $user,
        ?MatrimonyProfile $profile = null,
        ?MobileOnboardingDraft $draft = null,
        ?array $items = null
    ): ?array {
        return $this->blockerQueue($user, $profile, $draft, $items)[0] ?? null;
    }

    /**
     * How far along the member is, as a pair they can be shown: "5 of 7 done".
     *
     * Counted over the SAME rows blockerQueue ranks, so the bar can never fill
     * while a blocker remains, and can never stall while none does.
     *
     * @param  list<array<string, mixed>>|null  $items
     * @return array{done: int, total: int}
     */
    public function activationProgress(
        User $user,
        ?MatrimonyProfile $profile = null,
        ?MobileOnboardingDraft $draft = null,
        ?array $items = null
    ): array {
        $profile ??= $user->matrimonyProfile;
        $rows = collect($items ?? $this->items($user, $profile, $draft))->keyBy('key');

        $total = 0;
        $done = 0;
        foreach (array_keys(self::BLOCKER_ORDER) as $key) {
            $row = $rows->get($key);
            if (! is_array($row)) {
                continue;
            }
            $total++;
            if (($row['complete'] ?? false) === true) {
                $done++;
            }
        }

        return ['done' => $done, 'total' => $total];
    }

    /**
     * The gates a member must pass to be findable, in the order they meet them,
     * each with the screen that resolves it.
     *
     * Photo approval is deliberately actionable: review is automatic, so a held
     * photo is answered by sending another one and never by waiting on a human.
     * `profile_active` and `governance_clear` are the only two that genuinely
     * are not the member's to clear.
     */
    private const BLOCKER_ORDER = [
        'mobile_verified' => ['action' => 'verify_mobile', 'actionable_by_member' => true],
        'required_fields_complete' => ['action' => 'complete_profile', 'actionable_by_member' => true],
        'location_valid' => ['action' => 'set_location', 'actionable_by_member' => true],
        'photo_uploaded' => ['action' => 'upload_photo', 'actionable_by_member' => true],
        'photo_approved' => ['action' => 'upload_photo', 'actionable_by_member' => true],
        'governance_clear' => ['action' => 'wait', 'actionable_by_member' => false],
        'profile_active' => ['action' => 'wait', 'actionable_by_member' => false],
    ];

    public function isSearchable(User $user, ?MatrimonyProfile $profile = null): bool
    {
        $profile ??= $user->matrimonyProfile;
        if (! $profile instanceof MatrimonyProfile) {
            return false;
        }

        return $user->mobile_verified_at !== null
            && ProfileLifecycleService::isVisibleToOthers($profile)
            && $this->requiredFieldsComplete($profile)
            && $this->locationValid($profile)
            && $this->photoUploaded($profile)
            && $this->photoApproved($profile)
            && $this->governanceClear($profile);
    }

    /**
     * When the current wait started, for the blockers where a member is owed an
     * answer rather than asked for one. Null when nothing is pending on us —
     * "since" on a step the member simply has not done yet would read as a
     * complaint about them.
     */
    public function waitingSince(?MatrimonyProfile $profile, string $blockerKey): ?string
    {
        if (! $profile instanceof MatrimonyProfile) {
            return null;
        }

        if ($blockerKey === 'governance_clear' && Schema::hasTable('conflict_records')) {
            $since = ConflictRecord::query()
                ->where('profile_id', $profile->id)
                ->where('resolution_status', 'PENDING')
                ->min('created_at');

            return $since !== null ? Carbon::parse($since)->toISOString() : null;
        }

        if ($blockerKey === 'photo_approved' && $this->photoUploaded($profile) && Schema::hasTable('profile_photos')) {
            $since = ProfilePhoto::query()
                ->where('profile_id', $profile->id)
                ->min('created_at');

            return $since !== null ? Carbon::parse($since)->toISOString() : null;
        }

        return null;
    }

    public function profileSummary(?MatrimonyProfile $profile, ?User $user = null, ?MobileOnboardingDraft $draft = null): ?array
    {
        if (! $profile instanceof MatrimonyProfile) {
            return null;
        }

        $user ??= $profile->user;
        $locationValid = $this->locationValid($profile);
        $pendingLocation = $this->pendingLocationPayload($draft);

        return [
            'id' => (int) $profile->id,
            'profile_status' => $this->profileStatus($profile),
            'lifecycle_state' => $profile->lifecycle_state,
            'is_searchable' => $user instanceof User ? $this->isSearchable($user, $profile) : false,
            'photo_uploaded' => $this->photoUploaded($profile),
            'photo_approved' => $this->photoApproved($profile),
            'location_valid' => $locationValid,
            'location_status' => $locationValid ? 'complete' : ($pendingLocation !== null ? 'pending' : 'missing'),
            'pending_location' => $pendingLocation,
        ];
    }

    public function pendingLocationPayload(?MobileOnboardingDraft $draft): ?array
    {
        $data = $draft?->draft_data;
        $location = is_array($data) ? ($data['location'] ?? []) : [];
        if (! is_array($location)) {
            return null;
        }

        $requestId = $location['pending_location_request_id'] ?? null;
        $status = trim((string) ($location['pending_location_status'] ?? ''));
        if (($requestId === null || $requestId === '') && $status !== 'pending') {
            return null;
        }

        return [
            'request_id' => $requestId !== null && $requestId !== '' ? (int) $requestId : null,
            'label' => $location['pending_location_label'] ?? null,
            'status' => $status !== '' ? $status : 'pending',
            'type' => $location['pending_location_type'] ?? null,
        ];
    }

    public function profileStatus(?MatrimonyProfile $profile): ?string
    {
        if (! $profile instanceof MatrimonyProfile) {
            return null;
        }

        return (string) ($profile->lifecycle_state ?: 'draft');
    }

    public function requiredFieldsComplete(MatrimonyProfile $profile): bool
    {
        try {
            return app(RuleEngineService::class)->mandatoryCoreCompletionIsComplete($profile);
        } catch (\Throwable) {
            return false;
        }
    }

    public function locationValid(MatrimonyProfile $profile): bool
    {
        $locationId = null;
        if (Schema::hasColumn($profile->getTable(), 'location_id')) {
            $locationId = $profile->location_id;
        }
        if (($locationId === null || $locationId === '') && $profile->exists) {
            $locationId = ProfileCanonicalResidenceService::locationLeafId((int) $profile->id);
        }
        if ($locationId === null || $locationId === '' || (int) $locationId <= 0) {
            return false;
        }
        if (! Schema::hasTable(Location::geoTable())) {
            return false;
        }

        $location = Location::query()->find((int) $locationId);
        if (! $location instanceof Location) {
            return false;
        }

        return (bool) ($location->is_active ?? false)
            && (string) $location->hierarchy === 'village'
            && in_array((string) ($location->tag ?? ''), ['city', 'suburban', 'rural'], true);
    }

    public function photoUploaded(MatrimonyProfile $profile): bool
    {
        $path = trim((string) ($profile->profile_photo ?? ''));
        if ($path !== '') {
            return true;
        }

        return Schema::hasTable('profile_photos')
            && ProfilePhoto::query()->where('profile_id', $profile->id)->exists();
    }

    public function photoApproved(MatrimonyProfile $profile): bool
    {
        $path = trim((string) ($profile->profile_photo ?? ''));
        if ($path !== ''
            && ! ProfilePhotoUrlService::isPendingPlaceholder($path)
            && (bool) ($profile->photo_approved ?? false) === true
            && $profile->photo_rejected_at === null) {
            return true;
        }

        if (! Schema::hasTable('profile_photos')) {
            return false;
        }

        return ProfilePhoto::query()
            ->where('profile_id', $profile->id)
            ->where('is_primary', true)
            ->effectivelyApproved()
            ->exists();
    }

    public function governanceClear(MatrimonyProfile $profile): bool
    {
        if ((bool) ($profile->is_suspended ?? false) || $profile->trashed()) {
            return false;
        }

        if (! Schema::hasTable('conflict_records')) {
            return true;
        }

        return ! ConflictRecord::query()
            ->where('profile_id', $profile->id)
            ->where('resolution_status', 'PENDING')
            ->exists();
    }

    /**
     * @param  list<string>  $missingFields  named only where the row can name them
     */
    private function item(
        string $key,
        string $label,
        bool $complete,
        bool $blocking,
        string $status,
        string $message,
        array $missingFields = []
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'complete' => $complete,
            'blocking' => $blocking,
            'status' => $status,
            'message' => $message,
            'missing_fields' => $missingFields,
        ];
    }
}

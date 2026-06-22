<?php

namespace App\Services\Showcase;

use App\Models\MatrimonyProfile;
use App\Models\ProfilePhoto;
use App\Models\User;
use App\Services\ExtendedFieldService;
use App\Services\FieldValueHistoryService;
use App\Services\MutationService;
use App\Services\ProfileCompletionEngine;
use App\Services\RuleEngineService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use App\Services\Profile\ProfileTypedSelfAddressService;
use App\Services\ShowcaseProfileDefaultsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Single entry point for creating showcase profiles — admin bulk and auto-engine.
 */
class ShowcaseProfileFactory
{
    /**
     * Create one showcase profile (dedicated @system.local user, full autofill).
     *
     * @param  array<string, mixed>  $attributeOverrides  merged on top of {@see ShowcaseProfileDefaultsService::fullAttributesForShowcaseProfile}
     * @return int|null New matrimony_profiles.id, or null if skipped
     */
    public function create(
        int $sequenceIndex,
        ?string $genderOverride,
        int $actorUserId,
        array $attributeOverrides = [],
        string $lifecycleState = 'draft',
        ?int $searcherMatrimonyProfileId = null,
        bool $useAdminBulkFieldPolicy = false
    ): ?int {
        return $this->createWithOutcome(
            $sequenceIndex,
            $genderOverride,
            $actorUserId,
            $attributeOverrides,
            $lifecycleState,
            $searcherMatrimonyProfileId,
            $useAdminBulkFieldPolicy
        )->profileId;
    }

    /**
     * @param  array<string, mixed>  $attributeOverrides
     */
    public function createWithOutcome(
        int $sequenceIndex,
        ?string $genderOverride,
        int $actorUserId,
        array $attributeOverrides = [],
        string $lifecycleState = 'draft',
        ?int $searcherMatrimonyProfileId = null,
        bool $useAdminBulkFieldPolicy = false
    ): ShowcaseProfileCreateResult {
        $bulkPolicy = ($useAdminBulkFieldPolicy && $searcherMatrimonyProfileId === null)
            ? ShowcaseBulkCreateSettings::policy()
            : null;

        $attrs = array_merge(
            ShowcaseProfileDefaultsService::fullAttributesForShowcaseProfile($sequenceIndex, $genderOverride, $bulkPolicy),
            $attributeOverrides
        );

        $photoSkipReason = null;
        $photoCategoryLabel = null;
        $expectedPhotoFolder = null;
        $hasPhotoOverride = array_key_exists('profile_photo', $attributeOverrides)
            && trim((string) ($attributeOverrides['profile_photo'] ?? '')) !== '';

        if (! $hasPhotoOverride) {
            $resolved = ShowcaseProfileDefaultsService::resolveShowcasePhotoForAttributes($attrs);
            $photoCategoryLabel = $resolved['category_label'];
            $expectedPhotoFolder = $resolved['expected_folder'];
            if ($resolved['path'] !== null) {
                $attrs['profile_photo'] = $resolved['path'];
            } else {
                $photoSkipReason = $resolved['reason'];
                if ($photoSkipReason !== null && ShowcasePhotoPoolSettings::shouldSkipProfile($photoSkipReason)) {
                    return new ShowcaseProfileCreateResult(
                        null,
                        ShowcaseProfileCreateResult::OUTCOME_SKIPPED_NO_PHOTO,
                        $photoSkipReason,
                        $photoCategoryLabel,
                        $expectedPhotoFolder
                    );
                }
                $attrs['profile_photo'] = null;
            }
        }

        if (empty($attrs['district_id'] ?? null) || empty($attrs['city_id'] ?? null)) {
            return new ShowcaseProfileCreateResult(
                null,
                ShowcaseProfileCreateResult::OUTCOME_SKIPPED_NO_LOCATION
            );
        }

        $fullName = trim((string) ($attrs['full_name'] ?? ''));
        if ($fullName === '') {
            $fullName = 'Member';
            $attrs['full_name'] = $fullName;
        }

        $email = $this->allocateUniqueLoginEmailForShowcase($fullName);
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $fullName,
                'password' => bcrypt(Str::random(32)),
            ]
        );
        if ($user->matrimonyProfile) {
            return new ShowcaseProfileCreateResult(
                null,
                ShowcaseProfileCreateResult::OUTCOME_SKIPPED_DUPLICATE_USER
            );
        }

        $user->forceFill([
            'name' => $fullName,
        ])->save();

        $attrs['user_id'] = $user->id;
        $attrs['is_showcase'] = true;
        $attrs['is_suspended'] = false;
        $desiredLifecycle = in_array($lifecycleState, ['draft', 'active'], true) ? $lifecycleState : 'draft';
        $cityIdForResidence = isset($attrs['city_id']) ? (int) $attrs['city_id'] : 0;
        if ($cityIdForResidence > 0 && ! Schema::hasColumn('matrimony_profiles', 'location_id')) {
            // Without a profile.location_id column, the model flushes this into profile_addresses on first save (draft allows null in observer until saved() runs).
            $attrs['location_id'] = $cityIdForResidence;
        }
        $attrs['lifecycle_state'] = 'draft';

        $profile = MatrimonyProfile::create($attrs);
        $this->syncShowcasePrimaryPhotoRow($profile);
        if ($cityIdForResidence > 0 && Schema::hasTable('profile_addresses')) {
            ProfileCanonicalResidenceService::upsertSelfCurrent($profile->id, $cityIdForResidence, null, true, false);
            $workLeaf = isset($attrs['work_city_id']) ? (int) $attrs['work_city_id'] : 0;
            if ($workLeaf <= 0) {
                $workLeaf = $cityIdForResidence;
            }
            ProfileTypedSelfAddressService::upsertSelfTypedLeaf($profile->id, 'work', $workLeaf > 0 ? $workLeaf : null);
        }
        if ($desiredLifecycle !== 'draft' && ($profile->lifecycle_state ?? '') !== $desiredLifecycle) {
            $profile->lifecycle_state = $desiredLifecycle;
            $profile->save();
        }
        $this->addPrimaryContact($profile);
        $this->autofillExtendedAndHistory($profile);
        $this->applyWizardLikeNarrativeAndPreferences($profile, $actorUserId, $searcherMatrimonyProfileId, $bulkPolicy);
        $this->recordHistoryForShowcaseProfile($profile);

        $profile->refresh();
        $this->syncAuthUserWithShowcaseProfile($user->fresh(), $profile);

        $createdWithoutPhoto = ! $hasPhotoOverride
            && $photoSkipReason !== null
            && trim((string) ($profile->profile_photo ?? '')) === '';

        return new ShowcaseProfileCreateResult(
            (int) $profile->id,
            $createdWithoutPhoto
                ? ShowcaseProfileCreateResult::OUTCOME_CREATED_WITHOUT_PHOTO
                : ShowcaseProfileCreateResult::OUTCOME_CREATED,
            $createdWithoutPhoto ? $photoSkipReason : null,
            $photoCategoryLabel,
            $expectedPhotoFolder
        );
    }

    /**
     * Login email for system-local showcase accounts: derived from profile full name + short suffix (unique).
     * Avoids legacy "showcase-profile-..." wording while staying on @system.local.
     */
    private function allocateUniqueLoginEmailForShowcase(string $fullName): string
    {
        $ascii = Str::ascii(trim($fullName));
        $slug = Str::slug((string) $ascii, '.');
        if ($slug === '' || $slug === '.') {
            $slug = 'member';
        }
        $slug = substr($slug, 0, 44);

        for ($attempt = 0; $attempt < 25; $attempt++) {
            $candidate = $slug.'.'.Str::lower(Str::random(4)).'@system.local';
            if (! User::query()->where('email', $candidate)->exists()) {
                return $candidate;
            }
        }

        for ($attempt = 0; $attempt < 25; $attempt++) {
            $fallback = 'member.'.Str::lower(Str::random(12)).'@system.local';
            if (! User::query()->where('email', $fallback)->exists()) {
                return $fallback;
            }
        }

        return 'member.'.Str::lower(Str::random(16)).'@system.local';
    }

    private function syncShowcasePrimaryPhotoRow(MatrimonyProfile $profile): void
    {
        if (! Schema::hasTable('profile_photos')) {
            return;
        }

        $photoPath = ltrim(str_replace('\\', '/', trim((string) ($profile->profile_photo ?? ''))), '/');
        if ($photoPath === '' || str_contains($photoPath, '..')) {
            return;
        }

        DB::transaction(function () use ($profile, $photoPath): void {
            $existing = ProfilePhoto::query()
                ->where('profile_id', $profile->id)
                ->where('file_path', $photoPath)
                ->first();

            if ($existing !== null) {
                ProfilePhoto::query()
                    ->where('profile_id', $profile->id)
                    ->where('id', '!=', $existing->id)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);

                if (! $existing->is_primary || $existing->approved_status !== 'approved') {
                    $existing->forceFill([
                        'is_primary' => true,
                        'approved_status' => 'approved',
                    ])->save();
                }

                return;
            }

            ProfilePhoto::query()
                ->where('profile_id', $profile->id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);

            $row = [
                'profile_id' => $profile->id,
                'file_path' => $photoPath,
                'is_primary' => true,
                'uploaded_via' => 'user_web',
                'approved_status' => 'approved',
                'watermark_detected' => false,
            ];
            if (Schema::hasColumn('profile_photos', 'sort_order')) {
                $row['sort_order'] = 0;
            }

            ProfilePhoto::withoutEvents(fn () => ProfilePhoto::query()->create($row));
        });
    }

    /**
     * Keep {@see User} display fields aligned with {@see MatrimonyProfile} after autofill (same as member-facing profile).
     */
    private function syncAuthUserWithShowcaseProfile(User $user, MatrimonyProfile $profile): void
    {
        $name = trim((string) ($profile->full_name ?? ''));
        if ($name === '') {
            $name = trim((string) ($user->name ?? '')) ?: 'Member';
        }

        $user->forceFill([
            'name' => $name,
        ])->save();
    }

    private function addPrimaryContact(MatrimonyProfile $profile): void
    {
        $phone = ShowcaseProfileDefaultsService::randomPrimaryPhone();
        $contactRelationId = null;
        if (Schema::hasColumn('profile_contacts', 'contact_relation_id')) {
            $contactRelationId = DB::table('master_contact_relations')->where('key', 'self')->value('id');
        }
        $row = [
            'profile_id' => $profile->id,
            'contact_name' => $profile->full_name,
            'phone_number' => $phone,
            'is_primary' => true,
            'visibility_rule' => 'unlock_only',
            'verified_status' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if ($contactRelationId !== null) {
            $row['contact_relation_id'] = $contactRelationId;
        }
        if (Schema::hasColumn('profile_contacts', 'relation_type')) {
            $row['relation_type'] = 'self';
        }
        DB::table('profile_contacts')->insert($row);
    }

    private function recordHistoryForShowcaseProfile(MatrimonyProfile $profile): void
    {
        $tbl = $profile->getTable();
        $coreKeys = [
            'full_name', 'gender_id', 'date_of_birth', 'marital_status_id', 'highest_education',
            'religion_id', 'caste_id', 'sub_caste_id', 'height_cm', 'profile_photo', 'photo_approved',
            'is_showcase', 'is_suspended', 'company_name',
            'annual_income', 'family_income', 'father_name', 'mother_name',
        ];
        if (Schema::hasColumn($tbl, 'occupation_title')) {
            $coreKeys[] = 'occupation_title';
        }
        if (Schema::hasColumn($tbl, 'occupation_master_id')) {
            $coreKeys[] = 'occupation_master_id';
        }
        foreach ($coreKeys as $fieldKey) {
            if (! isset($profile->$fieldKey)) {
                continue;
            }
            $newVal = $profile->$fieldKey;
            if ($newVal instanceof \Carbon\Carbon) {
                $newVal = $newVal->format('Y-m-d');
            }
            $newVal = $newVal === '' || $newVal === null ? null : (string) $newVal;
            if (in_array($fieldKey, ['photo_approved', 'is_showcase', 'is_suspended'], true)) {
                $newVal = $newVal === null ? null : ($newVal ? '1' : '0');
            }
            FieldValueHistoryService::record($profile->id, $fieldKey, 'CORE', null, $newVal, FieldValueHistoryService::CHANGED_BY_SYSTEM);
        }
    }

    /**
     * @param  array<string, mixed>|null  $bulkPolicy  normalized policy for admin bulk only
     */
    private function applyWizardLikeNarrativeAndPreferences(MatrimonyProfile $profile, int $actorUserId, ?int $searcherMatrimonyProfileId, ?array $bulkPolicy = null): void
    {
        $snapshot = ShowcaseProfileDefaultsService::postCreateSnapshotForShowcaseProfile($profile->fresh(), $bulkPolicy);
        $searcher = $searcherMatrimonyProfileId
            ? MatrimonyProfile::query()->find((int) $searcherMatrimonyProfileId)
            : null;
        $snapshot['preferences'] = app(ShowcasePartnerPreferenceSnapshotBuilder::class)
            ->preferencesForShowcase($profile->fresh(), $searcher);

        app(MutationService::class)->applyManualSnapshot(
            $profile->fresh(),
            [
                'extended_narrative' => $snapshot['extended_narrative'] ?? [],
                'preferences' => $snapshot['preferences'] ?? [],
            ],
            $actorUserId > 0 ? $actorUserId : 0,
            'manual'
        );
    }

    private function autofillExtendedAndHistory(MatrimonyProfile $profile): void
    {
        $extended = ShowcaseProfileDefaultsService::extendedDefaultsForProfile();
        if (! empty($extended)) {
            ExtendedFieldService::saveValuesForProfile($profile, $extended, null);
        }
        $ruleEngine = app(RuleEngineService::class);
        $pct = app(ProfileCompletionEngine::class)->forProfile($profile)['mandatory_core'];
        $warnBelow = $ruleEngine->resolveShowcaseAutofillLogMinCorePercent();
        if ($warnBelow > 0 && $pct < $warnBelow) {
            \Log::info('Showcase profile autofill: completeness '.$pct.'% for profile '.$profile->id.' (threshold '.$warnBelow.'% from system_rules).');
        }
    }
}

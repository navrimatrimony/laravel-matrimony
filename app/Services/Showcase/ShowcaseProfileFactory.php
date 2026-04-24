<?php

namespace App\Services\Showcase;

use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\ExtendedFieldService;
use App\Services\FieldValueHistoryService;
use App\Services\MutationService;
use App\Services\ProfileCompletionEngine;
use App\Services\RuleEngineService;
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
     * @return int|null New matrimony_profiles.id, or null if skipped (e.g. user already owns a profile or missing location)
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
        $bulkPolicy = ($useAdminBulkFieldPolicy && $searcherMatrimonyProfileId === null)
            ? ShowcaseBulkCreateSettings::policy()
            : null;

        $attrs = array_merge(
            ShowcaseProfileDefaultsService::fullAttributesForShowcaseProfile($sequenceIndex, $genderOverride, $bulkPolicy),
            $attributeOverrides
        );

        if (empty($attrs['district_id'] ?? null) || empty($attrs['city_id'] ?? null)) {
            return null;
        }

        $fullName = trim((string) ($attrs['full_name'] ?? ''));
        if ($fullName === '') {
            $fullName = 'Member';
            $attrs['full_name'] = $fullName;
        }

        $email = $this->allocateUniqueLoginEmailForShowcase($fullName);
        $userGender = $this->genderKeyFromProfileGenderId((int) ($attrs['gender_id'] ?? 0));

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $fullName,
                'password' => bcrypt(Str::random(32)),
                'gender' => $userGender,
            ]
        );
        if ($user->matrimonyProfile) {
            return null;
        }

        $user->forceFill([
            'name' => $fullName,
            'gender' => $userGender,
        ])->save();

        $attrs['user_id'] = $user->id;
        $attrs['is_showcase'] = true;
        $attrs['is_suspended'] = false;
        $attrs['lifecycle_state'] = in_array($lifecycleState, ['draft', 'active'], true) ? $lifecycleState : 'draft';

        $profile = MatrimonyProfile::create($attrs);
        $this->addPrimaryContact($profile);
        $this->autofillExtendedAndHistory($profile);
        $this->applyWizardLikeNarrativeAndPreferences($profile, $actorUserId, $searcherMatrimonyProfileId, $bulkPolicy);
        $this->recordHistoryForShowcaseProfile($profile);

        $profile->refresh();
        $this->syncAuthUserWithShowcaseProfile($user->fresh(), $profile);

        return (int) $profile->id;
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

    private function genderKeyFromProfileGenderId(int $genderId): string
    {
        if ($genderId <= 0) {
            return 'other';
        }

        $key = MasterGender::query()->where('id', $genderId)->value('key');
        $key = $key !== null ? (string) $key : '';

        return in_array($key, ['male', 'female'], true) ? $key : 'other';
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
            'gender' => $this->genderKeyFromProfileGenderId((int) ($profile->gender_id ?? 0)),
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
        $coreKeys = [
            'full_name', 'gender_id', 'date_of_birth', 'marital_status_id', 'highest_education',
            'religion_id', 'caste_id', 'sub_caste_id', 'height_cm', 'profile_photo', 'photo_approved',
            'is_showcase', 'is_suspended', 'specialization', 'occupation_title', 'company_name',
            'annual_income', 'family_income', 'father_name', 'mother_name',
        ];
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

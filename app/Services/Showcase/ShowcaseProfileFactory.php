<?php

namespace App\Services\Showcase;

use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\DemoProfileDefaultsService;
use App\Services\ExtendedFieldService;
use App\Services\FieldValueHistoryService;
use App\Services\MutationService;
use App\Services\ProfileCompletenessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Single entry point for creating showcase (demo) profiles — admin bulk and auto-engine.
 */
class ShowcaseProfileFactory
{
    /**
     * Create one showcase profile (dedicated @system.local user, full autofill).
     *
     * @param  array<string, mixed>  $attributeOverrides  merged on top of {@see DemoProfileDefaultsService::fullAttributesForDemoProfile}
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
        $email = 'showcase-profile-'.Str::random(8).'@system.local';
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Showcase '.($sequenceIndex + 1),
                'password' => bcrypt(Str::random(32)),
                'gender' => 'other',
            ]
        );
        if ($user->matrimonyProfile) {
            return null;
        }

        $bulkPolicy = ($useAdminBulkFieldPolicy && $searcherMatrimonyProfileId === null)
            ? ShowcaseBulkCreateSettings::policy()
            : null;

        $attrs = array_merge(
            DemoProfileDefaultsService::fullAttributesForDemoProfile($sequenceIndex, $genderOverride, $bulkPolicy),
            $attributeOverrides
        );

        if (empty($attrs['district_id'] ?? null) || empty($attrs['city_id'] ?? null)) {
            return null;
        }

        $attrs['user_id'] = $user->id;
        $attrs['is_demo'] = true;
        $attrs['is_suspended'] = false;
        $attrs['lifecycle_state'] = in_array($lifecycleState, ['draft', 'active'], true) ? $lifecycleState : 'draft';

        $profile = MatrimonyProfile::create($attrs);
        $this->addPrimaryContact($profile);
        $this->autofillExtendedAndHistory($profile);
        $this->applyWizardLikeNarrativeAndPreferences($profile, $actorUserId, $searcherMatrimonyProfileId, $bulkPolicy);
        $this->recordHistoryForShowcaseProfile($profile);

        return (int) $profile->id;
    }

    private function addPrimaryContact(MatrimonyProfile $profile): void
    {
        $phone = DemoProfileDefaultsService::randomPrimaryPhone();
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
            'is_demo', 'is_suspended', 'specialization', 'occupation_title', 'company_name',
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
            if (in_array($fieldKey, ['photo_approved', 'is_demo', 'is_suspended'], true)) {
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
        $snapshot = DemoProfileDefaultsService::postCreateSnapshotForDemoProfile($profile->fresh(), $bulkPolicy);
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
        $extended = DemoProfileDefaultsService::extendedDefaultsForProfile();
        if (! empty($extended)) {
            ExtendedFieldService::saveValuesForProfile($profile, $extended, null);
        }
        $pct = ProfileCompletenessService::percentage($profile);
        if ($pct < 80) {
            \Log::info('Showcase profile autofill: completeness '.$pct.'% for profile '.$profile->id.' (target ≥80%).');
        }
    }
}

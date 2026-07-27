<?php

namespace Tests\Feature\Suchak\Concerns;

use App\Models\City;
use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakConsent;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The member / candidate / Suchak triangle every Suchak request test needs:
 * an active member, an active candidate represented under a VALID consent, and
 * a verified publicly-routable Suchak account.
 *
 * Extracted so the pipeline test and the chat-read test build the same world —
 * two fixtures that drift would silently test two different products.
 */
trait BuildsSuchakRequestFixture
{
    /**
     * @return array{
     *     member: User,
     *     member_profile: MatrimonyProfile,
     *     candidate: User,
     *     target_profile: MatrimonyProfile,
     *     account: SuchakAccount,
     *     representation: SuchakProfileRepresentation
     * }
     */
    private function fixture(
        string $memberMobile = '9876500001',
        string $candidateMobile = '9876500002',
        string $suchakMobile = '9876500003',
    ): array {
        foreach (['male' => 'Male', 'female' => 'Female'] as $key => $label) {
            MasterGender::query()->firstOrCreate(['key' => $key], ['label' => $label, 'is_active' => true]);
        }

        $maleId = (int) DB::table('master_genders')->where('key', 'male')->value('id');
        $femaleId = (int) DB::table('master_genders')->where('key', 'female')->value('id');

        $member = User::factory()->create(['mobile' => $memberMobile, 'mobile_verified_at' => now()]);
        $memberProfile = $this->activeProfile([
            'user_id' => $member->id,
            'full_name' => 'Seeking Member',
            'gender_id' => $maleId,
        ]);

        $candidate = User::factory()->create(['mobile' => $candidateMobile, 'mobile_verified_at' => now()]);
        $targetProfile = $this->activeProfile([
            'user_id' => $candidate->id,
            'full_name' => 'Represented Candidate',
            'gender_id' => $femaleId,
        ]);

        $suchakUser = User::factory()->create(['mobile' => $suchakMobile, 'mobile_verified_at' => now()]);
        $account = SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'office_name' => 'Adarsh Vivah Kendra',
            'office_name_mr' => null,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);

        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $targetProfile->id,
            // A Suchak-created profile is Suchak-routed by definition, which is
            // what makes the member's contact card show the Suchak instead of a
            // number.
            'representation_mode' => SuchakProfileRepresentation::MODE_MANUAL_FORM_BY_SUCHAK,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'first_verified_consent_at' => now(),
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ]);

        SuchakConsent::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $targetProfile->id,
            'representation_id' => $representation->id,
            'consent_status' => SuchakConsent::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'used_at' => now(),
            'otp_verified_at' => now(),
            'valid_from' => now(),
            'valid_until' => $representation->consent_valid_until,
        ]);

        return [
            'member' => $member,
            'member_profile' => $memberProfile,
            'candidate' => $candidate,
            'target_profile' => $targetProfile,
            'account' => $account,
            'representation' => $representation,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function activeProfile(array $attributes): MatrimonyProfile
    {
        $profile = MatrimonyProfile::factory()->create(array_merge($attributes, [
            'lifecycle_state' => 'draft',
        ]));

        $leafId = (int) City::query()->where('name', 'Pune City')->firstOrFail()->id;

        if (Schema::hasColumn($profile->getTable(), 'location_id')) {
            DB::table($profile->getTable())->where('id', $profile->id)->update(['location_id' => $leafId]);
            $profile->refresh();
        } else {
            ProfileCanonicalResidenceService::upsertSelfCurrent((int) $profile->id, $leafId, null, true, false);
        }

        $profile->update([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);

        return $profile->fresh();
    }
}

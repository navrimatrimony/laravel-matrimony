<?php

namespace Tests\Feature\Showcase;

use App\Models\FeatureFlag;
use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\Onboarding\ActivationChecklistService;
use App\Support\FeatureFlagKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Showcase profiles are seeded, not signed up: their owner is a @system.local
 * shell with no email and no phone to verify. Verification must therefore never
 * become a condition of a showcase profile existing, going live, or being seen.
 * The only switch over showcase is the feature flag.
 *
 * These are regression locks. They pass today; they exist so that a future
 * verification gate cannot be added without this file going red first.
 */
class ShowcaseProfileVerificationExemptionTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        FeatureFlag::query()->create([
            'key' => FeatureFlagKey::SHOWCASE_PROFILES,
            'display_name' => 'Showcase Profiles',
            'description' => 'Verification exemption test',
            'enabled' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'is_admin' => true,
            'admin_role' => 'super_admin',
        ]);
    }

    /**
     * Born the way the engine makes them: draft, owned by a shell account with
     * nothing to verify.
     *
     * @return array{0: User, 1: MatrimonyProfile}
     */
    private function unverifiedShowcase(): array
    {
        $owner = User::factory()->create([
            'is_admin' => false,
            'email_verified_at' => null,
            'mobile' => null,
            'mobile_verified_at' => null,
        ]);

        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $owner->id,
            'is_showcase' => true,
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);

        return [$owner, $profile];
    }

    /** Activation through the same admin route production uses. */
    private function publish(MatrimonyProfile $profile): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.showcase-profile.publish', ['profile' => $profile->id]))
            ->assertRedirect();

        $profile->refresh();
    }

    public function test_showcase_profile_is_created_and_saved_without_verified_email_or_mobile(): void
    {
        [$owner, $profile] = $this->unverifiedShowcase();

        $this->assertNull($owner->email_verified_at);
        $this->assertNull($owner->mobile_verified_at);
        $this->assertTrue($profile->exists);
        $this->assertTrue($profile->isShowcaseProfile());

        // Saving again must not start requiring verification either.
        $profile->forceFill(['is_suspended' => false])->save();
        $this->assertDatabaseHas('matrimony_profiles', [
            'id' => $profile->id,
            'is_showcase' => true,
        ]);
    }

    public function test_showcase_profile_is_activated_without_verified_email_or_mobile(): void
    {
        [, $profile] = $this->unverifiedShowcase();

        $this->publish($profile);

        $this->assertDatabaseHas('matrimony_profiles', [
            'id' => $profile->id,
            'lifecycle_state' => 'active',
            'is_suspended' => 0,
        ]);
    }

    public function test_active_showcase_profile_is_publicly_visible_without_verified_email_or_mobile(): void
    {
        [, $profile] = $this->unverifiedShowcase();
        $this->publish($profile);

        $this->get(route('profile.share.public', ['id' => $profile->id]))
            ->assertOk();
    }

    public function test_active_showcase_profile_is_returned_by_the_api_without_verified_email_or_mobile(): void
    {
        // Discovery pairs profiles by opposite gender, so both sides need one.
        // Nothing here verifies an email or a phone — that is the point.
        $male = MasterGender::query()->firstOrCreate(['key' => 'male'], ['label' => 'Male', 'is_active' => true]);
        $female = MasterGender::query()->firstOrCreate(['key' => 'female'], ['label' => 'Female', 'is_active' => true]);

        [, $profile] = $this->unverifiedShowcase();
        $profile->forceFill(['gender_id' => $female->id])->save();
        $this->publish($profile);

        $member = User::factory()->create(['is_admin' => false]);
        $viewerProfile = MatrimonyProfile::factory()->create([
            'user_id' => $member->id,
            'is_showcase' => false,
            'lifecycle_state' => 'draft',
        ]);
        $viewerProfile->forceFill(['gender_id' => $male->id])->save();

        Sanctum::actingAs($member->fresh());
        $this->getJson('/api/v1/matrimony-profiles/'.$profile->id)
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_member_verification_rules_are_unchanged(): void
    {
        // The member checklist still treats mobile verification as blocking and
        // still refuses to call an unverified member searchable. Exempting
        // showcase must not have relaxed this.
        $member = User::factory()->create([
            'is_admin' => false,
            'mobile_verified_at' => null,
        ]);
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $member->id,
            'is_showcase' => false,
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);

        $checklist = app(ActivationChecklistService::class);
        $items = collect($checklist->items($member, $profile));
        $mobileItem = $items->firstWhere('key', 'mobile_verified');

        $this->assertNotNull($mobileItem);
        $this->assertTrue($mobileItem['blocking']);
        $this->assertFalse($mobileItem['complete']);
        $this->assertFalse($checklist->isSearchable($member, $profile));
    }
}

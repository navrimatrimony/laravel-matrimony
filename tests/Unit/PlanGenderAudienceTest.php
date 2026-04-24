<?php

namespace Tests\Unit;

use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanGenderAudienceTest extends TestCase
{
    use RefreshDatabase;

    private static function seedGenders(): void
    {
        MasterGender::query()->firstOrCreate(
            ['key' => 'male'],
            ['label' => 'Male', 'is_active' => true],
        );
        MasterGender::query()->firstOrCreate(
            ['key' => 'female'],
            ['label' => 'Female', 'is_active' => true],
        );
        MasterGender::query()->firstOrCreate(
            ['key' => 'other'],
            ['label' => 'Other', 'is_active' => true],
        );
    }

    public function test_all_target_visible_to_guest_and_logged_in_without_gender(): void
    {
        $allPlan = new Plan(['applies_to_gender' => 'all']);
        $this->assertTrue(Plan::profileGenderAllowsPlan(null, $allPlan));

        self::seedGenders();
        $user = User::factory()->create();
        MatrimonyProfile::factory()->for($user)->create([
            'lifecycle_state' => 'active',
        ]);

        $this->assertTrue(Plan::profileGenderAllowsPlan($user->fresh(), $allPlan));
    }

    public function test_gendered_plan_hidden_for_guest(): void
    {
        $femalePlan = new Plan(['applies_to_gender' => 'female']);
        $this->assertFalse(Plan::profileGenderAllowsPlan(null, $femalePlan));
    }

    public function test_gendered_plan_hidden_when_profile_gender_missing_or_other(): void
    {
        self::seedGenders();
        $userNoGender = User::factory()->create();
        MatrimonyProfile::factory()->for($userNoGender)->create([
            'gender_id' => null,
            'lifecycle_state' => 'active',
        ]);
        $malePlan = new Plan(['applies_to_gender' => 'male']);
        $this->assertFalse(Plan::profileGenderAllowsPlan($userNoGender->fresh(), $malePlan));

        $otherId = MasterGender::query()->where('key', 'other')->value('id');
        $this->assertNotNull($otherId);
        $userOther = User::factory()->create();
        MatrimonyProfile::factory()->for($userOther)->create([
            'gender_id' => $otherId,
            'lifecycle_state' => 'active',
        ]);
        $this->assertFalse(Plan::profileGenderAllowsPlan($userOther->fresh(), $malePlan));
    }

    public function test_male_profile_never_allowed_female_plan(): void
    {
        self::seedGenders();
        $maleId = MasterGender::query()->where('key', 'male')->value('id');
        $this->assertNotNull($maleId);
        $user = User::factory()->create();
        MatrimonyProfile::factory()->for($user)->create([
            'gender_id' => $maleId,
            'lifecycle_state' => 'active',
        ]);
        $femalePlan = new Plan(['applies_to_gender' => 'female']);
        $this->assertFalse(Plan::profileGenderAllowsPlan($user->fresh(), $femalePlan));
        $malePlan = new Plan(['applies_to_gender' => 'male']);
        $this->assertTrue(Plan::profileGenderAllowsPlan($user->fresh(), $malePlan));
    }
}

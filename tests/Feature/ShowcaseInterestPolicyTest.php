<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Interest;
use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\Showcase\ShowcaseInterestPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowcaseInterestPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function seedGenders(): array
    {
        $male = MasterGender::query()->firstOrCreate(
            ['key' => 'male'],
            ['label' => 'Male', 'is_active' => true]
        );
        $female = MasterGender::query()->firstOrCreate(
            ['key' => 'female'],
            ['label' => 'Female', 'is_active' => true]
        );

        return [(int) $male->id, (int) $female->id];
    }

    public function test_send_policy_inactive_allows_directions(): void
    {
        AdminSetting::setValue('showcase_interest_rules_enabled', '0');
        AdminSetting::setValue('showcase_interest_allow_showcase_to_real', '0');

        [$maleGid, $femaleGid] = $this->seedGenders();
        $showcase = MatrimonyProfile::factory()->create([
            'gender_id' => $maleGid,
            'is_showcase' => true,
            'lifecycle_state' => 'active',
        ]);
        $real = MatrimonyProfile::factory()->create([
            'gender_id' => $femaleGid,
            'is_showcase' => false,
            'lifecycle_state' => 'active',
        ]);

        $svc = app(ShowcaseInterestPolicyService::class);
        $r = $svc->evaluateSendInterest($showcase, $real);
        $this->assertTrue($r['ok']);
        $this->assertFalse($r['bypass_plan_quota']);
    }

    public function test_send_policy_blocks_showcase_to_real_when_disabled(): void
    {
        AdminSetting::setValue('showcase_interest_rules_enabled', '1');
        AdminSetting::setValue('showcase_interest_allow_showcase_to_real', '0');
        AdminSetting::setValue('showcase_interest_allow_real_to_showcase', '1');
        AdminSetting::setValue('showcase_interest_allow_showcase_to_showcase_send', '1');

        [$maleGid, $femaleGid] = $this->seedGenders();
        $showcase = MatrimonyProfile::factory()->create([
            'gender_id' => $maleGid,
            'is_showcase' => true,
            'lifecycle_state' => 'active',
        ]);
        $real = MatrimonyProfile::factory()->create([
            'gender_id' => $femaleGid,
            'is_showcase' => false,
            'lifecycle_state' => 'active',
        ]);

        $svc = app(ShowcaseInterestPolicyService::class);
        $r = $svc->evaluateSendInterest($showcase, $real);
        $this->assertFalse($r['ok']);
        $this->assertNotEmpty($r['message']);
    }

    public function test_bypass_plan_toggle_only_for_showcase_sender(): void
    {
        AdminSetting::setValue('showcase_interest_bypass_plan_send_quota_for_showcase_sender', '1');

        [$maleGid, $femaleGid] = $this->seedGenders();
        $showcase = MatrimonyProfile::factory()->create([
            'gender_id' => $maleGid,
            'is_showcase' => true,
            'lifecycle_state' => 'active',
        ]);
        $real = MatrimonyProfile::factory()->create([
            'gender_id' => $femaleGid,
            'is_showcase' => false,
            'lifecycle_state' => 'active',
        ]);

        $svc = app(ShowcaseInterestPolicyService::class);
        $this->assertTrue($svc->shouldBypassPlanSendQuota($showcase));
        $this->assertFalse($svc->shouldBypassPlanSendQuota($real));
    }

    public function test_accept_policy_blocks_when_both_showcase_disabled(): void
    {
        AdminSetting::setValue('showcase_interest_rules_enabled', '1');
        AdminSetting::setValue('showcase_interest_allow_accept_when_both_showcase', '0');

        [$maleGid, $femaleGid] = $this->seedGenders();
        $sender = MatrimonyProfile::factory()->create(['gender_id' => $maleGid, 'is_showcase' => true]);
        $receiver = MatrimonyProfile::factory()->create(['gender_id' => $femaleGid, 'is_showcase' => true]);

        $interest = Interest::create([
            'sender_profile_id' => $sender->id,
            'receiver_profile_id' => $receiver->id,
            'status' => 'pending',
            'priority_score' => 1,
        ]);

        $svc = app(ShowcaseInterestPolicyService::class);
        $msg = $svc->validateAcceptInterest($receiver, $interest);
        $this->assertNotNull($msg);
    }

    public function test_withdraw_policy_blocks_showcase_sender_when_disabled(): void
    {
        AdminSetting::setValue('showcase_interest_rules_enabled', '1');
        AdminSetting::setValue('showcase_interest_allow_showcase_sender_withdraw', '0');

        [$maleGid, $femaleGid] = $this->seedGenders();
        $sender = MatrimonyProfile::factory()->create(['gender_id' => $maleGid, 'is_showcase' => true]);
        $receiver = MatrimonyProfile::factory()->create(['gender_id' => $femaleGid, 'is_showcase' => false]);

        $interest = Interest::create([
            'sender_profile_id' => $sender->id,
            'receiver_profile_id' => $receiver->id,
            'status' => 'pending',
            'priority_score' => 1,
        ]);

        $svc = app(ShowcaseInterestPolicyService::class);
        $msg = $svc->validateWithdrawInterest($sender, $interest);
        $this->assertNotNull($msg);
    }

    public function test_admin_showcase_interest_settings_page_requires_admin(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->get(route('admin.showcase-interest-settings.index'))->assertForbidden();
    }

    public function test_admin_showcase_interest_settings_page_ok_for_admin(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)
            ->get(route('admin.showcase-interest-settings.index'))
            ->assertOk()
            ->assertSee('Showcase — interest controls', false)
            ->assertSee('यादृच्छिक %', false);
    }

    public function test_real_sender_to_showcase_send_ignores_stochastic_even_with_zero_prob(): void
    {
        AdminSetting::setValue('showcase_interest_stochastic_gates_enabled', '1');
        AdminSetting::setValue('showcase_interest_prob_send_pct', '0');
        AdminSetting::setValue('showcase_interest_rules_enabled', '0');

        [$maleGid, $femaleGid] = $this->seedGenders();
        $real = MatrimonyProfile::factory()->create([
            'gender_id' => $maleGid,
            'is_showcase' => false,
            'lifecycle_state' => 'active',
        ]);
        $showcase = MatrimonyProfile::factory()->create([
            'gender_id' => $femaleGid,
            'is_showcase' => true,
            'lifecycle_state' => 'active',
        ]);

        $svc = app(ShowcaseInterestPolicyService::class);
        $r = $svc->evaluateSendInterest($real, $showcase);
        $this->assertTrue($r['ok'], 'Real → showcase send must not use stochastic gates on the sender');
    }

    public function test_match_weight_full_score_when_all_dimensions_match(): void
    {
        [$maleGid, $femaleGid] = $this->seedGenders();

        AdminSetting::setValue('showcase_interest_weight_age', '100');
        AdminSetting::setValue('showcase_interest_weight_religion', '0');
        AdminSetting::setValue('showcase_interest_weight_caste', '0');
        AdminSetting::setValue('showcase_interest_weight_district', '0');
        AdminSetting::setValue('showcase_interest_age_match_max_year_diff', '5');

        $a = MatrimonyProfile::factory()->create([
            'gender_id' => $maleGid,
            'date_of_birth' => now()->subYears(28),
            'is_showcase' => false,
        ]);
        $b = MatrimonyProfile::factory()->create([
            'gender_id' => $femaleGid,
            'date_of_birth' => now()->subYears(26),
            'is_showcase' => true,
        ]);

        $svc = app(ShowcaseInterestPolicyService::class);
        $bd = $svc->matchWeightBreakdown($a, $b);
        $this->assertEquals(100.0, $bd['score']);
        $this->assertEquals(1.0, $bd['ratio']);
    }
}

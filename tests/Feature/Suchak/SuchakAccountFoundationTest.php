<?php

namespace Tests\Feature\Suchak;

use App\Models\SuchakAccount;
use App\Models\SuchakPolicy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SuchakAccountFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_suchak_foundation_tables_exist_with_day_2_columns(): void
    {
        $this->assertTrue(Schema::hasTable('suchak_accounts'));
        $this->assertTrue(Schema::hasTable('suchak_verification_records'));
        $this->assertTrue(Schema::hasTable('suchak_policies'));

        foreach ([
            'user_id',
            'suchak_name',
            'office_name',
            'business_type',
            'mobile_number',
            'whatsapp_number',
            'email',
            'address_line',
            'city_id',
            'taluka_id',
            'district_id',
            'state_id',
            'verification_status',
            'public_status',
            'verified_at',
            'rejected_at',
            'suspended_at',
            'archived_at',
            'suspension_reason',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_accounts', $column), $column);
        }

        foreach ([
            'suchak_account_id',
            'verification_type',
            'document_path',
            'admin_status',
            'admin_user_id',
            'remarks',
            'verified_at',
            'rejected_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_verification_records', $column), $column);
        }

        foreach ([
            'policy_key',
            'policy_value',
            'value_type',
            'description',
            'is_active',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_policies', $column), $column);
        }

        $this->assertFalse(Schema::hasColumn('suchak_accounts', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('suchak_verification_records', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('suchak_policies', 'deleted_at'));
    }

    public function test_default_suchak_policy_rows_exist(): void
    {
        foreach ([
            'default_consent_validity_months' => [SuchakPolicy::TYPE_INTEGER, '12'],
            'allow_two_year_consent' => [SuchakPolicy::TYPE_BOOLEAN, 'true'],
            'allow_until_revoked_consent' => [SuchakPolicy::TYPE_BOOLEAN, 'true'],
            'request_action_sla_hours' => [SuchakPolicy::TYPE_INTEGER, '48'],
            'collaboration_sla_days' => [SuchakPolicy::TYPE_INTEGER, '7'],
            'pdf_download_limit_per_day' => [SuchakPolicy::TYPE_INTEGER, '20'],
            'qr_token_expiry_days' => [SuchakPolicy::TYPE_INTEGER, '30'],
            'suchak_upload_daily_limit' => [SuchakPolicy::TYPE_INTEGER, '25'],
            'suchak_active_profile_limit_by_plan' => [SuchakPolicy::TYPE_INTEGER, '0'],
            'suchak_free_trial_days' => [SuchakPolicy::TYPE_INTEGER, '0'],
            'suchak_grace_period_days' => [SuchakPolicy::TYPE_INTEGER, '0'],
            'suchak_plan_pricing_mode' => [SuchakPolicy::TYPE_STRING, 'manual_catalog'],
            'suchak_payment_mode' => [SuchakPolicy::TYPE_STRING, 'manual_only'],
            'suchak_commission_rules_json' => [SuchakPolicy::TYPE_JSON, '{"mode":"to_be_discussed","default_percent":0,"default_amount":0,"require_ack":true}'],
            'suchak_package_publish_approval_mode' => [SuchakPolicy::TYPE_STRING, 'admin_review'],
            'suchak_terms_policy_mode' => [SuchakPolicy::TYPE_STRING, 'strict'],
        ] as $key => [$type, $value]) {
            $this->assertDatabaseHas('suchak_policies', [
                'policy_key' => $key,
                'policy_value' => $value,
                'value_type' => $type,
                'is_active' => true,
            ]);
        }
    }

    public function test_user_has_one_suchak_account_relation(): void
    {
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($user->fresh()->suchakAccount->is($account));
        $this->assertTrue($account->user->is($user));
    }

    public function test_public_visibility_requires_verified_and_active_statuses(): void
    {
        $visible = SuchakAccount::factory()->make([
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
        ]);

        $pending = SuchakAccount::factory()->make([
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
        ]);

        $hidden = SuchakAccount::factory()->make([
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
        ]);

        $this->assertTrue($visible->isPubliclyVisible());
        $this->assertFalse($pending->isPubliclyVisible());
        $this->assertFalse($hidden->isPubliclyVisible());
    }

    public function test_suchak_account_is_not_tied_to_matrimony_profile(): void
    {
        $this->assertFalse(Schema::hasColumn('suchak_accounts', 'matrimony_profile_id'));
        $this->assertFalse(Schema::hasColumn('matrimony_profiles', 'suchak_account_id'));
    }
}

<?php

namespace Tests\Feature\Suchak;

use App\Models\AdminAuditLog;
use App\Models\Plan;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakPolicy;
use App\Models\SuchakServicePackage;
use App\Models\SuchakPlan;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakPackageCatalogService;
use App\Modules\Suchak\Services\SuchakPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SuchakPackageRateCardFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_package_rate_card_tables_are_structured_and_separate_from_platform_plan(): void
    {
        foreach ([
            'suchak_service_packages',
            'suchak_service_package_stages',
            'suchak_service_package_deliverables',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        foreach ([
            'suchak_package_templates',
            'suchak_package_template_stages',
            'suchak_package_template_deliverables',
        ] as $droppedTable) {
            $this->assertFalse(Schema::hasTable($droppedTable), $droppedTable);
        }

        foreach ([
            'stage_key',
            'stage_name',
            'stage_description',
            'sort_order',
            'is_required',
            'expected_days',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_service_package_stages', $column), $column);
        }

        foreach ([
            'deliverable_key',
            'deliverable_name',
            'deliverable_description',
            'sort_order',
            'is_required',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_service_package_deliverables', $column), $column);
        }

        foreach (['stages_json', 'deliverables_json', 'package_json', 'rate_card_json', 'suchak_plan_id', 'source_template_id'] as $forbiddenColumn) {
            $this->assertFalse(Schema::hasColumn('suchak_service_packages', $forbiddenColumn));
        }

        $this->assertFalse(Schema::hasColumn('suchak_service_package_stages', 'template_stage_id'));
        $this->assertFalse(Schema::hasColumn('suchak_service_package_deliverables', 'template_deliverable_id'));

        $this->assertDatabaseHas('suchak_policies', [
            'policy_key' => SuchakPolicyService::KEY_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE,
            'policy_value' => SuchakPolicyService::DEFAULT_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE,
            'value_type' => SuchakPolicy::TYPE_STRING,
            'is_active' => true,
        ]);
    }

    public function test_custom_package_creation_blocks_misleading_claims(): void
    {
        [$suchakUser, $account] = $this->verifiedSuchakActor();

        try {
            app(SuchakPackageCatalogService::class)->createCustomPackage(
                $account,
                $suchakUser,
                [
                    'package_name' => '100% guaranteed marriage package',
                    'price_amount' => '5000',
                    'currency' => 'INR',
                ],
                $this->stagePayload(),
                $this->deliverablePayload(),
            );

            $this->fail('Misleading package claims should be blocked.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Suchak packages must not contain misleading success or guarantee claims.', $exception->getMessage());
        }

        $this->assertSame(0, SuchakPlan::query()->count());
        $this->assertSame(0, Plan::query()->count());
    }

    public function test_custom_package_flow_uses_policy_driven_publish_mode_and_owner_status_guards(): void
    {
        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE],
            [
                'policy_value' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
                'value_type' => SuchakPolicy::TYPE_STRING,
                'description' => 'Test auto publish package policy.',
                'is_active' => true,
            ],
        );

        [$suchakUser, $account] = $this->verifiedSuchakActor();

        $package = app(SuchakPackageCatalogService::class)->createCustomPackage(
            $account,
            $suchakUser,
            [
                'package_name' => 'Custom Family Coordination',
                'package_description' => 'Structured family meeting and document coordination.',
                'price_amount' => '8500',
                'currency' => 'INR',
            ],
            $this->stagePayload(),
            $this->deliverablePayload(),
        );

        $this->assertSame(SuchakServicePackage::STATUS_PUBLISHED, $package->package_status);
        $this->assertSame(SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH, $package->approval_policy_mode);
        $this->assertFalse($package->requires_admin_approval);
        $this->assertNotNull($package->published_at);

        $pendingUser = User::factory()->create();
        $pendingAccount = SuchakAccount::factory()->create([
            'user_id' => $pendingUser->id,
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
        ]);

        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_ALLOW_WORK_BEFORE_ADMIN_APPROVAL],
            [
                'policy_value' => 'false',
                'value_type' => SuchakPolicy::TYPE_BOOLEAN,
                'description' => 'Block pending Suchak package management in this test.',
                'is_active' => true,
            ],
        );

        try {
            app(SuchakPackageCatalogService::class)->createCustomPackage(
                $pendingAccount,
                $pendingUser,
                ['package_name' => 'Pending Suchak Package'],
                $this->stagePayload(),
                $this->deliverablePayload(),
            );

            $this->fail('Unverified Suchak account should not create packages.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Only verified Suchak accounts can manage Suchak packages.', $exception->getMessage());
        }

        try {
            app(SuchakPackageCatalogService::class)->createCustomPackage(
                $account,
                User::factory()->create(),
                ['package_name' => 'Wrong Owner Package'],
                $this->stagePayload(),
                $this->deliverablePayload(),
            );

            $this->fail('Non-owner user should not create Suchak packages.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Only the owning Suchak account can manage Suchak packages.', $exception->getMessage());
        }
    }

    public function test_admin_can_approve_pending_package_with_audit(): void
    {
        [$suchakUser, $account] = $this->verifiedSuchakActor();
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => 'super_admin']);

        $package = app(SuchakPackageCatalogService::class)->createCustomPackage(
            $account,
            $suchakUser,
            ['package_name' => 'Review Required Coordination'],
            $this->stagePayload(),
            $this->deliverablePayload(),
        );

        $this->assertSame(SuchakServicePackage::STATUS_PENDING_REVIEW, $package->package_status);

        $approved = app(SuchakPackageCatalogService::class)->approvePackage(
            $package,
            $admin,
            'Approve Day-35 custom package after review.',
            '127.0.0.1',
            'Day-35 approve test',
        );

        $this->assertSame(SuchakServicePackage::STATUS_PUBLISHED, $approved->package_status);
        $this->assertFalse($approved->requires_admin_approval);
        $this->assertSame($admin->id, $approved->approved_by_admin_user_id);
        $this->assertNotNull($approved->approved_at);
        $this->assertNotNull($approved->published_at);
        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_id' => $admin->id,
            'action_type' => 'suchak_service_package_approved',
            'entity_type' => 'SuchakServicePackage',
            'entity_id' => $package->id,
        ]);

        $audit = AdminAuditLog::query()
            ->where('action_type', 'suchak_service_package_approved')
            ->where('entity_id', $package->id)
            ->firstOrFail();

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $admin->id,
            'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
            'action_type' => SuchakActivityLog::ACTION_SERVICE_PACKAGE_APPROVED,
            'target_type' => 'suchak_service_package',
            'target_id' => $package->id,
            'admin_audit_log_id' => $audit->id,
        ]);
    }

    public function test_service_package_delete_is_blocked(): void
    {
        [$suchakUser, $account] = $this->verifiedSuchakActor();
        $package = app(SuchakPackageCatalogService::class)->createCustomPackage(
            $account,
            $suchakUser,
            ['package_name' => 'Delete Guard Package'],
            $this->stagePayload(),
            $this->deliverablePayload(),
        );

        try {
            $package->delete();
            $this->fail('Service packages should not be deleted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak service packages cannot be deleted.', $exception->getMessage());
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function stagePayload(): array
    {
        return [
            [
                'stage_key' => 'intake_and_shortlist',
                'stage_name' => 'Intake and shortlist',
                'stage_description' => 'Collect requirements and prepare structured shortlist.',
                'sort_order' => 10,
                'expected_days' => 7,
            ],
            [
                'stage_key' => 'family_coordination',
                'stage_name' => 'Family coordination',
                'stage_description' => 'Coordinate family discussion and next steps.',
                'sort_order' => 20,
                'expected_days' => 14,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function deliverablePayload(): array
    {
        return [
            [
                'stage_key' => 'intake_and_shortlist',
                'deliverable_key' => 'shortlist_report',
                'deliverable_name' => 'Shortlist report',
                'deliverable_description' => 'Candidate shortlist summary with non-public contact protected.',
                'sort_order' => 10,
            ],
            [
                'stage_key' => 'family_coordination',
                'deliverable_key' => 'meeting_followup',
                'deliverable_name' => 'Meeting follow-up',
                'deliverable_description' => 'Follow-up notes after family discussion.',
                'sort_order' => 20,
            ],
        ];
    }

    /**
     * @return array{0: User, 1: SuchakAccount}
     */
    private function verifiedSuchakActor(): array
    {
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);

        return [$user, $account];
    }
}

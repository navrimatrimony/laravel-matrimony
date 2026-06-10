<?php

namespace Tests\Feature\Suchak;

use App\Models\AdminAuditLog;
use App\Models\Plan;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakPackageTemplate;
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
            'suchak_package_templates',
            'suchak_package_template_stages',
            'suchak_package_template_deliverables',
            'suchak_service_packages',
            'suchak_service_package_stages',
            'suchak_service_package_deliverables',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        foreach ([
            'template_name',
            'base_price_amount',
            'currency',
            'template_status',
            'created_by_admin_user_id',
            'approved_by_admin_user_id',
            'approved_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_package_templates', $column), $column);
        }

        foreach ([
            'stage_key',
            'stage_name',
            'stage_description',
            'sort_order',
            'is_required',
            'expected_days',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_package_template_stages', $column), $column);
            $this->assertTrue(Schema::hasColumn('suchak_service_package_stages', $column), $column);
        }

        foreach ([
            'deliverable_key',
            'deliverable_name',
            'deliverable_description',
            'sort_order',
            'is_required',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_package_template_deliverables', $column), $column);
            $this->assertTrue(Schema::hasColumn('suchak_service_package_deliverables', $column), $column);
        }

        foreach (['stages_json', 'deliverables_json', 'package_json', 'rate_card_json', 'suchak_plan_id'] as $forbiddenColumn) {
            $this->assertFalse(Schema::hasColumn('suchak_package_templates', $forbiddenColumn));
            $this->assertFalse(Schema::hasColumn('suchak_service_packages', $forbiddenColumn));
        }

        $this->assertDatabaseHas('suchak_policies', [
            'policy_key' => SuchakPolicyService::KEY_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE,
            'policy_value' => SuchakPolicyService::DEFAULT_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE,
            'value_type' => SuchakPolicy::TYPE_STRING,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_create_package_template_with_structured_stages_deliverables_and_claim_guard(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $template = $this->createApprovedTemplate($admin);

        $this->assertSame(SuchakPackageTemplate::STATUS_APPROVED, $template->template_status);
        $this->assertSame('15000.00', $template->base_price_amount);
        $this->assertSame('INR', $template->currency);
        $this->assertCount(2, $template->stages);
        $this->assertCount(2, $template->deliverables);
        $this->assertSame('intake_and_shortlist', $template->stages->first()->stage_key);

        $this->assertDatabaseHas('suchak_package_template_deliverables', [
            'package_template_id' => $template->id,
            'template_stage_id' => $template->stages->first()->id,
            'deliverable_key' => 'shortlist_report',
        ]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_id' => $admin->id,
            'action_type' => 'suchak_package_template_created',
            'entity_type' => 'SuchakPackageTemplate',
            'entity_id' => $template->id,
        ]);
        $this->assertDatabaseHas('suchak_activity_logs', [
            'actor_user_id' => $admin->id,
            'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
            'action_type' => SuchakActivityLog::ACTION_PACKAGE_TEMPLATE_CREATED,
            'target_type' => 'suchak_package_template',
            'target_id' => $template->id,
        ]);
        $this->assertSame(0, SuchakPlan::query()->count());
        $this->assertSame(0, Plan::query()->count());

        try {
            app(SuchakPackageCatalogService::class)->createTemplate(
                $admin,
                [
                    'template_name' => '100% guaranteed marriage package',
                    'base_price_amount' => '5000',
                    'currency' => 'INR',
                ],
                $this->stagePayload(),
                $this->deliverablePayload(),
                'Reject misleading package claim.',
            );

            $this->fail('Misleading package claims should be blocked.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Suchak packages must not contain misleading success or guarantee claims.', $exception->getMessage());
        }
    }

    public function test_verified_suchak_can_clone_template_without_touching_suchak_platform_plan(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $template = $this->createApprovedTemplate($admin);
        [$suchakUser, $account] = $this->verifiedSuchakActor();

        $package = app(SuchakPackageCatalogService::class)->cloneTemplateForSuchak(
            $account,
            $suchakUser,
            $template,
            [
                'package_name' => 'Premium Match Facilitation',
                'package_description' => 'Personalized shortlist and family coordination.',
                'price_amount' => '12000',
                'currency' => 'INR',
            ],
            null,
            '127.0.0.1',
            'Day-35 clone test',
        );

        $this->assertSame($account->id, $package->suchak_account_id);
        $this->assertSame($template->id, $package->source_template_id);
        $this->assertSame(SuchakServicePackage::STATUS_PENDING_REVIEW, $package->package_status);
        $this->assertSame(SuchakServicePackage::APPROVAL_MODE_ADMIN_REVIEW, $package->approval_policy_mode);
        $this->assertTrue($package->requires_admin_approval);
        $this->assertSame('12000.00', $package->price_amount);
        $this->assertCount(2, $package->stages);
        $this->assertCount(2, $package->deliverables);
        $this->assertSame($template->stages->first()->id, $package->stages->first()->template_stage_id);
        $this->assertSame($package->stages->first()->id, $package->deliverables->first()->service_package_stage_id);
        $this->assertSame(0, SuchakPlan::query()->count());

        $activity = SuchakActivityLog::query()
            ->where('action_type', SuchakActivityLog::ACTION_SERVICE_PACKAGE_CREATED)
            ->where('target_type', 'suchak_service_package')
            ->where('target_id', $package->id)
            ->firstOrFail();

        $this->assertSame('template_clone', $activity->metadata_json['context']);
        $this->assertSame(SuchakServicePackage::APPROVAL_MODE_ADMIN_REVIEW, $activity->metadata_json['approval_policy_mode']);
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

        $this->assertNull($package->source_template_id);
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
        $admin = User::factory()->create(['is_admin' => true]);

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

    public function test_package_template_and_service_package_delete_are_blocked(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $template = $this->createApprovedTemplate($admin);
        [$suchakUser, $account] = $this->verifiedSuchakActor();
        $package = app(SuchakPackageCatalogService::class)->cloneTemplateForSuchak($account, $suchakUser, $template);

        try {
            $template->delete();
            $this->fail('Package templates should not be deleted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak package templates cannot be deleted.', $exception->getMessage());
        }

        try {
            $package->delete();
            $this->fail('Service packages should not be deleted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak service packages cannot be deleted.', $exception->getMessage());
        }
    }

    private function createApprovedTemplate(User $admin): SuchakPackageTemplate
    {
        return app(SuchakPackageCatalogService::class)->createTemplate(
            $admin,
            [
                'template_name' => 'Premium Match Coordination',
                'template_description' => 'Structured shortlist, family meeting, and follow-up coordination.',
                'base_price_amount' => '15000',
                'currency' => 'INR',
            ],
            $this->stagePayload(),
            $this->deliverablePayload(),
            'Create Day-35 package template.',
        );
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

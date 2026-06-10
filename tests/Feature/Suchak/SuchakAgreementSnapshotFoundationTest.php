<?php

namespace Tests\Feature\Suchak;

use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakPackageTemplate;
use App\Models\SuchakPolicy;
use App\Models\SuchakServicePackage;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAgreementService;
use App\Modules\Suchak\Services\SuchakPackageCatalogService;
use App\Modules\Suchak\Services\SuchakPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SuchakAgreementSnapshotFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_agreement_tables_exist_with_day_36_columns(): void
    {
        $this->assertTrue(Schema::hasTable('suchak_customer_agreements'));
        $this->assertTrue(Schema::hasTable('suchak_customer_agreement_stages'));
        $this->assertTrue(Schema::hasTable('suchak_customer_agreement_deliverables'));

        foreach ([
            'suchak_account_id',
            'customer_context_id',
            'service_package_id',
            'supersedes_agreement_id',
            'agreement_revision',
            'terms_status',
            'terms_policy_mode',
            'agreement_snapshot_hash',
            'package_name',
            'price_amount',
            'currency',
            'agreement_title',
            'agreement_body',
            'invoice_note',
            'created_by_user_id',
            'accepted_by_user_id',
            'accepted_at',
            'bypassed_by_user_id',
            'bypassed_at',
            'bypass_reason',
            'superseded_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_customer_agreements', $column), $column);
        }

        foreach (['stage_key', 'stage_name', 'sort_order', 'is_required', 'expected_days'] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_customer_agreement_stages', $column), $column);
        }

        foreach (['deliverable_key', 'deliverable_name', 'sort_order', 'is_required'] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_customer_agreement_deliverables', $column), $column);
        }

        $this->assertFalse(Schema::hasColumn('suchak_customer_agreements', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('suchak_customer_agreements', 'payment_status'));
        $this->assertFalse(Schema::hasColumn('suchak_customer_agreements', 'refund_status'));
        $this->assertFalse(Schema::hasColumn('suchak_customer_agreements', 'dispute_status'));

        $this->assertDatabaseHas('suchak_policies', [
            'policy_key' => SuchakPolicyService::KEY_SUCHAK_TERMS_POLICY_MODE,
            'policy_value' => SuchakPolicyService::DEFAULT_SUCHAK_TERMS_POLICY_MODE,
            'value_type' => SuchakPolicy::TYPE_STRING,
            'is_active' => true,
        ]);
    }

    public function test_suchak_can_create_structured_customer_agreement_snapshot_for_published_package(): void
    {
        [$suchakUser, , $package] = $this->publishedPackageFixture();

        $agreement = app(SuchakAgreementService::class)->createAgreementForPackage(
            $package,
            $suchakUser,
            [
                'agreement_title' => 'Day-36 customer agreement',
                'agreement_body' => 'Customer confirms the package stages and deliverables.',
            ],
            '127.0.0.1',
            'Day-36 create test',
        );

        $this->assertSame($package->suchak_account_id, $agreement->suchak_account_id);
        $this->assertSame($package->id, $agreement->service_package_id);
        $this->assertSame(1, $agreement->agreement_revision);
        $this->assertNull($agreement->supersedes_agreement_id);
        $this->assertSame(SuchakCustomerAgreement::TERMS_PENDING, $agreement->terms_status);
        $this->assertSame(SuchakCustomerAgreement::POLICY_STRICT, $agreement->terms_policy_mode);
        $this->assertSame($package->package_name, $agreement->package_name);
        $this->assertSame('15000.00', $agreement->price_amount);
        $this->assertCount(2, $agreement->stages);
        $this->assertCount(2, $agreement->deliverables);
        $this->assertSame($package->stages->first()->id, $agreement->stages->first()->service_package_stage_id);
        $this->assertStringContainsString('Terms pending', $agreement->invoice_note);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $package->suchak_account_id,
            'actor_user_id' => $suchakUser->id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => SuchakActivityLog::ACTION_CUSTOMER_AGREEMENT_CREATED,
            'target_type' => 'suchak_customer_agreement',
            'target_id' => $agreement->id,
        ]);
    }

    public function test_accepting_terms_finalizes_agreement_and_blocks_later_mutation(): void
    {
        [$suchakUser, , $package] = $this->publishedPackageFixture();
        $agreement = app(SuchakAgreementService::class)->createAgreementForPackage($package, $suchakUser);

        $accepted = app(SuchakAgreementService::class)->acceptTerms(
            $agreement,
            $suchakUser,
            '127.0.0.1',
            'Day-36 accept test',
        );

        $this->assertSame(SuchakCustomerAgreement::TERMS_ACCEPTED, $accepted->terms_status);
        $this->assertSame($suchakUser->id, $accepted->accepted_by_user_id);
        $this->assertNotNull($accepted->accepted_at);
        $this->assertStringContainsString('Terms accepted', $accepted->invoice_note);
        $this->assertTrue($accepted->isTermsSatisfied());

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $package->suchak_account_id,
            'actor_user_id' => $suchakUser->id,
            'action_type' => SuchakActivityLog::ACTION_CUSTOMER_AGREEMENT_TERMS_ACCEPTED,
            'target_type' => 'suchak_customer_agreement',
            'target_id' => $accepted->id,
        ]);

        try {
            $accepted->update(['invoice_note' => 'silent change']);
            $this->fail('Accepted agreement should be immutable.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Suchak customer agreements are immutable after acceptance, bypass, or not-required finalization.',
                $exception->getMessage(),
            );
        }

        try {
            $accepted->delete();
            $this->fail('Agreement delete should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak customer agreements cannot be deleted.', $exception->getMessage());
        }
    }

    public function test_package_change_requires_new_agreement_revision_before_acceptance(): void
    {
        [$suchakUser, , $package] = $this->publishedPackageFixture();
        $agreement = app(SuchakAgreementService::class)->createAgreementForPackage($package, $suchakUser);
        $oldHash = $agreement->agreement_snapshot_hash;

        $package->forceFill([
            'package_name' => 'Changed Family Coordination',
            'price_amount' => '17500.00',
        ])->save();

        try {
            app(SuchakAgreementService::class)->acceptTerms($agreement, $suchakUser);
            $this->fail('Changed package should require a new agreement revision.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Suchak package changed. Create a new agreement revision before accepting terms.', $exception->getMessage());
        }

        $revision = app(SuchakAgreementService::class)->createRevisionForPackageChange(
            $agreement,
            $suchakUser,
            ['revision_reason' => 'Package price changed before customer acceptance.'],
        );

        $this->assertSame(SuchakCustomerAgreement::TERMS_SUPERSEDED, $agreement->fresh()->terms_status);
        $this->assertSame(2, $revision->agreement_revision);
        $this->assertSame($agreement->id, $revision->supersedes_agreement_id);
        $this->assertSame('Changed Family Coordination', $revision->package_name);
        $this->assertSame('17500.00', $revision->price_amount);
        $this->assertNotSame($oldHash, $revision->agreement_snapshot_hash);
        $this->assertSame(SuchakCustomerAgreement::TERMS_PENDING, $revision->terms_status);

        $accepted = app(SuchakAgreementService::class)->acceptTerms($revision, $suchakUser);
        $this->assertSame(SuchakCustomerAgreement::TERMS_ACCEPTED, $accepted->terms_status);
    }

    public function test_terms_bypass_requires_reason_actor_and_invoice_note(): void
    {
        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_TERMS_POLICY_MODE],
            [
                'policy_value' => SuchakCustomerAgreement::POLICY_RECOMMENDED,
                'value_type' => SuchakPolicy::TYPE_STRING,
                'description' => 'Recommended terms policy for Day-36 test.',
                'is_active' => true,
            ],
        );

        [$suchakUser, , $package] = $this->publishedPackageFixture();
        $agreement = app(SuchakAgreementService::class)->createAgreementForPackage($package, $suchakUser);

        try {
            app(SuchakAgreementService::class)->bypassTerms($agreement, $suchakUser, '');
            $this->fail('Bypass reason should be mandatory.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Suchak agreement terms bypass reason is required.', $exception->getMessage());
        }

        $bypassed = app(SuchakAgreementService::class)->bypassTerms(
            $agreement,
            $suchakUser,
            'Customer accepted terms offline and signed paper copy.',
            '127.0.0.1',
            'Day-36 bypass test',
        );

        $this->assertSame(SuchakCustomerAgreement::TERMS_BYPASSED, $bypassed->terms_status);
        $this->assertSame($suchakUser->id, $bypassed->bypassed_by_user_id);
        $this->assertNotNull($bypassed->bypassed_at);
        $this->assertSame('Customer accepted terms offline and signed paper copy.', $bypassed->bypass_reason);
        $this->assertStringContainsString('Terms bypassed', $bypassed->invoice_note);
        $this->assertTrue($bypassed->isTermsSatisfied());

        $activity = SuchakActivityLog::query()
            ->where('action_type', SuchakActivityLog::ACTION_CUSTOMER_AGREEMENT_TERMS_BYPASSED)
            ->where('target_id', $bypassed->id)
            ->firstOrFail();

        $this->assertTrue($activity->metadata_json['has_bypass_reason']);
        $this->assertTrue($activity->metadata_json['has_invoice_note']);
    }

    public function test_strict_policy_blocks_non_admin_bypass_and_optional_policy_marks_terms_not_required(): void
    {
        [$suchakUser, , $package] = $this->publishedPackageFixture();
        $agreement = app(SuchakAgreementService::class)->createAgreementForPackage($package, $suchakUser);

        try {
            app(SuchakAgreementService::class)->bypassTerms($agreement, $suchakUser, 'Offline exception.');
            $this->fail('Strict policy should require admin bypass.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Strict Suchak terms policy requires admin bypass.', $exception->getMessage());
        }

        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_TERMS_POLICY_MODE],
            [
                'policy_value' => SuchakCustomerAgreement::POLICY_OPTIONAL,
                'value_type' => SuchakPolicy::TYPE_STRING,
                'description' => 'Optional terms policy for Day-36 test.',
                'is_active' => true,
            ],
        );

        [, , $optionalPackage] = $this->publishedPackageFixture('Optional Package');
        $optional = app(SuchakAgreementService::class)->createAgreementForPackage($optionalPackage, $optionalPackage->suchakAccount->user);

        $this->assertSame(SuchakCustomerAgreement::TERMS_NOT_REQUIRED, $optional->terms_status);
        $this->assertSame(SuchakCustomerAgreement::POLICY_OPTIONAL, $optional->terms_policy_mode);
        $this->assertTrue($optional->isTermsSatisfied());
        $this->assertStringContainsString('Terms not required', $optional->invoice_note);
    }

    /**
     * @return array{0: User, 1: SuchakAccount, 2: SuchakServicePackage}
     */
    private function publishedPackageFixture(string $packageName = 'Premium Match Coordination'): array
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [$suchakUser, $account] = $this->verifiedSuchakActor();

        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE],
            [
                'policy_value' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
                'value_type' => SuchakPolicy::TYPE_STRING,
                'description' => 'Auto publish packages for agreement fixture.',
                'is_active' => true,
            ],
        );

        $template = app(SuchakPackageCatalogService::class)->createTemplate(
            $admin,
            [
                'template_name' => 'Agreement Fixture Template',
                'base_price_amount' => '15000',
                'currency' => 'INR',
            ],
            $this->stagePayload(),
            $this->deliverablePayload(),
            'Create agreement fixture template.',
        );

        $package = app(SuchakPackageCatalogService::class)->cloneTemplateForSuchak(
            $account,
            $suchakUser,
            $template,
            [
                'package_name' => $packageName,
                'price_amount' => '15000',
                'currency' => 'INR',
            ],
        );

        return [$suchakUser, $account, $package->fresh(['suchakAccount.user', 'stages', 'deliverables.servicePackageStage'])];
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
                'deliverable_description' => 'Candidate shortlist summary.',
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

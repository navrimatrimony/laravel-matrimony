<?php

namespace Tests\Feature\Suchak;

use App\Models\SuchakAccount;
use App\Models\SuchakMessageTemplate;
use App\Models\SuchakMessageTemplateUsage;
use App\Models\SuchakTrainingCertificate;
use App\Models\SuchakTrainingCompletion;
use App\Models\SuchakTrainingModule;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakTrainingAcademyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SuchakTrainingAcademyTemplateLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_day_56_training_schema_admin_center_and_suchak_template_library_are_policy_safe(): void
    {
        foreach ([
            'suchak_training_modules',
            'suchak_training_completions',
            'suchak_training_certificates',
            'suchak_message_templates',
            'suchak_message_template_usages',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        foreach ([
            'module_key',
            'module_category',
            'is_required_for_certificate',
            'content_outline',
            'admin_audit_log_id',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_training_modules', $column), $column);
        }

        foreach ([
            'certificate_code',
            'certificate_scope',
            'public_badge_status',
            'required_module_ids_json',
            'issued_admin_audit_log_id',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_training_certificates', $column), $column);
        }

        foreach ([
            'template_key',
            'template_category',
            'template_channel',
            'policy_status',
            'body_text',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_message_templates', $column), $column);
        }

        foreach (['phone', 'mobile', 'whatsapp', 'email', 'upi', 'deleted_at'] as $forbiddenColumn) {
            $this->assertFalse(Schema::hasColumn('suchak_message_templates', $forbiddenColumn), $forbiddenColumn);
            $this->assertFalse(Schema::hasColumn('suchak_message_template_usages', $forbiddenColumn), $forbiddenColumn);
        }

        $admin = User::factory()->create(['is_admin' => true]);
        [$suchakUser, $account] = $this->verifiedSuchakActor([
            'suchak_name' => 'Day56 Academy Suchak',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.suchak.academy.modules.store'), [
                'module_key' => 'privacy_basics_v1',
                'module_title' => 'Privacy Basics',
                'module_category' => SuchakTrainingModule::CATEGORY_PRIVACY,
                'summary' => 'Use masked platform records and protect candidate information.',
                'content_outline' => 'Explain consent, masked biodata sharing, and private data handling.',
                'is_required_for_certificate' => '1',
                'sort_order' => 10,
            ])
            ->assertRedirect(route('admin.suchak.academy.index'));

        $this->actingAs($admin)
            ->post(route('admin.suchak.academy.message-templates.store'), [
                'template_key' => 'consent_followup_v1',
                'template_title' => 'Consent Follow-up',
                'template_category' => SuchakMessageTemplate::CATEGORY_CONSENT,
                'template_channel' => SuchakMessageTemplate::CHANNEL_WHATSAPP_COPY,
                'body_text' => 'Please review the platform consent request for {candidate_reference}. Use only the platform link.',
                'usage_guidance' => 'Use when consent action is pending.',
            ])
            ->assertRedirect(route('admin.suchak.academy.index'));

        $module = SuchakTrainingModule::query()->firstOrFail();
        $template = SuchakMessageTemplate::query()->firstOrFail();
        $this->assertNotNull($module->admin_audit_log_id);
        $this->assertNotNull($template->admin_audit_log_id);
        $this->assertSame(SuchakMessageTemplate::POLICY_SAFE, $template->policy_status);

        $this->actingAs($admin)
            ->get(route('admin.suchak.academy.index'))
            ->assertOk()
            ->assertSee('Suchak Training Academy', false)
            ->assertSee('Internal Certificate Issue', false)
            ->assertSee('Consent Follow-up', false);

        $this->actingAs($suchakUser)
            ->get(route('suchak.training-academy.index'))
            ->assertOk()
            ->assertSee('Training Academy', false)
            ->assertSee('Message Template Library', false)
            ->assertSee('Consent Follow-up', false)
            ->assertDontSee('Quality Score', false);

        $this->actingAs($suchakUser)
            ->post(route('suchak.training-academy.message-templates.use', $template), [
                'usage_context' => SuchakMessageTemplateUsage::CONTEXT_CONSENT,
                'rendered_body' => 'Please review the platform consent request for masked-candidate. Use only the platform link.',
            ])
            ->assertRedirect(route('suchak.training-academy.index'));

        $usage = SuchakMessageTemplateUsage::query()->firstOrFail();
        $this->assertSame($account->id, $usage->suchak_account_id);
        $this->assertSame($suchakUser->id, $usage->used_by_user_id);
        $this->assertStringNotContainsString('9876543210', $usage->rendered_body);
        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'action_type' => 'message_template_reused',
            'target_type' => 'suchak_message_template_usage',
            'target_id' => $usage->id,
        ]);

        try {
            app(SuchakTrainingAcademyService::class)->createMessageTemplate($admin, [
                'template_key' => 'unsafe_payment_v1',
                'template_title' => 'Unsafe Payment Template',
                'template_category' => SuchakMessageTemplate::CATEGORY_PAYMENT,
                'template_channel' => SuchakMessageTemplate::CHANNEL_WHATSAPP_COPY,
                'body_text' => 'Pay directly on UPI secret@bank for guaranteed match service.',
                'usage_guidance' => 'Unsafe direct payment message.',
            ]);
            $this->fail('Unsafe Suchak message templates should be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('direct payment handles', $exception->getMessage());
        }
    }

    public function test_day_56_internal_certificate_requires_training_and_does_not_leak_public_badge(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [, $account] = $this->verifiedSuchakActor([
            'suchak_name' => 'Day56 Certified Suchak',
        ]);
        $service = app(SuchakTrainingAcademyService::class);

        $privacy = $service->createTrainingModule($admin, [
            'module_key' => 'privacy_required_v1',
            'module_title' => 'Privacy Required',
            'module_category' => SuchakTrainingModule::CATEGORY_PRIVACY,
            'summary' => 'Privacy training for masked profile handling.',
            'content_outline' => 'Use consent, masked records, and governed platform links.',
            'is_required_for_certificate' => true,
            'sort_order' => 1,
        ]);
        $payment = $service->createTrainingModule($admin, [
            'module_key' => 'payment_required_v1',
            'module_title' => 'Payment Required',
            'module_category' => SuchakTrainingModule::CATEGORY_PAYMENT,
            'summary' => 'Payment training for platform-safe collection boundaries.',
            'content_outline' => 'Use structured payment requests, receipts, and dispute-safe notes.',
            'is_required_for_certificate' => true,
            'sort_order' => 2,
        ]);
        $dispute = $service->createTrainingModule($admin, [
            'module_key' => 'dispute_required_v1',
            'module_title' => 'Dispute Required',
            'module_category' => SuchakTrainingModule::CATEGORY_DISPUTE,
            'summary' => 'Dispute training for evidence and safe resolution.',
            'content_outline' => 'Record evidence, avoid pressure claims, and keep actions auditable.',
            'is_required_for_certificate' => true,
            'sort_order' => 3,
        ]);

        try {
            $service->issueInternalCertificate($account, $admin, 'Certificate should wait for all required modules.');
            $this->fail('Certificate should require all required training modules.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('All required Suchak training modules must be completed before certificate issue.', $exception->getMessage());
        }

        foreach ([$privacy, $payment, $dispute] as $module) {
            $service->completeModule($account, $module, $admin, [
                'score_percent' => 90,
                'completion_note' => 'Admin verified Day-56 training completion evidence.',
            ]);
        }

        $this->actingAs($admin)
            ->post(route('admin.suchak.academy.accounts.certificates.issue', $account), [
                'certificate_note' => 'Internal certificate issued after privacy payment and dispute training.',
            ])
            ->assertRedirect(route('admin.suchak.academy.index'));

        $certificate = SuchakTrainingCertificate::query()->firstOrFail();
        $this->assertSame(SuchakTrainingCertificate::SCOPE_INTERNAL, $certificate->certificate_scope);
        $this->assertSame(SuchakTrainingCertificate::PUBLIC_BADGE_NOT_PUBLIC, $certificate->public_badge_status);
        $this->assertNotNull($certificate->issued_admin_audit_log_id);
        $this->assertSame(3, SuchakTrainingCompletion::query()->count());
        $this->assertDatabaseHas('admin_audit_logs', [
            'action_type' => 'suchak_training_certificate_issued',
            'entity_type' => 'SuchakTrainingCertificate',
            'entity_id' => $certificate->id,
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('suchak.marketplace.show', $account))
            ->assertOk()
            ->assertSee('Day56 Certified Suchak', false)
            ->assertDontSee($certificate->certificate_code, false)
            ->assertDontSee('Internal certificate', false)
            ->assertDontSee('Training Academy', false);

        try {
            $certificate->delete();
            $this->fail('Suchak internal certificates should not be deleted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak training certificates cannot be deleted.', $exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: User, 1: SuchakAccount}
     */
    private function verifiedSuchakActor(array $overrides = []): array
    {
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create(array_merge([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'rejected_at' => null,
            'suspended_at' => null,
            'archived_at' => null,
        ], $overrides));

        return [$user, $account];
    }
}

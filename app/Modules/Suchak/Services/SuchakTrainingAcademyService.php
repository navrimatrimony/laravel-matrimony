<?php

namespace App\Modules\Suchak\Services;

use App\Models\AdminAuditLog;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakMessageTemplate;
use App\Models\SuchakMessageTemplateUsage;
use App\Models\SuchakTrainingCertificate;
use App\Models\SuchakTrainingCompletion;
use App\Models\SuchakTrainingModule;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SuchakTrainingAcademyService
{
    public function __construct(
        private readonly SuchakAccessService $accessService,
        private readonly SuchakActivityLogger $activityLogger,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function adminSummary(int $limit = 20): array
    {
        return [
            'modules' => SuchakTrainingModule::query()
                ->withCount('completions')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
            'templates' => SuchakMessageTemplate::query()
                ->withCount('usages')
                ->latest()
                ->limit($limit)
                ->get(),
            'recent_completions' => SuchakTrainingCompletion::query()
                ->with(['suchakAccount.user', 'trainingModule', 'completedByAdmin'])
                ->latest('completed_at')
                ->limit($limit)
                ->get(),
            'recent_certificates' => SuchakTrainingCertificate::query()
                ->with(['suchakAccount.user', 'issuedByAdmin'])
                ->latest('issued_at')
                ->limit($limit)
                ->get(),
            'recent_template_usages' => SuchakMessageTemplateUsage::query()
                ->with(['suchakAccount.user', 'messageTemplate', 'usedByUser'])
                ->latest('used_at')
                ->limit($limit)
                ->get(),
            'accounts' => SuchakAccount::query()
                ->with('user')
                ->withCount(['trainingCompletions', 'trainingCertificates', 'messageTemplateUsages'])
                ->latest()
                ->limit($limit)
                ->get(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function academyFor(SuchakAccount $account): array
    {
        $modules = SuchakTrainingModule::query()
            ->where('module_status', SuchakTrainingModule::STATUS_ACTIVE)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $completions = SuchakTrainingCompletion::query()
            ->with('trainingModule')
            ->where('suchak_account_id', $account->id)
            ->where('completion_status', SuchakTrainingCompletion::STATUS_COMPLETED)
            ->get()
            ->keyBy('training_module_id');
        $requiredModuleIds = $modules
            ->where('is_required_for_certificate', true)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values();
        $completedRequired = $requiredModuleIds
            ->filter(fn (int $id): bool => $completions->has($id))
            ->count();

        return [
            'modules' => $modules,
            'completions' => $completions,
            'required_module_count' => $requiredModuleIds->count(),
            'completed_required_count' => $completedRequired,
            'latest_certificate' => SuchakTrainingCertificate::query()
                ->where('suchak_account_id', $account->id)
                ->latest('issued_at')
                ->latest('id')
                ->first(),
            'templates' => SuchakMessageTemplate::query()
                ->where('template_status', SuchakMessageTemplate::STATUS_ACTIVE)
                ->where('policy_status', SuchakMessageTemplate::POLICY_SAFE)
                ->latest()
                ->get(),
            'recent_template_usages' => SuchakMessageTemplateUsage::query()
                ->with('messageTemplate')
                ->where('suchak_account_id', $account->id)
                ->latest('used_at')
                ->limit(10)
                ->get(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createTrainingModule(User $admin, array $attributes): SuchakTrainingModule
    {
        $this->accessService->assertAdmin($admin, 'Only admins can create Suchak training modules.');

        $moduleKey = $this->slugKey($attributes['module_key'] ?? null, 'Suchak training module key is required.');
        $title = $this->requiredText($attributes['module_title'] ?? null, 'Suchak training module title is required.', 160);
        $titleMr = $this->nullableText($attributes['module_title_mr'] ?? null, 160);
        $category = $this->allowed($attributes['module_category'] ?? null, SuchakTrainingModule::CATEGORIES, 'Suchak training module category is invalid.');
        $summary = $this->requiredText($attributes['summary'] ?? null, 'Suchak training module summary is required.', 1000);
        $summaryMr = $this->nullableText($attributes['summary_mr'] ?? null, 1000);
        $outline = $this->requiredText($attributes['content_outline'] ?? null, 'Suchak training module outline is required.', 4000);
        $outlineMr = $this->nullableText($attributes['content_outline_mr'] ?? null, 4000);

        $this->assertPolicySafeText($title.' '.$summary.' '.$outline);

        return DB::transaction(function () use ($admin, $attributes, $moduleKey, $title, $titleMr, $category, $summary, $summaryMr, $outline, $outlineMr): SuchakTrainingModule {
            $module = SuchakTrainingModule::query()->create([
                'module_key' => $moduleKey,
                'module_title' => $title,
                'module_title_mr' => $titleMr,
                'module_category' => $category,
                'module_status' => SuchakTrainingModule::STATUS_ACTIVE,
                'is_required_for_certificate' => (bool) ($attributes['is_required_for_certificate'] ?? true),
                'sort_order' => $this->sortOrder($attributes['sort_order'] ?? 0),
                'summary' => $summary,
                'summary_mr' => $summaryMr,
                'content_outline' => $outline,
                'content_outline_mr' => $outlineMr,
                'created_by_admin_user_id' => $admin->id,
            ]);

            $audit = $this->audit($admin, 'suchak_training_module_created', $module, $summary);
            $module->forceFill(['admin_audit_log_id' => $audit->id])->save();

            return $module->fresh(['createdByAdmin', 'adminAuditLog']);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function completeModule(
        SuchakAccount $account,
        SuchakTrainingModule $module,
        User $admin,
        array $attributes,
    ): SuchakTrainingCompletion {
        $this->accessService->assertAdmin($admin, 'Only admins can record Suchak training completion.');

        if ($module->module_status !== SuchakTrainingModule::STATUS_ACTIVE) {
            throw new InvalidArgumentException('Only active Suchak training modules can be completed.');
        }

        $note = $this->requiredText($attributes['completion_note'] ?? null, 'Suchak training completion note is required.', 1000);
        $score = $this->nullableScore($attributes['score_percent'] ?? null);
        $this->assertPolicySafeText($note);

        return DB::transaction(function () use ($account, $module, $admin, $note, $score): SuchakTrainingCompletion {
            $existing = SuchakTrainingCompletion::query()
                ->where('suchak_account_id', $account->id)
                ->where('training_module_id', $module->id)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof SuchakTrainingCompletion
                && $existing->completion_status === SuchakTrainingCompletion::STATUS_COMPLETED) {
                throw new InvalidArgumentException('Suchak training module is already completed for this account.');
            }

            $completion = SuchakTrainingCompletion::query()->create([
                'suchak_account_id' => $account->id,
                'training_module_id' => $module->id,
                'completion_status' => SuchakTrainingCompletion::STATUS_COMPLETED,
                'score_percent' => $score,
                'completion_note' => $note,
                'completed_by_admin_user_id' => $admin->id,
                'completed_at' => now(),
            ]);

            $audit = $this->audit($admin, 'suchak_training_module_completed', $completion, $note, [
                'training_module_id' => $module->id,
                'score_percent' => $score,
            ]);
            $completion->forceFill(['admin_audit_log_id' => $audit->id])->save();

            $this->activityLogger->record([
                'suchak_account_id' => $account->id,
                'actor_user_id' => $admin->id,
                'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
                'action_type' => 'training_module_completed',
                'target_type' => 'suchak_training_completion',
                'target_id' => $completion->id,
                'admin_audit_log_id' => $audit->id,
                'metadata_json' => [
                    'training_module_id' => $module->id,
                    'module_category' => $module->module_category,
                ],
            ]);

            return $completion->fresh(['suchakAccount', 'trainingModule', 'completedByAdmin', 'adminAuditLog']);
        });
    }

    public function issueInternalCertificate(SuchakAccount $account, User $admin, string $note): SuchakTrainingCertificate
    {
        $this->accessService->assertAdmin($admin, 'Only admins can issue Suchak training certificates.');
        $note = $this->requiredText($note, 'Suchak training certificate note is required.', 1000);
        $this->assertPolicySafeText($note);

        return DB::transaction(function () use ($account, $admin, $note): SuchakTrainingCertificate {
            $requiredModuleIds = SuchakTrainingModule::query()
                ->where('module_status', SuchakTrainingModule::STATUS_ACTIVE)
                ->where('is_required_for_certificate', true)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values();

            if ($requiredModuleIds->isEmpty()) {
                throw new InvalidArgumentException('At least one required Suchak training module is needed before certificate issue.');
            }

            $completedModuleIds = SuchakTrainingCompletion::query()
                ->where('suchak_account_id', $account->id)
                ->where('completion_status', SuchakTrainingCompletion::STATUS_COMPLETED)
                ->whereIn('training_module_id', $requiredModuleIds)
                ->pluck('training_module_id')
                ->map(fn ($id): int => (int) $id)
                ->values();

            $missingModuleIds = $requiredModuleIds
                ->diff($completedModuleIds)
                ->values();
            if ($missingModuleIds->isNotEmpty()) {
                throw new InvalidArgumentException('All required Suchak training modules must be completed before certificate issue.');
            }

            $activeCertificate = SuchakTrainingCertificate::query()
                ->where('suchak_account_id', $account->id)
                ->where('certificate_status', SuchakTrainingCertificate::STATUS_ISSUED)
                ->lockForUpdate()
                ->first();
            if ($activeCertificate instanceof SuchakTrainingCertificate) {
                throw new InvalidArgumentException('This Suchak account already has an active internal training certificate.');
            }

            $certificate = SuchakTrainingCertificate::query()->create([
                'suchak_account_id' => $account->id,
                'certificate_code' => $this->certificateCode($account),
                'certificate_status' => SuchakTrainingCertificate::STATUS_ISSUED,
                'certificate_scope' => SuchakTrainingCertificate::SCOPE_INTERNAL,
                'public_badge_status' => SuchakTrainingCertificate::PUBLIC_BADGE_NOT_PUBLIC,
                'required_module_ids_json' => $requiredModuleIds->all(),
                'certificate_note' => $note,
                'issued_by_admin_user_id' => $admin->id,
                'issued_at' => now(),
            ]);

            $audit = $this->audit($admin, 'suchak_training_certificate_issued', $certificate, $note, [
                'required_module_ids' => $requiredModuleIds->all(),
                'public_badge_status' => SuchakTrainingCertificate::PUBLIC_BADGE_NOT_PUBLIC,
            ]);
            $certificate->forceFill(['issued_admin_audit_log_id' => $audit->id])->save();

            $this->activityLogger->record([
                'suchak_account_id' => $account->id,
                'actor_user_id' => $admin->id,
                'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
                'action_type' => 'training_certificate_issued',
                'target_type' => 'suchak_training_certificate',
                'target_id' => $certificate->id,
                'admin_audit_log_id' => $audit->id,
                'metadata_json' => [
                    'certificate_scope' => $certificate->certificate_scope,
                    'public_badge_status' => $certificate->public_badge_status,
                ],
            ]);

            return $certificate->fresh(['suchakAccount', 'issuedByAdmin', 'issuedAdminAuditLog']);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createMessageTemplate(User $admin, array $attributes): SuchakMessageTemplate
    {
        $this->accessService->assertAdmin($admin, 'Only admins can create Suchak message templates.');

        $templateKey = $this->slugKey($attributes['template_key'] ?? null, 'Suchak message template key is required.');
        $title = $this->requiredText($attributes['template_title'] ?? null, 'Suchak message template title is required.', 160);
        $titleMr = $this->nullableText($attributes['template_title_mr'] ?? null, 160);
        $category = $this->allowed($attributes['template_category'] ?? null, SuchakMessageTemplate::CATEGORIES, 'Suchak message template category is invalid.');
        $channel = $this->allowed($attributes['template_channel'] ?? SuchakMessageTemplate::CHANNEL_WHATSAPP_COPY, SuchakMessageTemplate::CHANNELS, 'Suchak message template channel is invalid.');
        $body = $this->requiredText($attributes['body_text'] ?? null, 'Suchak message template body is required.', 4000);
        $bodyMr = $this->nullableText($attributes['body_text_mr'] ?? null, 4000);
        $guidance = $this->nullableText($attributes['usage_guidance'] ?? null, 1000);
        $guidanceMr = $this->nullableText($attributes['usage_guidance_mr'] ?? null, 1000);

        $this->assertPolicySafeText($title.' '.$body.' '.($guidance ?? ''));

        return DB::transaction(function () use ($admin, $templateKey, $title, $titleMr, $category, $channel, $body, $bodyMr, $guidance, $guidanceMr): SuchakMessageTemplate {
            $template = SuchakMessageTemplate::query()->create([
                'template_key' => $templateKey,
                'template_title' => $title,
                'template_title_mr' => $titleMr,
                'template_category' => $category,
                'template_channel' => $channel,
                'template_status' => SuchakMessageTemplate::STATUS_ACTIVE,
                'policy_status' => SuchakMessageTemplate::POLICY_SAFE,
                'body_text' => $body,
                'body_text_mr' => $bodyMr,
                'usage_guidance' => $guidance,
                'usage_guidance_mr' => $guidanceMr,
                'created_by_admin_user_id' => $admin->id,
            ]);

            $audit = $this->audit($admin, 'suchak_message_template_created', $template, $template->template_title, [
                'template_category' => $category,
                'template_channel' => $channel,
            ]);
            $template->forceFill(['admin_audit_log_id' => $audit->id])->save();

            return $template->fresh(['createdByAdmin', 'adminAuditLog']);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function useTemplate(
        SuchakAccount $account,
        SuchakMessageTemplate $template,
        User $actor,
        array $attributes,
    ): SuchakMessageTemplateUsage {
        $this->accessService->assertOwnerCanOperate(
            $account,
            $actor,
            'Only the owning Suchak can reuse this message template.',
            'Only verified Suchak accounts can reuse message templates.',
        );

        if ($template->template_status !== SuchakMessageTemplate::STATUS_ACTIVE
            || $template->policy_status !== SuchakMessageTemplate::POLICY_SAFE) {
            throw new InvalidArgumentException('Only active policy-safe Suchak message templates can be reused.');
        }

        $usageContext = $this->allowed($attributes['usage_context'] ?? null, SuchakMessageTemplateUsage::CONTEXTS, 'Suchak message template usage context is invalid.');
        $renderedBody = $this->requiredText($attributes['rendered_body'] ?? $template->body_text, 'Suchak rendered message body is required.', 4000);
        $this->assertPolicySafeText($renderedBody);

        return DB::transaction(function () use ($account, $template, $actor, $usageContext, $renderedBody): SuchakMessageTemplateUsage {
            $usage = SuchakMessageTemplateUsage::query()->create([
                'suchak_account_id' => $account->id,
                'message_template_id' => $template->id,
                'used_by_user_id' => $actor->id,
                'usage_context' => $usageContext,
                'rendered_body' => $renderedBody,
                'metadata_json' => [
                    'template_key' => $template->template_key,
                    'template_category' => $template->template_category,
                    'policy_status' => $template->policy_status,
                ],
                'used_at' => now(),
            ]);

            $this->activityLogger->record([
                'suchak_account_id' => $account->id,
                'actor_user_id' => $actor->id,
                'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
                'action_type' => 'message_template_reused',
                'target_type' => 'suchak_message_template_usage',
                'target_id' => $usage->id,
                'metadata_json' => [
                    'message_template_id' => $template->id,
                    'usage_context' => $usageContext,
                ],
            ]);

            return $usage->fresh(['suchakAccount', 'messageTemplate', 'usedByUser']);
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(User $admin, string $action, object $entity, string $reason, array $metadata = []): AdminAuditLog
    {
        return AuditLogService::log(
            $admin,
            $action,
            class_basename($entity),
            (int) $entity->id,
            $reason,
            false,
        );
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function allowed(mixed $value, array $allowed, string $message): string
    {
        $normalized = trim((string) ($value ?? ''));
        if (! in_array($normalized, $allowed, true)) {
            throw new InvalidArgumentException($message);
        }

        return $normalized;
    }

    private function slugKey(mixed $value, string $message): string
    {
        $normalized = Str::slug(trim((string) ($value ?? '')), '_');
        if ($normalized === '') {
            throw new InvalidArgumentException($message);
        }

        return Str::limit($normalized, 96, '');
    }

    private function requiredText(mixed $value, string $message, int $limit): string
    {
        $normalized = trim((string) ($value ?? ''));
        if ($normalized === '') {
            throw new InvalidArgumentException($message);
        }

        return Str::limit($normalized, $limit, '');
    }

    private function nullableText(mixed $value, int $limit): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === ''
            ? null
            : Str::limit($normalized, $limit, '');
    }

    private function nullableScore(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (int) $value < 0 || (int) $value > 100) {
            throw new InvalidArgumentException('Suchak training score must be between 0 and 100.');
        }

        return (int) $value;
    }

    private function sortOrder(mixed $value): int
    {
        if (! is_numeric($value) || (int) $value < 0 || (int) $value > 65535) {
            throw new InvalidArgumentException('Suchak training module sort order is invalid.');
        }

        return (int) $value;
    }

    private function certificateCode(SuchakAccount $account): string
    {
        return 'SK-ACAD-'.$account->id.'-'.Str::upper(Str::random(8));
    }

    private function assertPolicySafeText(string $text): void
    {
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text) === 1
            || preg_match('/(?<!\d)(?:\+?91[\s-]*)?[6-9]\d(?:[\s-]?\d){8}(?!\d)/', $text) === 1) {
            throw new InvalidArgumentException('Suchak training and message templates must not store private contact details.');
        }

        if (preg_match('/\bupi\b|@[a-z0-9]{2,}\b/i', $text) === 1) {
            throw new InvalidArgumentException('Suchak message templates must not expose direct payment handles.');
        }

        if (preg_match('/(100\s*(%|percent|टक्के))|guarantee|guaranteed|sure\s*shot|confirmed\s+(marriage|match)|assured\s+(marriage|match|success)|(marriage|match|success)\s+assured|हमी|खात्रीशीर/u', $text) === 1) {
            throw new InvalidArgumentException('Suchak training and message templates must not contain misleading success or guarantee claims.');
        }
    }
}

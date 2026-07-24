<?php

namespace App\Modules\Suchak\Services;

use App\Models\AdminAuditLog;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakPackageTemplate;
use App\Models\SuchakPackageTemplateDeliverable;
use App\Models\SuchakPackageTemplateStage;
use App\Models\SuchakServicePackage;
use App\Models\SuchakServicePackageDeliverable;
use App\Models\SuchakServicePackageStage;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SuchakPackageCatalogService
{
    public function __construct(
        private readonly SuchakActivityLogger $activityLogger,
        private readonly SuchakAccessService $accessService,
        private readonly SuchakPolicyService $policyService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $stages
     * @param  array<int, array<string, mixed>>  $deliverables
     */
    public function createTemplate(
        User $admin,
        array $attributes,
        array $stages,
        array $deliverables,
        string $reason,
    ): SuchakPackageTemplate {
        $this->assertAdmin($admin);
        $reason = $this->requiredReason($reason, 'Suchak package template change reason is required.');
        $stagePayloads = $this->normalizedStages($stages);
        $deliverablePayloads = $this->normalizedDeliverables($deliverables, array_column($stagePayloads, 'stage_key'));
        $templateAttributes = $this->normalizedTemplateAttributes($admin, $attributes);

        return DB::transaction(function () use ($admin, $templateAttributes, $stagePayloads, $deliverablePayloads, $reason): SuchakPackageTemplate {
            $template = SuchakPackageTemplate::query()->create($templateAttributes);
            $stageIdsByKey = [];

            foreach ($stagePayloads as $stagePayload) {
                unset($stagePayload['template_stage_id']);
                $stage = SuchakPackageTemplateStage::query()->create(array_merge($stagePayload, [
                    'package_template_id' => $template->id,
                ]));
                $stageIdsByKey[$stage->stage_key] = $stage->id;
            }

            foreach ($deliverablePayloads as $deliverablePayload) {
                $stageKey = $deliverablePayload['stage_key'];
                unset($deliverablePayload['stage_key'], $deliverablePayload['template_deliverable_id']);

                SuchakPackageTemplateDeliverable::query()->create(array_merge($deliverablePayload, [
                    'package_template_id' => $template->id,
                    'template_stage_id' => $stageKey === null ? null : $stageIdsByKey[$stageKey],
                ]));
            }

            $auditLog = $this->writeAdminAuditLog(
                $admin,
                'suchak_package_template_created',
                'SuchakPackageTemplate',
                $template->id,
                $reason.' | stages='.count($stagePayloads).' | deliverables='.count($deliverablePayloads),
            );

            $this->activityLogger->record([
                'actor_user_id' => $admin->id,
                'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
                'action_type' => SuchakActivityLog::ACTION_PACKAGE_TEMPLATE_CREATED,
                'target_type' => 'suchak_package_template',
                'target_id' => $template->id,
                'admin_audit_log_id' => $auditLog->id,
                'metadata_json' => [
                    'context' => 'package_template_created',
                    'template_status' => $template->template_status,
                    'stage_count' => count($stagePayloads),
                    'deliverable_count' => count($deliverablePayloads),
                ],
            ]);

            return $template->fresh(['stages', 'deliverables']);
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function cloneTemplateForSuchak(
        SuchakAccount $account,
        User $actor,
        SuchakPackageTemplate $template,
        array $overrides = [],
        ?SuchakCustomerContext $customerContext = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakServicePackage {
        $this->assertSuchakActor($account, $actor);
        $this->assertCustomerContext($customerContext, $account);

        $template->refresh()->loadMissing(['stages', 'deliverables.templateStage']);
        if (! $template->isApproved()) {
            throw new InvalidArgumentException('Only approved Suchak package templates can be cloned.');
        }

        $packageAttributes = $this->normalizedServicePackageAttributes(
            $account,
            $actor,
            [
                'package_name' => $overrides['package_name'] ?? $overrides['name'] ?? $template->template_name,
                'package_name_mr' => $overrides['package_name_mr'] ?? $overrides['name_mr'] ?? $template->template_name_mr,
                'package_description' => $overrides['package_description'] ?? $overrides['description'] ?? $template->template_description,
                'package_description_mr' => $overrides['package_description_mr'] ?? $overrides['description_mr'] ?? $template->template_description_mr,
                'price_amount' => $overrides['price_amount'] ?? $template->base_price_amount,
                'currency' => $overrides['currency'] ?? $template->currency,
            ],
            $customerContext,
            $template,
        );

        $stagePayloads = $template->stages->map(fn (SuchakPackageTemplateStage $stage): array => [
            'template_stage_id' => $stage->id,
            'stage_key' => $stage->stage_key,
            'stage_name' => $stage->stage_name,
            'stage_name_mr' => $stage->stage_name_mr,
            'stage_description' => $stage->stage_description,
            'stage_description_mr' => $stage->stage_description_mr,
            'sort_order' => $stage->sort_order,
            'is_required' => $stage->is_required,
            'expected_days' => $stage->expected_days,
        ])->all();

        $deliverablePayloads = $template->deliverables->map(fn (SuchakPackageTemplateDeliverable $deliverable): array => [
            'template_deliverable_id' => $deliverable->id,
            'stage_key' => $deliverable->templateStage?->stage_key,
            'deliverable_key' => $deliverable->deliverable_key,
            'deliverable_name' => $deliverable->deliverable_name,
            'deliverable_name_mr' => $deliverable->deliverable_name_mr,
            'deliverable_description' => $deliverable->deliverable_description,
            'deliverable_description_mr' => $deliverable->deliverable_description_mr,
            'sort_order' => $deliverable->sort_order,
            'is_required' => $deliverable->is_required,
        ])->all();

        return $this->persistServicePackage(
            $account,
            $actor,
            $packageAttributes,
            $stagePayloads,
            $deliverablePayloads,
            'template_clone',
            $ipAddress,
            $userAgent,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $stages
     * @param  array<int, array<string, mixed>>  $deliverables
     */
    public function createCustomPackage(
        SuchakAccount $account,
        User $actor,
        array $attributes,
        array $stages,
        array $deliverables,
        ?SuchakCustomerContext $customerContext = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        bool $autoPublish = false,
    ): SuchakServicePackage {
        $this->assertSuchakActor($account, $actor);
        $this->assertCustomerContext($customerContext, $account);

        $stagePayloads = $this->normalizedStages($stages);
        $deliverablePayloads = $this->normalizedDeliverables($deliverables, array_column($stagePayloads, 'stage_key'));
        $packageAttributes = $this->normalizedServicePackageAttributes($account, $actor, $attributes, $customerContext, null, $autoPublish);

        return $this->persistServicePackage(
            $account,
            $actor,
            $packageAttributes,
            $stagePayloads,
            $deliverablePayloads,
            'custom_package',
            $ipAddress,
            $userAgent,
        );
    }

    public function approvePackage(
        SuchakServicePackage $package,
        User $admin,
        string $reason,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakServicePackage {
        $this->assertAdmin($admin);
        $reason = $this->requiredReason($reason, 'Suchak package approval reason is required.');

        return DB::transaction(function () use ($package, $admin, $reason, $ipAddress, $userAgent): SuchakServicePackage {
            /** @var SuchakServicePackage $locked */
            $locked = SuchakServicePackage::query()
                ->whereKey($package->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->package_status !== SuchakServicePackage::STATUS_PENDING_REVIEW) {
                throw new InvalidArgumentException('Only pending review Suchak packages can be approved.');
            }

            $locked->forceFill([
                'package_status' => SuchakServicePackage::STATUS_PUBLISHED,
                'requires_admin_approval' => false,
                'approved_by_admin_user_id' => $admin->id,
                'approved_at' => now(),
                'published_at' => now(),
                'rejected_by_admin_user_id' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ])->save();

            $auditLog = $this->writeAdminAuditLog(
                $admin,
                'suchak_service_package_approved',
                'SuchakServicePackage',
                $locked->id,
                $reason.' | suchak_account_id='.(int) $locked->suchak_account_id,
            );

            $this->activityLogger->record([
                'suchak_account_id' => $locked->suchak_account_id,
                'actor_user_id' => $admin->id,
                'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
                'action_type' => SuchakActivityLog::ACTION_SERVICE_PACKAGE_APPROVED,
                'target_type' => 'suchak_service_package',
                'target_id' => $locked->id,
                'admin_audit_log_id' => $auditLog->id,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 512, ''),
                'metadata_json' => [
                    'context' => 'service_package_approved',
                    'package_status' => SuchakServicePackage::STATUS_PUBLISHED,
                    'approval_policy_mode' => $locked->approval_policy_mode,
                    'source_template_id' => $locked->source_template_id,
                    'customer_context_id' => $locked->customer_context_id,
                ],
            ]);

            return $locked->fresh(['suchakAccount', 'customerContext', 'sourceTemplate', 'stages', 'deliverables']);
        });
    }

    private function assertAdmin(User $admin): void
    {
        $this->accessService->assertAdmin($admin, 'Only admins can manage Suchak package templates and approvals.');
    }

    private function assertSuchakActor(SuchakAccount $account, User $actor): void
    {
        $this->accessService->assertOwnerCanOperate(
            $account,
            $actor,
            'Only the owning Suchak account can manage Suchak packages.',
            'Only verified Suchak accounts can manage Suchak packages.',
        );
    }

    private function assertCustomerContext(?SuchakCustomerContext $customerContext, SuchakAccount $account): void
    {
        if ($customerContext === null) {
            return;
        }

        $customerContext->refresh();
        if ((int) $customerContext->suchak_account_id !== (int) $account->id) {
            throw new InvalidArgumentException('Suchak package customer context must belong to the Suchak account.');
        }

        if (in_array($customerContext->customer_lifecycle_status, [
            SuchakCustomerContext::STATUS_CANCELLED,
            SuchakCustomerContext::STATUS_CLOSED,
        ], true)) {
            throw new InvalidArgumentException('Closed Suchak customer contexts cannot receive service packages.');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizedTemplateAttributes(User $admin, array $attributes): array
    {
        $templateName = $this->requiredText($attributes['template_name'] ?? $attributes['name'] ?? null, 'Suchak package template name is required.', 160);
        $templateNameMr = $this->limitedText($attributes['template_name_mr'] ?? $attributes['name_mr'] ?? null, 160);
        $templateDescription = $this->limitedText($attributes['template_description'] ?? $attributes['description'] ?? null, 3000);
        $templateDescriptionMr = $this->limitedText($attributes['template_description_mr'] ?? $attributes['description_mr'] ?? null, 3000);
        [$basePriceAmount, $currency] = $this->normalizedPrice($attributes['base_price_amount'] ?? $attributes['price_amount'] ?? null, $attributes['currency'] ?? null);
        $status = $this->allowedValue(
            $attributes['template_status'] ?? SuchakPackageTemplate::STATUS_APPROVED,
            SuchakPackageTemplate::STATUSES,
            'Suchak package template status is invalid.',
        );

        $this->assertNoMisleadingClaims([$templateName, $templateDescription]);

        return [
            'template_name' => $templateName,
            'template_name_mr' => $templateNameMr,
            'template_description' => $templateDescription,
            'template_description_mr' => $templateDescriptionMr,
            'base_price_amount' => $basePriceAmount,
            'currency' => $currency,
            'template_status' => $status,
            'created_by_admin_user_id' => $admin->id,
            'approved_by_admin_user_id' => $status === SuchakPackageTemplate::STATUS_APPROVED ? $admin->id : null,
            'approved_at' => $status === SuchakPackageTemplate::STATUS_APPROVED ? now() : null,
            'archived_at' => $status === SuchakPackageTemplate::STATUS_ARCHIVED ? now() : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizedServicePackageAttributes(
        SuchakAccount $account,
        User $actor,
        array $attributes,
        ?SuchakCustomerContext $customerContext,
        ?SuchakPackageTemplate $template,
        bool $forceAutoPublish = false,
    ): array {
        $packageName = $this->requiredText($attributes['package_name'] ?? $attributes['name'] ?? null, 'Suchak package name is required.', 160);
        $packageNameMr = $this->limitedText($attributes['package_name_mr'] ?? $attributes['name_mr'] ?? null, 160);
        $packageDescription = $this->limitedText($attributes['package_description'] ?? $attributes['description'] ?? null, 3000);
        $packageDescriptionMr = $this->limitedText($attributes['package_description_mr'] ?? $attributes['description_mr'] ?? null, 3000);
        [$priceAmount, $currency] = $this->normalizedPrice($attributes['price_amount'] ?? null, $attributes['currency'] ?? null);
        $approval = $this->approvalAttributes($forceAutoPublish);

        $this->assertNoMisleadingClaims([$packageName, $packageDescription]);

        return array_merge($approval, [
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext?->id,
            'source_template_id' => $template?->id,
            'package_name' => $packageName,
            'package_name_mr' => $packageNameMr,
            'package_description' => $packageDescription,
            'package_description_mr' => $packageDescriptionMr,
            'price_amount' => $priceAmount,
            'currency' => $currency,
            'customized_by_user_id' => $actor->id,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $stages
     * @return array<int, array<string, mixed>>
     */
    private function normalizedStages(array $stages): array
    {
        $payloads = [];

        foreach ($stages as $index => $stage) {
            $stageName = $this->requiredText($stage['stage_name'] ?? $stage['name'] ?? null, 'Suchak package stage name is required.', 160);
            $stageNameMr = $this->limitedText($stage['stage_name_mr'] ?? $stage['name_mr'] ?? null, 160);
            $stageKey = $this->normalizedKey($stage['stage_key'] ?? $stageName, 'Suchak package stage key is required.');
            if (isset($payloads[$stageKey])) {
                throw new InvalidArgumentException('Suchak package stage keys must be unique.');
            }

            $stageDescription = $this->limitedText($stage['stage_description'] ?? $stage['description'] ?? null, 3000);
            $stageDescriptionMr = $this->limitedText($stage['stage_description_mr'] ?? $stage['description_mr'] ?? null, 3000);
            $this->assertNoMisleadingClaims([$stageName, $stageDescription]);

            $payloads[$stageKey] = [
                'template_stage_id' => $stage['template_stage_id'] ?? null,
                'stage_key' => $stageKey,
                'stage_name' => $stageName,
                'stage_name_mr' => $stageNameMr,
                'stage_description' => $stageDescription,
                'stage_description_mr' => $stageDescriptionMr,
                'sort_order' => $this->sortOrder($stage['sort_order'] ?? $index),
                'is_required' => filter_var($stage['is_required'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'expected_days' => $this->expectedDays($stage['expected_days'] ?? null),
            ];
        }

        if ($payloads === []) {
            throw new InvalidArgumentException('At least one structured Suchak package stage is required.');
        }

        return array_values($payloads);
    }

    /**
     * @param  array<int, array<string, mixed>>  $deliverables
     * @param  array<int, string>  $stageKeys
     * @return array<int, array<string, mixed>>
     */
    private function normalizedDeliverables(array $deliverables, array $stageKeys): array
    {
        $stageLookup = array_fill_keys($stageKeys, true);
        $payloads = [];

        foreach ($deliverables as $index => $deliverable) {
            $deliverableName = $this->requiredText($deliverable['deliverable_name'] ?? $deliverable['name'] ?? null, 'Suchak package deliverable name is required.', 160);
            $deliverableNameMr = $this->limitedText($deliverable['deliverable_name_mr'] ?? $deliverable['name_mr'] ?? null, 160);
            $deliverableKey = $this->normalizedKey($deliverable['deliverable_key'] ?? $deliverableName, 'Suchak package deliverable key is required.');
            if (isset($payloads[$deliverableKey])) {
                throw new InvalidArgumentException('Suchak package deliverable keys must be unique.');
            }

            $stageKey = null;
            if (($deliverable['stage_key'] ?? null) !== null && trim((string) $deliverable['stage_key']) !== '') {
                $stageKey = $this->normalizedKey($deliverable['stage_key'], 'Suchak package deliverable stage key is invalid.');
                if (! isset($stageLookup[$stageKey])) {
                    throw new InvalidArgumentException('Suchak package deliverable stage key must match a structured stage.');
                }
            }

            $deliverableDescription = $this->limitedText($deliverable['deliverable_description'] ?? $deliverable['description'] ?? null, 3000);
            $deliverableDescriptionMr = $this->limitedText($deliverable['deliverable_description_mr'] ?? $deliverable['description_mr'] ?? null, 3000);
            $this->assertNoMisleadingClaims([$deliverableName, $deliverableDescription]);

            $payloads[$deliverableKey] = [
                'template_deliverable_id' => $deliverable['template_deliverable_id'] ?? null,
                'stage_key' => $stageKey,
                'deliverable_key' => $deliverableKey,
                'deliverable_name' => $deliverableName,
                'deliverable_name_mr' => $deliverableNameMr,
                'deliverable_description' => $deliverableDescription,
                'deliverable_description_mr' => $deliverableDescriptionMr,
                'sort_order' => $this->sortOrder($deliverable['sort_order'] ?? $index),
                'is_required' => filter_var($deliverable['is_required'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        if ($payloads === []) {
            throw new InvalidArgumentException('At least one structured Suchak package deliverable is required.');
        }

        return array_values($payloads);
    }

    /**
     * @param  array<string, mixed>  $packageAttributes
     * @param  array<int, array<string, mixed>>  $stagePayloads
     * @param  array<int, array<string, mixed>>  $deliverablePayloads
     */
    private function persistServicePackage(
        SuchakAccount $account,
        User $actor,
        array $packageAttributes,
        array $stagePayloads,
        array $deliverablePayloads,
        string $activityContext,
        ?string $ipAddress,
        ?string $userAgent,
    ): SuchakServicePackage {
        return DB::transaction(function () use ($account, $actor, $packageAttributes, $stagePayloads, $deliverablePayloads, $activityContext, $ipAddress, $userAgent): SuchakServicePackage {
            $package = SuchakServicePackage::query()->create($packageAttributes);
            $stageIdsByKey = [];

            foreach ($stagePayloads as $stagePayload) {
                $stage = SuchakServicePackageStage::query()->create(array_merge($stagePayload, [
                    'service_package_id' => $package->id,
                ]));
                $stageIdsByKey[$stage->stage_key] = $stage->id;
            }

            foreach ($deliverablePayloads as $deliverablePayload) {
                $stageKey = $deliverablePayload['stage_key'];
                unset($deliverablePayload['stage_key']);

                SuchakServicePackageDeliverable::query()->create(array_merge($deliverablePayload, [
                    'service_package_id' => $package->id,
                    'service_package_stage_id' => $stageKey === null ? null : $stageIdsByKey[$stageKey],
                ]));
            }

            $this->activityLogger->record([
                'suchak_account_id' => $account->id,
                'actor_user_id' => $actor->id,
                'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
                'action_type' => SuchakActivityLog::ACTION_SERVICE_PACKAGE_CREATED,
                'target_type' => 'suchak_service_package',
                'target_id' => $package->id,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 512, ''),
                'metadata_json' => [
                    'context' => $activityContext,
                    'package_status' => $package->package_status,
                    'approval_policy_mode' => $package->approval_policy_mode,
                    'requires_admin_approval' => $package->requires_admin_approval,
                    'source_template_id' => $package->source_template_id,
                    'customer_context_id' => $package->customer_context_id,
                    'stage_count' => count($stagePayloads),
                    'deliverable_count' => count($deliverablePayloads),
                ],
            ]);

            return $package->fresh(['suchakAccount', 'customerContext', 'sourceTemplate', 'stages', 'deliverables']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function approvalAttributes(bool $forceAutoPublish = false): array
    {
        $mode = $this->policyService->packagePublishApprovalMode();

        if ($forceAutoPublish || $mode === SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH) {
            return [
                'package_status' => SuchakServicePackage::STATUS_PUBLISHED,
                'approval_policy_mode' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
                'requires_admin_approval' => false,
                'submitted_for_review_at' => null,
                'published_at' => now(),
            ];
        }

        return [
            'package_status' => SuchakServicePackage::STATUS_PENDING_REVIEW,
            'approval_policy_mode' => SuchakServicePackage::APPROVAL_MODE_ADMIN_REVIEW,
            'requires_admin_approval' => true,
            'submitted_for_review_at' => now(),
            'published_at' => null,
        ];
    }

    private function writeAdminAuditLog(
        User $admin,
        string $actionType,
        string $entityType,
        ?int $entityId,
        string $reason,
    ): AdminAuditLog {
        return AuditLogService::log($admin, $actionType, $entityType, $entityId, $reason, false);
    }

    private function requiredReason(string $reason, string $message): string
    {
        $normalized = trim($reason);
        if ($normalized === '') {
            throw new InvalidArgumentException($message);
        }

        return Str::limit($normalized, 500, '');
    }

    private function requiredText(mixed $value, string $message, int $limit): string
    {
        $normalized = trim((string) ($value ?? ''));
        if ($normalized === '') {
            throw new InvalidArgumentException($message);
        }

        return Str::limit($normalized, $limit, '');
    }

    private function limitedText(mixed $value, int $limit): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : Str::limit($normalized, $limit, '');
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function normalizedPrice(mixed $amount, mixed $currency): array
    {
        $rawAmount = trim((string) ($amount ?? ''));
        if ($rawAmount === '') {
            return [null, null];
        }

        if (! is_numeric($rawAmount) || (float) $rawAmount < 0) {
            throw new InvalidArgumentException('Suchak package price must be zero or greater.');
        }

        $normalizedCurrency = strtoupper(trim((string) ($currency ?? 'INR')));
        if (! preg_match('/^[A-Z]{3}$/', $normalizedCurrency)) {
            throw new InvalidArgumentException('Suchak package currency must be a three-letter code.');
        }

        return [number_format((float) $rawAmount, 2, '.', ''), $normalizedCurrency];
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function allowedValue(mixed $value, array $allowed, string $message): string
    {
        $normalized = trim((string) ($value ?? ''));
        if (! in_array($normalized, $allowed, true)) {
            throw new InvalidArgumentException($message);
        }

        return $normalized;
    }

    private function normalizedKey(mixed $value, string $message): string
    {
        $raw = strtolower(trim((string) ($value ?? '')));
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $raw) ?? '';
        $normalized = trim($normalized, '_');

        if ($normalized === '') {
            throw new InvalidArgumentException($message);
        }

        return Str::limit($normalized, 80, '');
    }

    private function sortOrder(mixed $value): int
    {
        return max(0, min(65535, (int) $value));
    }

    private function expectedDays(mixed $value): ?int
    {
        $normalized = trim((string) ($value ?? ''));
        if ($normalized === '') {
            return null;
        }

        $days = (int) $normalized;
        if ($days < 1 || $days > 3650) {
            throw new InvalidArgumentException('Suchak package stage expected days must be between 1 and 3650.');
        }

        return $days;
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function assertNoMisleadingClaims(array $values): void
    {
        foreach ($values as $value) {
            $text = Str::lower(trim((string) ($value ?? '')));
            if ($text === '') {
                continue;
            }

            if (preg_match('/(100\s*(%|percent|टक्के))|guarantee|guaranteed|sure\s*shot|confirmed\s+(marriage|match)|assured\s+(marriage|match|success)|(marriage|match|success)\s+assured|हमी|खात्रीशीर/u', $text) === 1) {
                throw new InvalidArgumentException('Suchak packages must not contain misleading success or guarantee claims.');
            }
        }
    }
}

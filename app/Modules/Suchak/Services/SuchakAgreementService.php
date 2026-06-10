<?php

namespace App\Modules\Suchak\Services;

use App\Models\AdminAuditLog;
use App\Models\SuchakActivityLog;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerAgreementDeliverable;
use App\Models\SuchakCustomerAgreementStage;
use App\Models\SuchakServicePackage;
use App\Models\SuchakServicePackageDeliverable;
use App\Models\SuchakServicePackageStage;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SuchakAgreementService
{
    public function __construct(
        private readonly SuchakActivityLogger $activityLogger,
        private readonly SuchakAccessService $accessService,
        private readonly SuchakPolicyService $policyService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createAgreementForPackage(
        SuchakServicePackage $package,
        User $actor,
        array $attributes = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakCustomerAgreement {
        $package->refresh()->loadMissing($this->packageRelations());
        $this->assertPackageManager($package, $actor);
        $this->assertPackageReady($package);

        return DB::transaction(function () use ($package, $actor, $attributes, $ipAddress, $userAgent): SuchakCustomerAgreement {
            $existing = SuchakCustomerAgreement::query()
                ->where('service_package_id', $package->id)
                ->lockForUpdate()
                ->exists();

            if ($existing) {
                throw new InvalidArgumentException('Suchak package agreement already exists; create a new revision instead.');
            }

            $agreement = $this->createAgreementSnapshot(
                $package,
                $actor,
                1,
                null,
                $attributes,
            );

            $this->recordActivity(
                $agreement,
                $actor,
                SuchakActivityLog::ACTION_CUSTOMER_AGREEMENT_CREATED,
                'customer_agreement_created',
                'Create Suchak customer agreement snapshot.',
                $ipAddress,
                $userAgent,
            );

            return $agreement->fresh($this->agreementRelations());
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createRevisionForPackageChange(
        SuchakCustomerAgreement $currentAgreement,
        User $actor,
        array $attributes = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakCustomerAgreement {
        $currentAgreement->refresh()->loadMissing(['servicePackage.suchakAccount', 'servicePackage.customerContext']);
        $package = $currentAgreement->servicePackage;
        $package->refresh()->loadMissing($this->packageRelations());
        $this->assertPackageManager($package, $actor);
        $this->assertPackageReady($package);

        return DB::transaction(function () use ($currentAgreement, $package, $actor, $attributes, $ipAddress, $userAgent): SuchakCustomerAgreement {
            /** @var SuchakCustomerAgreement $locked */
            $locked = SuchakCustomerAgreement::query()
                ->whereKey($currentAgreement->id)
                ->lockForUpdate()
                ->firstOrFail();

            $latestId = (int) SuchakCustomerAgreement::query()
                ->where('service_package_id', $package->id)
                ->orderByDesc('agreement_revision')
                ->orderByDesc('id')
                ->value('id');

            if ((int) $locked->id !== $latestId) {
                throw new InvalidArgumentException('Only the latest Suchak agreement revision can be superseded.');
            }

            $nextRevision = ((int) SuchakCustomerAgreement::query()
                ->where('service_package_id', $package->id)
                ->lockForUpdate()
                ->max('agreement_revision')) + 1;

            if (! in_array($locked->terms_status, [
                SuchakCustomerAgreement::TERMS_ACCEPTED,
                SuchakCustomerAgreement::TERMS_BYPASSED,
                SuchakCustomerAgreement::TERMS_NOT_REQUIRED,
            ], true)) {
                $locked->forceFill([
                    'terms_status' => SuchakCustomerAgreement::TERMS_SUPERSEDED,
                    'superseded_at' => now(),
                ])->save();
            }

            $agreement = $this->createAgreementSnapshot(
                $package,
                $actor,
                $nextRevision,
                $locked->id,
                $attributes,
            );

            $this->recordActivity(
                $agreement,
                $actor,
                SuchakActivityLog::ACTION_CUSTOMER_AGREEMENT_REVISED,
                'customer_agreement_revised',
                $this->limitedText($attributes['revision_reason'] ?? 'Suchak package changed; created new agreement revision.', 500) ?? 'Suchak package agreement revised.',
                $ipAddress,
                $userAgent,
            );

            return $agreement->fresh($this->agreementRelations());
        });
    }

    public function acceptTerms(
        SuchakCustomerAgreement $agreement,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakCustomerAgreement {
        $agreement->refresh()->loadMissing(['suchakAccount', 'customerContext', 'servicePackage']);
        $this->assertTermsActor($agreement, $actor);

        return DB::transaction(function () use ($agreement, $actor, $ipAddress, $userAgent): SuchakCustomerAgreement {
            /** @var SuchakCustomerAgreement $locked */
            $locked = SuchakCustomerAgreement::query()
                ->whereKey($agreement->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPendingTerms($locked);
            $this->assertPackageSnapshotCurrent($locked);

            $locked->forceFill([
                'terms_status' => SuchakCustomerAgreement::TERMS_ACCEPTED,
                'accepted_by_user_id' => $actor->id,
                'accepted_at' => now(),
                'invoice_note' => 'Terms accepted for agreement revision '.$locked->agreement_revision.'.',
            ])->save();

            $fresh = $locked->fresh($this->agreementRelations());
            $this->recordActivity(
                $fresh,
                $actor,
                SuchakActivityLog::ACTION_CUSTOMER_AGREEMENT_TERMS_ACCEPTED,
                'customer_agreement_terms_accepted',
                'Suchak customer agreement terms accepted.',
                $ipAddress,
                $userAgent,
            );

            return $fresh;
        });
    }

    public function bypassTerms(
        SuchakCustomerAgreement $agreement,
        User $actor,
        string $reason,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakCustomerAgreement {
        $agreement->refresh()->loadMissing(['suchakAccount', 'customerContext', 'servicePackage']);
        $this->assertTermsActor($agreement, $actor);
        $reason = $this->requiredText($reason, 'Suchak agreement terms bypass reason is required.', 1000);

        if ($agreement->terms_policy_mode === SuchakCustomerAgreement::POLICY_STRICT
            && ! $this->accessService->isAdmin($actor)) {
            throw new InvalidArgumentException('Strict Suchak terms policy requires admin bypass.');
        }

        return DB::transaction(function () use ($agreement, $actor, $reason, $ipAddress, $userAgent): SuchakCustomerAgreement {
            /** @var SuchakCustomerAgreement $locked */
            $locked = SuchakCustomerAgreement::query()
                ->whereKey($agreement->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPendingTerms($locked);
            $this->assertPackageSnapshotCurrent($locked);

            $locked->forceFill([
                'terms_status' => SuchakCustomerAgreement::TERMS_BYPASSED,
                'bypassed_by_user_id' => $actor->id,
                'bypassed_at' => now(),
                'bypass_reason' => $reason,
                'invoice_note' => 'Terms bypassed for agreement revision '.$locked->agreement_revision.'. Bypass reason is recorded on the agreement.',
            ])->save();

            $fresh = $locked->fresh($this->agreementRelations());
            $this->recordActivity(
                $fresh,
                $actor,
                SuchakActivityLog::ACTION_CUSTOMER_AGREEMENT_TERMS_BYPASSED,
                'customer_agreement_terms_bypassed',
                $reason,
                $ipAddress,
                $userAgent,
            );

            return $fresh;
        });
    }

    public function assertAgreementAllowsPaymentRequest(SuchakCustomerAgreement $agreement): void
    {
        $agreement->refresh()->loadMissing(['servicePackage.suchakAccount', 'servicePackage.customerContext']);

        if (! $agreement->isTermsSatisfied()) {
            throw new InvalidArgumentException('Suchak agreement terms must be accepted, bypassed, or not required before sending payment requests.');
        }

        $latestId = (int) SuchakCustomerAgreement::query()
            ->where('service_package_id', $agreement->service_package_id)
            ->orderByDesc('agreement_revision')
            ->orderByDesc('id')
            ->value('id');

        if ((int) $agreement->id !== $latestId) {
            throw new InvalidArgumentException('Only the latest Suchak agreement revision can create payment requests.');
        }

        $this->assertPackageSnapshotCurrent($agreement);
    }

    private function createAgreementSnapshot(
        SuchakServicePackage $package,
        User $actor,
        int $revision,
        ?int $supersedesAgreementId,
        array $attributes,
    ): SuchakCustomerAgreement {
        $policyMode = $this->policyService->termsPolicyMode();
        $termsStatus = $policyMode === SuchakCustomerAgreement::POLICY_OPTIONAL
            ? SuchakCustomerAgreement::TERMS_NOT_REQUIRED
            : SuchakCustomerAgreement::TERMS_PENDING;
        $title = $this->requiredText(
            $attributes['agreement_title'] ?? 'Agreement for '.$package->package_name,
            'Suchak agreement title is required.',
            160,
        );
        $body = $this->limitedText($attributes['agreement_body'] ?? null, 5000);
        $invoiceNote = $this->invoiceNote($termsStatus, $policyMode, $revision, $attributes['invoice_note'] ?? null);
        $snapshotHash = $this->agreementSnapshotHash($package, $policyMode, $title, $body);

        $agreement = SuchakCustomerAgreement::query()->create([
            'suchak_account_id' => $package->suchak_account_id,
            'customer_context_id' => $package->customer_context_id,
            'service_package_id' => $package->id,
            'supersedes_agreement_id' => $supersedesAgreementId,
            'agreement_revision' => $revision,
            'terms_status' => $termsStatus,
            'terms_policy_mode' => $policyMode,
            'agreement_snapshot_hash' => $snapshotHash,
            'package_name' => $package->package_name,
            'package_description' => $package->package_description,
            'price_amount' => $package->price_amount,
            'currency' => $package->currency,
            'agreement_title' => $title,
            'agreement_body' => $body,
            'invoice_note' => $invoiceNote,
            'created_by_user_id' => $actor->id,
        ]);

        $stageIdsByKey = [];
        foreach ($package->stages as $stage) {
            $stageSnapshot = SuchakCustomerAgreementStage::query()->create([
                'customer_agreement_id' => $agreement->id,
                'service_package_stage_id' => $stage->id,
                'stage_key' => $stage->stage_key,
                'stage_name' => $stage->stage_name,
                'stage_description' => $stage->stage_description,
                'sort_order' => $stage->sort_order,
                'is_required' => $stage->is_required,
                'expected_days' => $stage->expected_days,
            ]);
            $stageIdsByKey[$stageSnapshot->stage_key] = $stageSnapshot->id;
        }

        foreach ($package->deliverables as $deliverable) {
            $stageKey = $deliverable->servicePackageStage?->stage_key;
            SuchakCustomerAgreementDeliverable::query()->create([
                'customer_agreement_id' => $agreement->id,
                'agreement_stage_id' => $stageKey === null ? null : ($stageIdsByKey[$stageKey] ?? null),
                'service_package_deliverable_id' => $deliverable->id,
                'deliverable_key' => $deliverable->deliverable_key,
                'deliverable_name' => $deliverable->deliverable_name,
                'deliverable_description' => $deliverable->deliverable_description,
                'sort_order' => $deliverable->sort_order,
                'is_required' => $deliverable->is_required,
            ]);
        }

        return $agreement;
    }

    private function assertPackageManager(SuchakServicePackage $package, User $actor): void
    {
        if ($this->accessService->isAdmin($actor)) {
            return;
        }

        $this->accessService->assertOwnerCanOperate(
            $package->suchakAccount,
            $actor,
            'Only the owning Suchak account can manage customer agreements.',
            'Only verified Suchak accounts can manage customer agreements.',
        );
    }

    private function assertTermsActor(SuchakCustomerAgreement $agreement, User $actor): void
    {
        if ($this->accessService->isAdmin($actor)) {
            return;
        }

        if ($this->accessService->canOwnerOperate($agreement->suchakAccount, $actor)) {
            return;
        }

        if ($agreement->customerContext !== null
            && in_array((int) $actor->id, array_filter([
                $agreement->customerContext->payer_user_id,
                $agreement->customerContext->consent_giver_user_id,
            ]), true)) {
            return;
        }

        throw new InvalidArgumentException('Only the Suchak owner, linked payer, consent giver, or admin can update agreement terms.');
    }

    private function assertPackageReady(SuchakServicePackage $package): void
    {
        if (! $package->isPublished()) {
            throw new InvalidArgumentException('Only published Suchak service packages can create customer agreements.');
        }
    }

    private function assertPendingTerms(SuchakCustomerAgreement $agreement): void
    {
        if ($agreement->terms_status !== SuchakCustomerAgreement::TERMS_PENDING) {
            throw new InvalidArgumentException('Only pending Suchak agreement terms can be changed.');
        }
    }

    private function assertPackageSnapshotCurrent(SuchakCustomerAgreement $agreement): void
    {
        $agreement->loadMissing('servicePackage');
        $package = $agreement->servicePackage;
        $package->refresh()->loadMissing($this->packageRelations());

        $currentHash = $this->agreementSnapshotHash(
            $package,
            $agreement->terms_policy_mode,
            $agreement->agreement_title,
            $agreement->agreement_body,
        );

        if (! hash_equals($agreement->agreement_snapshot_hash, $currentHash)) {
            throw new InvalidArgumentException('Suchak package changed. Create a new agreement revision before accepting terms.');
        }
    }

    private function agreementSnapshotHash(
        SuchakServicePackage $package,
        string $policyMode,
        string $title,
        ?string $body,
    ): string {
        return hash('sha256', json_encode([
            'terms_policy_mode' => $policyMode,
            'agreement_title' => $title,
            'agreement_body' => $body,
            'package' => [
                'id' => (int) $package->id,
                'name' => (string) $package->package_name,
                'description' => $package->package_description,
                'price_amount' => $package->price_amount === null ? null : number_format((float) $package->price_amount, 2, '.', ''),
                'currency' => $package->currency,
                'status' => $package->package_status,
                'source_template_id' => $package->source_template_id,
            ],
            'stages' => $package->stages->map(fn (SuchakServicePackageStage $stage): array => [
                'id' => (int) $stage->id,
                'stage_key' => $stage->stage_key,
                'stage_name' => $stage->stage_name,
                'stage_description' => $stage->stage_description,
                'sort_order' => (int) $stage->sort_order,
                'is_required' => (bool) $stage->is_required,
                'expected_days' => $stage->expected_days,
            ])->values()->all(),
            'deliverables' => $package->deliverables->map(fn (SuchakServicePackageDeliverable $deliverable): array => [
                'id' => (int) $deliverable->id,
                'stage_key' => $deliverable->servicePackageStage?->stage_key,
                'deliverable_key' => $deliverable->deliverable_key,
                'deliverable_name' => $deliverable->deliverable_name,
                'deliverable_description' => $deliverable->deliverable_description,
                'sort_order' => (int) $deliverable->sort_order,
                'is_required' => (bool) $deliverable->is_required,
            ])->values()->all(),
        ], JSON_THROW_ON_ERROR));
    }

    private function invoiceNote(string $termsStatus, string $policyMode, int $revision, mixed $custom): string
    {
        $customNote = $this->limitedText($custom, 1000);
        if ($customNote !== null) {
            return $customNote;
        }

        if ($termsStatus === SuchakCustomerAgreement::TERMS_NOT_REQUIRED) {
            return 'Terms not required by optional Suchak terms policy for agreement revision '.$revision.'.';
        }

        return 'Terms pending under '.$policyMode.' Suchak terms policy for agreement revision '.$revision.'.';
    }

    private function recordActivity(
        SuchakCustomerAgreement $agreement,
        User $actor,
        string $actionType,
        string $context,
        string $reason,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $adminAuditLog = $this->adminAuditLog($actor, $agreement, $actionType, $reason);

        $this->activityLogger->record([
            'suchak_account_id' => $agreement->suchak_account_id,
            'actor_user_id' => $actor->id,
            'actor_type' => $this->actorType($agreement, $actor),
            'action_type' => $actionType,
            'target_type' => 'suchak_customer_agreement',
            'target_id' => $agreement->id,
            'admin_audit_log_id' => $adminAuditLog?->id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 512, ''),
            'metadata_json' => [
                'context' => $context,
                'service_package_id' => $agreement->service_package_id,
                'customer_context_id' => $agreement->customer_context_id,
                'agreement_revision' => $agreement->agreement_revision,
                'terms_status' => $agreement->terms_status,
                'terms_policy_mode' => $agreement->terms_policy_mode,
                'supersedes_agreement_id' => $agreement->supersedes_agreement_id,
                'has_invoice_note' => $agreement->invoice_note !== null,
                'has_bypass_reason' => $agreement->bypass_reason !== null,
            ],
        ]);
    }

    private function adminAuditLog(
        User $actor,
        SuchakCustomerAgreement $agreement,
        string $actionType,
        string $reason,
    ): ?AdminAuditLog {
        if (! $this->accessService->isAdmin($actor)) {
            return null;
        }

        return AuditLogService::log(
            $actor,
            'suchak_'.$actionType,
            'SuchakCustomerAgreement',
            $agreement->id,
            Str::limit($reason.' | suchak_account_id='.(int) $agreement->suchak_account_id.' | service_package_id='.(int) $agreement->service_package_id, 1000, ''),
            false,
        );
    }

    private function actorType(SuchakCustomerAgreement $agreement, User $actor): string
    {
        if ($this->accessService->isAdmin($actor)) {
            return SuchakActivityLog::ACTOR_ADMIN;
        }

        return $this->accessService->canOwnerOperate($agreement->suchakAccount, $actor)
            ? SuchakActivityLog::ACTOR_SUCHAK
            : SuchakActivityLog::ACTOR_USER;
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
     * @return array<int, string>
     */
    private function packageRelations(): array
    {
        return [
            'suchakAccount',
            'customerContext',
            'stages',
            'deliverables.servicePackageStage',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function agreementRelations(): array
    {
        return [
            'suchakAccount',
            'customerContext',
            'servicePackage',
            'supersedesAgreement',
            'stages',
            'deliverables',
        ];
    }
}

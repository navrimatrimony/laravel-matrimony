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
use App\Models\SuchakSuccessFeeTranche;
use App\Models\User;
use App\Services\AuditLogService;
use App\Support\MoneyFormat;
use App\Support\Suchak\SuchakContactRouting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SuchakAgreementService
{
    /**
     * Matches SuchakConsent::DEFAULT_TOKEN_EXPIRY_DAYS. Both links reach the same
     * family through the same WhatsApp forward, so a customer who sits on one for
     * a week must find both dead, not one still live.
     */
    private const ACCEPTANCE_TOKEN_EXPIRY_DAYS = 7;

    public function __construct(
        private readonly SuchakActivityLogger $activityLogger,
        private readonly SuchakAccessService $accessService,
        private readonly SuchakPolicyService $policyService,
        private readonly SuchakSuccessFeeTrancheService $trancheService,
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

    /**
     * Bring a package's latest agreement into a terms-satisfied state so a
     * payment request can go out, creating a fresh revision first when the
     * pending snapshot is stale.
     *
     * This is the robust variant of {@see acceptTerms}: it never throws
     * "Suchak package changed." If the latest (pending) revision no longer
     * matches the current package, it is superseded by a new revision — reusing
     * {@see createRevisionForPackageChange}, which recomputes the snapshot from
     * the current package — and that fresh revision is accepted instead. An
     * already-satisfied agreement (accepted / bypassed / not_required) is
     * returned unchanged. The passed agreement must be the latest revision for
     * its package (the createRevisionForPackageChange contract).
     */
    public function acceptOrReviseTerms(
        SuchakCustomerAgreement $agreement,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakCustomerAgreement {
        $agreement->refresh()->loadMissing(['suchakAccount', 'customerContext', 'servicePackage']);

        // Nothing to do when terms are already satisfied for this revision.
        if ($agreement->isTermsSatisfied()) {
            return $agreement;
        }

        // A stale pending snapshot is exactly what acceptTerms would reject.
        // Do what that rejection asks: supersede it with a new revision built
        // from the current package, then accept THAT instead of throwing.
        if (! $this->isPackageSnapshotCurrent($agreement)) {
            $agreement = $this->createRevisionForPackageChange(
                $agreement,
                $actor,
                [
                    'terms_policy_mode' => $agreement->terms_policy_mode,
                    'agreement_title' => $agreement->agreement_title,
                    'agreement_body' => $agreement->agreement_body,
                    'revision_reason' => 'Suchak package changed after pending agreement; superseded before payment request.',
                ],
                $ipAddress,
                $userAgent,
            );

            // Under an optional terms policy the fresh revision is already
            // TERMS_NOT_REQUIRED — no acceptance step needed.
            if ($agreement->isTermsSatisfied()) {
                return $agreement;
            }
        }

        return $this->acceptTerms($agreement, $actor, $ipAddress, $userAgent);
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

    /**
     * Mints the single-use public link a customer accepts the price terms on.
     *
     * The link is issued from the logged-in Suchak side, so the ordinary actor
     * check still applies here — it is only the customer's later click that has
     * no identity to check.
     *
     * Re-issuing overwrites the previous hash and clears the used marker, which
     * is what makes a lost or stale WhatsApp forward stop working the moment a
     * replacement is sent. Because re-issuing silently kills the link already in
     * the customer's hands, every issuance writes its own activity row —
     * ACTION_CUSTOMER_AGREEMENT_LINK_ISSUED, which exists for exactly this and
     * nothing else. The token columns hold only the newest link; the trail holds
     * all of them.
     *
     * @return array{agreement: SuchakCustomerAgreement, raw_token: string, acceptance_url: string, expires_at: \Illuminate\Support\Carbon, forward_message: string}
     */
    public function issueAcceptanceLink(
        SuchakCustomerAgreement $agreement,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $agreement->refresh()->loadMissing(['suchakAccount', 'customerContext', 'servicePackage']);
        $this->assertTermsActor($agreement, $actor);

        return DB::transaction(function () use ($agreement, $actor, $ipAddress, $userAgent): array {
            /** @var SuchakCustomerAgreement $locked */
            $locked = SuchakCustomerAgreement::query()
                ->whereKey($agreement->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPendingTerms($locked);
            $this->assertPackageSnapshotCurrent($locked);

            $rawToken = Str::random(64);
            $expiresAt = now()->addDays(self::ACCEPTANCE_TOKEN_EXPIRY_DAYS);

            // Query builder, not a model save: the row is still pending today, but
            // the acceptance path next door cannot use a save at all, and a single
            // write mechanism for this table keeps the two from diverging.
            SuchakCustomerAgreement::query()
                ->whereKey($locked->id)
                ->update([
                    'acceptance_token_hash' => hash('sha256', $rawToken),
                    'acceptance_token_expires_at' => $expiresAt,
                    'acceptance_token_used_at' => null,
                    'updated_at' => now(),
                ]);

            $fresh = $locked->fresh($this->agreementRelations());
            $acceptanceUrl = $this->publicAcceptanceUrl($rawToken);

            $this->recordActivity(
                $fresh,
                $actor,
                SuchakActivityLog::ACTION_CUSTOMER_AGREEMENT_LINK_ISSUED,
                'customer_agreement_link_issued',
                'Suchak customer agreement acceptance link issued.',
                $ipAddress,
                $userAgent,
            );

            return [
                'agreement' => $fresh,
                'raw_token' => $rawToken,
                'acceptance_url' => $acceptanceUrl,
                'expires_at' => $expiresAt,
                'forward_message' => $this->acceptanceForwardMessage($fresh, $acceptanceUrl),
            ];
        });
    }

    /**
     * The Marathi WhatsApp text the Suchak forwards with the link.
     *
     * Lives beside the link it carries, so there is one place that knows what an
     * agreement link is worded as. Nothing generic is re-solved here: the Suchak
     * name comes from the shared owner (SuchakContactRouting::displayName, the
     * same name a member sees on the profile page and in chat) and the amount
     * from MoneyFormat, the one money formatter.
     *
     * D27: who is asking, what it is, what it costs, the link. The page behind
     * the link carries the rest — repeating rules or reassurance here would give
     * the reader nothing to do differently.
     */
    private function acceptanceForwardMessage(SuchakCustomerAgreement $agreement, string $acceptanceUrl): string
    {
        $suchakName = SuchakContactRouting::displayName($agreement->suchakAccount);
        $price = MoneyFormat::amount($agreement->price_amount, (string) ($agreement->currency ?: 'INR'));

        return "नमस्कार,\n\n"
            ."मी {$suchakName}.\n\n"
            ."सेवा शुल्काचा करार तुमच्या स्वीकारासाठी पाठवत आहे.\n"
            .($price === null ? '' : "नोंदणी शुल्क: {$price}\n")
            ."\nकरार पाहण्यासाठी आणि स्वीकारण्यासाठी खालील लिंकवर क्लिक करा:\n"
            .$acceptanceUrl;
    }

    /**
     * Looks an agreement up from a raw public token. Read-only on purpose: the
     * agreement has no "link opened" state to move to, and any write here would
     * be a write on a row that may already be frozen.
     */
    public function resolvePublicAcceptanceToken(string $token): ?SuchakCustomerAgreement
    {
        if (! preg_match('/^[A-Za-z0-9]{64}$/', $token)) {
            return null;
        }

        return SuchakCustomerAgreement::query()
            ->with($this->agreementRelations())
            ->where('acceptance_token_hash', hash('sha256', $token))
            ->first();
    }

    /**
     * Records a customer's acceptance arriving from the public link.
     *
     * Two things make this different from acceptTerms and neither is optional:
     *
     * 1. The write is a query-builder update. SuchakCustomerAgreement throws on
     *    any model save once terms_status is final, and this call is the very
     *    transition that makes it final — a save would abort mid-acceptance.
     * 2. accepted_by_user_id stays NULL. Possession of the secret link is what
     *    was proven, not who is holding it, and there is no OTP on this path
     *    yet. Naming a user here would record a verification that never ran.
     *
     * What is stored is only what actually happened: the typed name, the IP, the
     * user agent, and the moment the link was spent.
     */
    public function recordPublicAcceptance(
        SuchakCustomerAgreement $agreement,
        string $acceptedByName,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakCustomerAgreement {
        $agreement->refresh()->loadMissing(['suchakAccount', 'customerContext', 'servicePackage']);
        $acceptedByName = $this->requiredText($acceptedByName, 'Accepting person name is required.', 160);
        $this->assertPublicAcceptanceAllowed($agreement);

        return DB::transaction(function () use ($agreement, $acceptedByName, $ipAddress, $userAgent): SuchakCustomerAgreement {
            /** @var SuchakCustomerAgreement $locked */
            $locked = SuchakCustomerAgreement::query()
                ->whereKey($agreement->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Same two guards the logged-in path uses; a public click must not be
            // able to accept a superseded revision or a stale package snapshot.
            $this->assertPendingTerms($locked);
            $this->assertPackageSnapshotCurrent($locked);

            $acceptedAt = now();

            SuchakCustomerAgreement::query()
                ->whereKey($locked->id)
                ->update([
                    'terms_status' => SuchakCustomerAgreement::TERMS_ACCEPTED,
                    'accepted_at' => $acceptedAt,
                    'acceptance_token_used_at' => $acceptedAt,
                    'accepted_ip_address' => $ipAddress,
                    'accepted_user_agent' => $userAgent === null ? null : Str::limit($userAgent, 512, ''),
                    'accepted_by_name' => $acceptedByName,
                    'invoice_note' => 'Terms accepted by customer from public link for agreement revision '.$locked->agreement_revision.'.',
                    'updated_at' => $acceptedAt,
                ]);

            $fresh = $locked->fresh($this->agreementRelations());
            $this->recordActivity(
                $fresh,
                null,
                SuchakActivityLog::ACTION_CUSTOMER_AGREEMENT_TERMS_ACCEPTED,
                'customer_agreement_public_acceptance',
                'Suchak customer agreement terms accepted from public link.',
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
        // The caller may pin the terms mode for this agreement (e.g. the Suchak's
        // per-request choice on the payment screen); otherwise fall back to the
        // platform policy. A valid override wins so one request's decision never
        // leaks into the global setting.
        $policyModeOverride = $attributes['terms_policy_mode'] ?? null;
        $policyMode = in_array($policyModeOverride, SuchakCustomerAgreement::POLICY_MODES, true)
            ? $policyModeOverride
            : $this->policyService->termsPolicyMode();
        $termsStatus = $policyMode === SuchakCustomerAgreement::POLICY_OPTIONAL
            ? SuchakCustomerAgreement::TERMS_NOT_REQUIRED
            : SuchakCustomerAgreement::TERMS_PENDING;
        $title = $this->requiredText(
            $attributes['agreement_title'] ?? 'Agreement for '.$package->package_name,
            'Suchak agreement title is required.',
            160,
        );
        $titleMr = $this->limitedText($attributes['agreement_title_mr'] ?? null, 160);
        $body = $this->limitedText($attributes['agreement_body'] ?? null, 5000);
        $bodyMr = $this->limitedText($attributes['agreement_body_mr'] ?? null, 5000);
        $invoiceNote = $this->invoiceNote($termsStatus, $policyMode, $revision, $attributes['invoice_note'] ?? null);
        $invoiceNoteMr = $this->limitedText($attributes['invoice_note_mr'] ?? null, 1000);

        // Blueprint 7.4: the success-fee split is set at agreement time and freezes with the
        // rest of the terms, so it is resolved before the digest and hashed with it.
        $supersededTranches = $this->supersededTranches($supersedesAgreementId);
        $trancheRows = $this->resolveTranchePlan($attributes, $supersededTranches);
        $this->trancheService->assertPackageCarriesFixedSuccessFee($package, $trancheRows);
        $this->trancheService->assertPlanChangeAllowed($supersededTranches, $trancheRows);

        $snapshotHash = $this->agreementSnapshotHash($package, $policyMode, $title, $body, $trancheRows);

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
            'package_name_mr' => $package->package_name_mr,
            'package_description' => $package->package_description,
            'package_description_mr' => $package->package_description_mr,
            'price_amount' => $package->price_amount,
            'currency' => $package->currency,
            'agreement_title' => $title,
            'agreement_title_mr' => $titleMr,
            'agreement_body' => $body,
            'agreement_body_mr' => $bodyMr,
            'invoice_note' => $invoiceNote,
            'invoice_note_mr' => $invoiceNoteMr,
            'created_by_user_id' => $actor->id,
        ]);

        $stageIdsByKey = [];
        foreach ($package->stages as $stage) {
            $stageSnapshot = SuchakCustomerAgreementStage::query()->create([
                'customer_agreement_id' => $agreement->id,
                'service_package_stage_id' => $stage->id,
                'stage_key' => $stage->stage_key,
                'stage_name' => $stage->stage_name,
                'stage_name_mr' => $stage->stage_name_mr,
                'stage_description' => $stage->stage_description,
                'stage_description_mr' => $stage->stage_description_mr,
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
                'deliverable_name_mr' => $deliverable->deliverable_name_mr,
                'deliverable_description' => $deliverable->deliverable_description,
                'deliverable_description_mr' => $deliverable->deliverable_description_mr,
                'sort_order' => $deliverable->sort_order,
                'is_required' => $deliverable->is_required,
            ]);
        }

        $this->persistTranchePlan($agreement, $trancheRows, $supersededTranches);

        return $agreement;
    }

    /**
     * The tranche rows of the revision being superseded, or none for a first agreement.
     *
     * @return list<SuchakSuccessFeeTranche>
     */
    private function supersededTranches(?int $supersedesAgreementId): array
    {
        if ($supersedesAgreementId === null) {
            return [];
        }

        return SuchakSuccessFeeTranche::query()
            ->where('customer_agreement_id', $supersedesAgreementId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * An explicit split wins; otherwise the previous revision's split carries forward.
     *
     * Carrying forward matters more than it looks: createRevisionForPackageChange is called
     * from acceptOrReviseTerms with only the title and body, so without this a package edit
     * would silently drop a split the customer had already agreed to and the whole success fee
     * would fall due at one stage.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<SuchakSuccessFeeTranche>  $supersededTranches
     * @return list<array<string, mixed>>
     */
    private function resolveTranchePlan(array $attributes, array $supersededTranches): array
    {
        if (array_key_exists('success_fee_tranches', $attributes) && is_array($attributes['success_fee_tranches'])) {
            return $this->trancheService->normalizePlan($attributes['success_fee_tranches']);
        }

        return $this->trancheService->snapshotPayload($supersededTranches);
    }

    /**
     * @param  list<array<string, mixed>>  $trancheRows
     * @param  list<SuchakSuccessFeeTranche>  $supersededTranches
     */
    private function persistTranchePlan(
        SuchakCustomerAgreement $agreement,
        array $trancheRows,
        array $supersededTranches,
    ): void {
        if ($trancheRows === []) {
            return;
        }

        // M9: the ledger follows the customer, not the revision. When the split is unchanged the
        // previous revision's release and payment state moves with it, so a tranche already paid
        // stays paid across a package edit and the family's total exposure never resets.
        $carriedState = [];
        if ($this->trancheService->snapshotPayload($supersededTranches) === $trancheRows) {
            foreach ($supersededTranches as $tranche) {
                $carriedState[(string) $tranche->trigger_stage_key] = $tranche;
            }
        }

        foreach ($trancheRows as $row) {
            $source = $carriedState[$row['trigger_stage_key']] ?? null;

            SuchakSuccessFeeTranche::query()->create([
                'customer_agreement_id' => $agreement->id,
                'sort_order' => $row['sort_order'],
                'trigger_stage_key' => $row['trigger_stage_key'],
                'share_percent' => $row['share_percent'],
                'is_final_tranche' => $row['is_final_tranche'],
                'released_by_collaboration_request_id' => $source?->released_by_collaboration_request_id,
                'released_by_stage_event_id' => $source?->released_by_stage_event_id,
                'released_at' => $source?->released_at,
                'customer_payment_id' => $source?->customer_payment_id,
                'settled_at' => $source?->settled_at,
            ]);
        }

        $this->trancheService->logAdvisories($trancheRows, (int) $agreement->id);
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

    /**
     * The public counterpart of assertTermsActor.
     *
     * assertTermsActor is untouched and still governs the logged-in path. Here
     * there is no actor to authorise, so what is checked instead is the token:
     * that one was ever issued, that it has not already been spent, and that it
     * has not aged out. This mirrors assertPublicDecisionAllowed on the consent
     * side, which trades actor identity for link possession the same way.
     */
    private function assertPublicAcceptanceAllowed(SuchakCustomerAgreement $agreement): void
    {
        if ($agreement->acceptance_token_hash === null) {
            throw new InvalidArgumentException('This agreement has no public acceptance link.');
        }

        if ($agreement->acceptance_token_used_at !== null) {
            throw new InvalidArgumentException('Agreement acceptance link has already been used.');
        }

        if ($agreement->isAcceptanceTokenExpired()) {
            throw new InvalidArgumentException('Agreement acceptance link has expired.');
        }
    }

    private function publicAcceptanceUrl(string $rawToken): string
    {
        return route('suchak.agreements.public.show', ['token' => $rawToken]);
    }

    /**
     * Whether the agreement's stored snapshot still matches its (current)
     * package. Reused by {@see acceptOrReviseTerms} to decide when a fresh
     * revision is needed instead of throwing.
     */
    public function isPackageSnapshotCurrent(SuchakCustomerAgreement $agreement): bool
    {
        $agreement->loadMissing('servicePackage');
        $package = $agreement->servicePackage;
        $package->refresh()->loadMissing($this->packageRelations());

        $currentHash = $this->agreementSnapshotHash(
            $package,
            $agreement->terms_policy_mode,
            $agreement->agreement_title,
            $agreement->agreement_body,
            // Read straight from the table, never from an already-loaded relation: a stale
            // in-memory copy would hide the very edit this comparison exists to catch.
            $agreement->successFeeTranches()->get()->all(),
        );

        return hash_equals($agreement->agreement_snapshot_hash, $currentHash);
    }

    private function assertPackageSnapshotCurrent(SuchakCustomerAgreement $agreement): void
    {
        if (! $this->isPackageSnapshotCurrent($agreement)) {
            throw new InvalidArgumentException('Suchak package changed. Create a new agreement revision before accepting terms.');
        }
    }

    /**
     * The single definition of what an agreement snapshot covers.
     *
     * Public only so a data migration can re-digest stored agreements through
     * this exact payload; copying the payload into the migration would let the
     * two drift, which is precisely the staleness this hash exists to detect.
     *
     * @param  iterable<int, SuchakSuccessFeeTranche|array<string, mixed>>  $successFeeTranches
     *                                                                     the success-fee split
     *                                                                     (blueprint 7.4), as
     *                                                                     models or canonical rows
     */
    public function agreementSnapshotHash(
        SuchakServicePackage $package,
        string $policyMode,
        string $title,
        ?string $body,
        iterable $successFeeTranches = [],
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
                // Fee terms are part of what the customer agreed to, so editing one
                // has to invalidate the snapshot just like editing the price does.
                'per_meeting_fee_amount' => $package->per_meeting_fee_amount === null ? null : number_format((float) $package->per_meeting_fee_amount, 2, '.', ''),
                'per_meeting_online_fee_amount' => $package->per_meeting_online_fee_amount === null ? null : number_format((float) $package->per_meeting_online_fee_amount, 2, '.', ''),
                'post_marriage_fee_mode' => $package->post_marriage_fee_mode,
                'post_marriage_fee_amount' => $package->post_marriage_fee_amount === null ? null : number_format((float) $package->post_marriage_fee_amount, 2, '.', ''),
                'status' => $package->package_status,
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
            // The success-fee split freezes with the agreement (blueprint 7.4), so re-cutting a
            // tranche after acceptance has to read as "terms changed" for exactly the same
            // reason editing the price or either meeting fee does. Plan facts only — release and
            // payment state is ledger movement, not a change of terms.
            'success_fee_tranches' => $this->trancheService->snapshotPayload($successFeeTranches),
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

    /**
     * $actor is nullable because a public acceptance has no signed-in user to
     * attribute — the same shape SuchakConsentService::recordActivity already
     * uses for candidate decisions arriving from a consent link.
     */
    private function recordActivity(
        SuchakCustomerAgreement $agreement,
        ?User $actor,
        string $actionType,
        string $context,
        string $reason,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $adminAuditLog = $this->adminAuditLog($actor, $agreement, $actionType, $reason);

        $this->activityLogger->record([
            'suchak_account_id' => $agreement->suchak_account_id,
            'actor_user_id' => $actor?->id,
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
        ?User $actor,
        SuchakCustomerAgreement $agreement,
        string $actionType,
        string $reason,
    ): ?AdminAuditLog {
        if ($actor === null || ! $this->accessService->isAdmin($actor)) {
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

    private function actorType(SuchakCustomerAgreement $agreement, ?User $actor): string
    {
        // A public acceptance is the customer acting, not the platform: ACTOR_USER
        // keeps that readable in the trail even though no user id backs it.
        if ($actor === null) {
            return SuchakActivityLog::ACTOR_USER;
        }

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
            'successFeeTranches',
        ];
    }
}

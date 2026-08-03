<?php

namespace App\Modules\Suchak\Services;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakLedgerEntry;
use App\Models\SuchakPaymentRequest;
use App\Models\SuchakPipeline;
use App\Models\SuchakPlatformLeadAllocation;
use App\Models\SuchakProfileNote;
use App\Models\SuchakProfileRepresentation;
use App\Modules\Suchak\Support\SuchakWorklistSourceQueries;
use App\Support\MoneyFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The Suchak's first screen, every day.
 *
 * Every word this service emits is a translation key (`suchak.worklist.*`), and
 * every sentence is worded WHOLE in each language. It used to build English
 * literals and concatenate an already-translated fit summary onto an English
 * tail, so a Marathi Suchak read "… 3 तपासणी नोंदी against your representation.
 * Reference: masked-def044cc4c1d." — three defects in one line: an English
 * title, a half-translated sentence, and an internal hash shown to a human.
 *
 * Two rules hold the fix in place:
 *
 *  - A sentence is ONE key. Anything already localised (the engine's fit
 *    summary, a formatted date, a formatted amount) enters as a `:parameter`.
 *    Nothing is glued together in PHP, because gluing is what produced the
 *    half-and-half line in the first place.
 *  - No internal identifier reaches the prose. `candidate_reference` still
 *    carries the masked handle as a FIELD (it is the machine-side key, and the
 *    cross-Suchak mask that D19a requires), but a human reads a NAME: where the
 *    card is about one of this Suchak's OWN consented customers he is told who
 *    it is, exactly as his customer list already tells him
 *    ({@see SuchakCustomerListService}). Where there is no own customer behind
 *    the record, the sentence says so honestly instead of naming a row id.
 */
class SuchakDailyOpportunityService
{
    private const FINAL_LIMIT = 12;

    private const PER_BUCKET_LIMIT = 5;

    /**
     * `accountId => [profileId => name]` for this Suchak's own consented
     * customers. One query per account per process; the buckets that hold a
     * representation already read the name off it and never come here.
     *
     * Keyed by account (rather than reset per call) because the container hands
     * the same instance to two dailyWorklist() calls in one process, and a map
     * built for one Suchak must never answer for another.
     *
     * @var array<int, array<int, string>>
     */
    private array $ownCustomerNames = [];

    public function __construct(
        private readonly SuchakCandidateMaskingService $maskingService,
        private readonly SuchakMatchFitService $matchFitService,
    ) {
    }

    public function dailyWorklist(SuchakAccount $account, ?Carbon $at = null): Collection
    {
        $at ??= now();

        return collect()
            ->merge($this->followUpsDue($account, $at))
            ->merge($this->consentsExpiring($account, $at))
            ->merge($this->missingPdfs($account))
            ->merge($this->slaRisks($account, $at))
            ->merge($this->paymentsDue($account, $at))
            ->merge($this->collaborationOpportunities($account))
            // ONE extractor returning a tuple, never a LIST of extractors.
            // Collection::sortBy() treats each callable in a list as a
            // two-argument COMPARATOR, not a value reader. These read one
            // argument and return a large positive timestamp, so uasort was
            // told "a comes after b" on every single comparison and the second
            // and third callables never ran at all. Measured: the worklist came
            // back in exactly the reverse of its merge order while the due dates
            // ran forward — and because take() then trims the tail, an overdue
            // payment could be dropped in favour of a collaboration suggestion.
            ->sortBy(fn (array $item): array => [
                $item['due_at'] instanceof Carbon
                    ? $item['due_at']->getTimestamp()
                    : PHP_INT_MAX,
                $this->typeOrder($item['type']),
                (int) ($item['target_id'] ?? 0),
            ])
            ->values()
            ->take(self::FINAL_LIMIT);
    }

    private function followUpsDue(SuchakAccount $account, Carbon $at): Collection
    {
        return SuchakWorklistSourceQueries::dueFollowUpNotes($account, $at)
            ->limit(self::PER_BUCKET_LIMIT)
            ->get()
            ->map(function (SuchakProfileNote $note) use ($account): array {
                // Note text itself is never echoed: a Suchak's private note may
                // carry a phone number, and this feed is not where that gets
                // republished. The name is a different fact and is his own.
                $name = $this->ownCustomerName($account, $note->matrimony_profile_id);
                $due = $this->moment($note->follow_up_at);

                return [
                    'type' => 'follow_up_due',
                    'label' => __('suchak.worklist.follow_up_due.label'),
                    'reason' => $name === null
                        ? __('suchak.worklist.follow_up_due.reason', ['due' => $due])
                        : __('suchak.worklist.follow_up_due.reason_named', ['name' => $name, 'due' => $due]),
                    'due_at' => $note->follow_up_at,
                    'target_type' => 'suchak_profile_note',
                    'target_id' => $note->id,
                    'candidate_reference' => null,
                    'action_label' => __('suchak.worklist.follow_up_due.action'),
                    'action_url' => route('suchak.dashboard'),
                ];
            });
    }

    private function consentsExpiring(SuchakAccount $account, Carbon $at): Collection
    {
        return SuchakWorklistSourceQueries::expiringConsentedRepresentations($account, $at)
            ->with(['matrimonyProfile.religion', 'matrimonyProfile.caste'])
            ->whereHas('matrimonyProfile', fn (Builder $query) => $this->activeProfileQuery($query))
            ->limit(self::PER_BUCKET_LIMIT)
            ->get()
            ->map(function (SuchakProfileRepresentation $representation): array {
                $name = $this->representedCustomerName($representation);
                $due = $this->moment($representation->consent_valid_until);

                return [
                    'type' => 'consent_expiring',
                    'label' => __('suchak.worklist.consent_expiring.label'),
                    'reason' => $name === null
                        ? __('suchak.worklist.consent_expiring.reason', ['due' => $due])
                        : __('suchak.worklist.consent_expiring.reason_named', ['name' => $name, 'due' => $due]),
                    'due_at' => $representation->consent_valid_until,
                    'target_type' => 'suchak_profile_representation',
                    'target_id' => $representation->id,
                    'candidate_reference' => $this->maskedCandidateReference($representation),
                    'action_label' => __('suchak.worklist.consent_expiring.action'),
                    'action_url' => route('suchak.dashboard'),
                ];
            });
    }

    private function missingPdfs(SuchakAccount $account): Collection
    {
        return SuchakProfileRepresentation::query()
            ->with(['matrimonyProfile.religion', 'matrimonyProfile.caste'])
            ->where('suchak_account_id', $account->id)
            ->withValidConsent()
            ->whereHas('matrimonyProfile', fn (Builder $query) => $this->activeProfileQuery($query))
            ->whereDoesntHave('biodataExports', fn (Builder $query) => $query->whereNotNull('file_path'))
            ->orderBy('id')
            ->limit(self::PER_BUCKET_LIMIT)
            ->get()
            ->map(function (SuchakProfileRepresentation $representation): array {
                $name = $this->representedCustomerName($representation);

                return [
                    'type' => 'pdf_missing',
                    'label' => __('suchak.worklist.pdf_missing.label'),
                    'reason' => $name === null
                        ? __('suchak.worklist.pdf_missing.reason')
                        : __('suchak.worklist.pdf_missing.reason_named', ['name' => $name]),
                    'due_at' => null,
                    'target_type' => 'suchak_profile_representation',
                    'target_id' => $representation->id,
                    'candidate_reference' => $this->maskedCandidateReference($representation),
                    'action_label' => __('suchak.worklist.pdf_missing.action'),
                    'action_url' => route('suchak.dashboard'),
                ];
            });
    }

    private function slaRisks(SuchakAccount $account, Carbon $at): Collection
    {
        $pipelineRisks = SuchakPipeline::query()
            ->where('selected_suchak_account_id', $account->id)
            ->where('pipeline_status', SuchakPipeline::STATUS_PENDING)
            ->whereNotNull('lock_expires_at')
            ->whereBetween('lock_expires_at', [$at, $at->copy()->addHours(12)])
            ->orderBy('lock_expires_at')
            ->orderBy('id')
            ->limit(self::PER_BUCKET_LIMIT)
            ->get()
            ->map(function (SuchakPipeline $pipeline) use ($account): array {
                // The candidate a member approached through this Suchak is one
                // of his own represented customers, so he is told which one.
                $name = $this->ownCustomerName($account, $pipeline->target_matrimony_profile_id);
                $due = $this->moment($pipeline->lock_expires_at);

                return [
                    'type' => 'sla_risk',
                    'label' => __('suchak.worklist.sla_risk.label'),
                    'reason' => $name === null
                        ? __('suchak.worklist.sla_risk.reason_request', ['due' => $due])
                        : __('suchak.worklist.sla_risk.reason_request_named', ['name' => $name, 'due' => $due]),
                    'due_at' => $pipeline->lock_expires_at,
                    'target_type' => 'suchak_pipeline',
                    'target_id' => $pipeline->id,
                    'candidate_reference' => null,
                    'action_label' => __('suchak.worklist.sla_risk.action_request'),
                    'action_url' => route('suchak.dashboard'),
                ];
            });

        $leadAllocationRisks = SuchakPlatformLeadAllocation::query()
            ->where('suchak_account_id', $account->id)
            ->whereIn('allocation_status', [
                SuchakPlatformLeadAllocation::STATUS_ALLOCATED,
                SuchakPlatformLeadAllocation::STATUS_ACCEPTED,
            ])
            ->whereNotNull('sla_expires_at')
            ->whereBetween('sla_expires_at', [$at, $at->copy()->addHours(12)])
            ->orderBy('sla_expires_at')
            ->orderBy('id')
            ->limit(self::PER_BUCKET_LIMIT)
            ->get()
            // A platform lead is a person who has NOT been linked to a customer
            // of this Suchak yet, so there is no own-customer name to give and
            // none is invented. The sentence stands on its own.
            ->map(fn (SuchakPlatformLeadAllocation $allocation): array => [
                'type' => 'sla_risk',
                'label' => __('suchak.worklist.sla_risk.label'),
                'reason' => __('suchak.worklist.sla_risk.reason_lead', [
                    'due' => $this->moment($allocation->sla_expires_at),
                ]),
                'due_at' => $allocation->sla_expires_at,
                'target_type' => 'suchak_platform_lead_allocation',
                'target_id' => $allocation->id,
                'candidate_reference' => null,
                'action_label' => __('suchak.worklist.sla_risk.action_lead'),
                'action_url' => route('suchak.dashboard'),
            ]);

        // toBase() before merge, and it is load-bearing. Eloquent's map() hands
        // back a plain collection only when it can see a non-model in the
        // result — so an EMPTY map stays an Eloquent collection, whose merge()
        // calls getKey() on every item. These items are arrays. The dashboard
        // then died with "Call to a member function getKey() on array", but
        // only for a Suchak whose first list happened to be empty and second
        // was not, which is why it survived every test.
        return $pipelineRisks->toBase()->merge($leadAllocationRisks)
            // See dailyWorklist(): a list of callables is a list of
            // comparators, so this bucket was reversed too.
            ->sortBy(fn (array $item): array => [
                $item['due_at'] instanceof Carbon ? $item['due_at']->getTimestamp() : PHP_INT_MAX,
                (int) $item['target_id'],
            ])
            ->values()
            ->take(self::PER_BUCKET_LIMIT);
    }

    private function paymentsDue(SuchakAccount $account, Carbon $at): Collection
    {
        $ledgerEntries = SuchakWorklistSourceQueries::dueLedgerEntries($account, $at)
            ->limit(self::PER_BUCKET_LIMIT)
            ->get()
            ->map(function (SuchakLedgerEntry $entry) use ($account): array {
                $name = $this->ownCustomerName($account, $entry->matrimony_profile_id);
                // MoneyFormat is the one owner of Indian comma grouping and of
                // "null in, null out" — a ledger row may carry no amount, and a
                // missing figure gets its own whole sentence, never a blank gap.
                $amount = MoneyFormat::amount($entry->amount, (string) ($entry->currency ?: 'INR'));
                $date = $this->day($entry->due_date);

                $key = match (true) {
                    $name !== null && $amount !== null => 'reason_ledger_named',
                    $name !== null => 'reason_ledger_named_no_amount',
                    $amount !== null => 'reason_ledger',
                    default => 'reason_ledger_no_amount',
                };

                return [
                    'type' => 'payment_due',
                    'label' => __('suchak.worklist.payment_due.label'),
                    'reason' => __('suchak.worklist.payment_due.'.$key, [
                        'name' => (string) $name,
                        'amount' => (string) $amount,
                        'date' => $date,
                    ]),
                    'due_at' => $entry->due_date?->copy()->startOfDay(),
                    'target_type' => 'suchak_ledger_entry',
                    'target_id' => $entry->id,
                    'candidate_reference' => null,
                    'action_label' => __('suchak.worklist.payment_due.action_ledger'),
                    'action_url' => route('suchak.dashboard'),
                ];
            });

        $paymentRequests = SuchakWorklistSourceQueries::paymentRequestsNeedingFollowUp($account, $at)
            ->limit(self::PER_BUCKET_LIMIT)
            ->get()
            // "Has an expiry" and "has none" are two different sentences, not one
            // sentence with an optional tail bolted on — the bolted-on tail is
            // exactly the construction that produced the half-translated line.
            ->map(function (SuchakPaymentRequest $request): array {
                $amount = MoneyFormat::amount($request->amount_due, (string) ($request->currency ?: 'INR'));
                $expires = $request->expires_at;

                $key = match (true) {
                    $amount !== null && $expires !== null => 'reason_request_expiring',
                    $amount !== null => 'reason_request',
                    $expires !== null => 'reason_request_expiring_no_amount',
                    default => 'reason_request_no_amount',
                };

                return [
                    'type' => 'payment_due',
                    'label' => __('suchak.worklist.payment_due.label'),
                    'reason' => __('suchak.worklist.payment_due.'.$key, [
                        'amount' => (string) $amount,
                        'due' => $this->moment($expires),
                    ]),
                    'due_at' => $request->expires_at,
                    'target_type' => 'suchak_payment_request',
                    'target_id' => $request->id,
                    'candidate_reference' => null,
                    'action_label' => __('suchak.worklist.payment_due.action_request'),
                    'action_url' => route('suchak.dashboard'),
                ];
            });

        // toBase() for the same reason as pipelineRisks above — this is the one
        // that actually fired on production: no due ledger entries, one payment
        // request, and the Suchak's home screen showed "Server Error".
        return $ledgerEntries->toBase()->merge($paymentRequests)
            // See dailyWorklist(): a list of callables is a list of
            // comparators, so this bucket was reversed too.
            ->sortBy(fn (array $item): array => [
                $item['due_at'] instanceof Carbon ? $item['due_at']->getTimestamp() : PHP_INT_MAX,
                (int) $item['target_id'],
            ])
            ->values()
            ->take(self::PER_BUCKET_LIMIT);
    }

    private function collaborationOpportunities(SuchakAccount $account): Collection
    {
        $ownRepresentations = SuchakProfileRepresentation::query()
            ->with(['matrimonyProfile.religion', 'matrimonyProfile.caste'])
            ->where('suchak_account_id', $account->id)
            ->withValidConsent()
            ->whereHas('matrimonyProfile', fn (Builder $query) => $this->activeProfileQuery($query))
            ->orderBy('id')
            ->get();

        if ($ownRepresentations->isEmpty()) {
            return collect();
        }

        return SuchakProfileRepresentation::query()
            ->with(['matrimonyProfile.religion', 'matrimonyProfile.caste'])
            ->publiclyRoutable()
            ->where('suchak_account_id', '!=', $account->id)
            ->whereHas('matrimonyProfile', fn (Builder $query) => $this->activeProfileQuery($query))
            ->orderBy('id')
            ->limit(30)
            ->get()
            ->map(function (SuchakProfileRepresentation $candidate) use ($account, $ownRepresentations): ?array {
                if ($this->hasOpenCollaboration($account, $candidate)) {
                    return null;
                }

                // Real engine score (SuchakMatchFitService -> MatchingService), not a caste/district guess.
                $match = $this->matchFitService->bestFitAmong($ownRepresentations, $candidate);

                if ($match === null) {
                    return null;
                }

                /** @var SuchakProfileRepresentation $ownRepresentation */
                $ownRepresentation = $match['own_representation'];

                // The side being compared is HIS OWN consented customer, so it
                // is named. The masked hash that used to sit here told him
                // nothing he could act on or repeat to a family; the OTHER
                // Suchak's candidate stays masked, in `candidate_reference`,
                // which is where the mask belongs.
                $ownName = $this->representedCustomerName($ownRepresentation);

                return [
                    'type' => 'collaboration_opportunity',
                    'label' => __('suchak.worklist.collaboration_opportunity.label'),
                    // ONE key. `fit_summary` arrives already localised from the
                    // matching engine and enters as a parameter — the sentence
                    // around it is never assembled from language fragments.
                    'reason' => $ownName === null
                        ? __('suchak.worklist.collaboration_opportunity.reason', [
                            'fit' => (string) $match['fit_summary'],
                        ])
                        : __('suchak.worklist.collaboration_opportunity.reason_named', [
                            'fit' => (string) $match['fit_summary'],
                            'name' => $ownName,
                        ]),
                    'due_at' => null,
                    'target_type' => 'suchak_profile_representation',
                    'target_id' => $candidate->id,
                    'candidate_reference' => $this->maskedCandidateReference($candidate),
                    'match_score' => $match['match_score'],
                    'fit_label' => $match['fit_label'],
                    'action_label' => __('suchak.worklist.collaboration_opportunity.action'),
                    'action_url' => route('suchak.search.index'),
                ];
            })
            ->filter()
            ->sortByDesc(fn (array $item): int => (int) ($item['match_score'] ?? 0))
            ->values()
            ->take(self::PER_BUCKET_LIMIT);
    }

    private function hasOpenCollaboration(SuchakAccount $account, SuchakProfileRepresentation $candidate): bool
    {
        return SuchakCollaborationRequest::query()
            ->whereIn('status', SuchakCollaborationRequest::OPEN_STATUSES)
            ->where(function (Builder $query) use ($account, $candidate): void {
                $query->where(function (Builder $query) use ($account, $candidate): void {
                    $query->where('requesting_suchak_account_id', $account->id)
                        ->where('target_representation_id', $candidate->id);
                })->orWhere(function (Builder $query) use ($account, $candidate): void {
                    $query->where('target_suchak_account_id', $account->id)
                        ->where('requesting_representation_id', $candidate->id);
                });
            })
            ->exists();
    }

    private function activeProfileQuery(Builder $query): Builder
    {
        return $query
            ->where('lifecycle_state', 'active')
            ->where('is_suspended', false);
    }

    /**
     * The name of one of THIS Suchak's own customers, from a representation he
     * already holds in hand.
     *
     * Not a masking decision and deliberately not routed through
     * {@see SuchakCandidateMaskingService}: that class answers "what may ANOTHER
     * Suchak see of this candidate" (D19a). Every caller here is looking at its
     * own book, where the customer list already prints the full name
     * ({@see SuchakCustomerListService::rowFromRepresentation()}), and all three
     * callers select `withValidConsent()`, so the person has agreed to be
     * represented. Masking a Suchak's own customer from himself is what made the
     * card unusable, not what made it safe.
     */
    private function representedCustomerName(SuchakProfileRepresentation $representation): ?string
    {
        return $this->customerName($representation->matrimonyProfile);
    }

    /**
     * The name behind a bare `matrimony_profile_id` on a note / ledger row /
     * pipeline — but ONLY when that profile is one of this Suchak's own
     * consented customers. A profile he merely touched (an unconsented claim,
     * another Suchak's candidate) resolves to null and the caller words the
     * sentence without a name rather than naming someone it may not name.
     */
    private function ownCustomerName(SuchakAccount $account, mixed $profileId): ?string
    {
        $profileId = is_numeric($profileId) ? (int) $profileId : null;
        if ($profileId === null) {
            return null;
        }

        $accountId = (int) $account->id;

        $this->ownCustomerNames[$accountId] ??= SuchakProfileRepresentation::query()
            ->with('matrimonyProfile')
            ->where('suchak_account_id', $accountId)
            ->withValidConsent()
            ->get()
            ->mapWithKeys(fn (SuchakProfileRepresentation $representation): array => [
                (int) $representation->matrimony_profile_id => (string) $this->customerName($representation->matrimonyProfile),
            ])
            ->filter(fn (string $name): bool => $name !== '')
            ->all();

        return $this->ownCustomerNames[$accountId][$profileId] ?? null;
    }

    private function customerName(?MatrimonyProfile $profile): ?string
    {
        $name = trim((string) ($profile?->full_name ?? ''));

        return $name !== '' ? $name : null;
    }

    /**
     * A date-and-time / a date, as text for a sentence.
     *
     * Formatted here rather than in the lang file, and with a fixed pattern
     * rather than a locale-aware one, so the frozen digits rule holds by
     * construction: `2026-08-03 10:00` is the same Latin 0-9 string under `mr`
     * as under `en`. Never null into a sentence — an unknown moment degrades to
     * the same neutral wording the rest of the Suchak surface uses.
     */
    private function moment(?Carbon $at): string
    {
        return $at?->format('Y-m-d H:i') ?? (string) __('suchak.labels.common.not_available');
    }

    private function day(?Carbon $at): string
    {
        return $at?->format('Y-m-d') ?? (string) __('suchak.labels.common.not_available');
    }

    private function maskedCandidateReference(SuchakProfileRepresentation $representation): ?string
    {
        $profile = $representation->matrimonyProfile;

        if (! $profile instanceof MatrimonyProfile) {
            return null;
        }

        $summary = $this->maskingService->maskedSummary($profile, $representation);

        return $summary['candidate_reference'] ?? null;
    }

    private function typeOrder(string $type): int
    {
        return match ($type) {
            'follow_up_due' => 10,
            'consent_expiring' => 20,
            'sla_risk' => 30,
            'payment_due' => 40,
            'pdf_missing' => 50,
            'collaboration_opportunity' => 60,
            default => 99,
        };
    }
}

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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SuchakDailyOpportunityService
{
    private const FINAL_LIMIT = 12;

    private const PER_BUCKET_LIMIT = 5;

    public function __construct(
        private readonly SuchakCandidateMaskingService $maskingService,
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
            ->sortBy([
                fn (array $item): int => $item['due_at'] instanceof Carbon
                    ? $item['due_at']->getTimestamp()
                    : PHP_INT_MAX,
                fn (array $item): int => $this->typeOrder($item['type']),
                fn (array $item): int => (int) ($item['target_id'] ?? 0),
            ])
            ->values()
            ->take(self::FINAL_LIMIT);
    }

    private function followUpsDue(SuchakAccount $account, Carbon $at): Collection
    {
        return SuchakProfileNote::query()
            ->where('suchak_account_id', $account->id)
            ->whereNotNull('follow_up_at')
            ->where('follow_up_at', '<=', $at)
            ->orderBy('follow_up_at')
            ->orderBy('id')
            ->limit(self::PER_BUCKET_LIMIT)
            ->get()
            ->map(fn (SuchakProfileNote $note): array => [
                'type' => 'follow_up_due',
                'label' => 'Follow-up due',
                'reason' => 'Follow-up is due because note #'.$note->id.' has follow_up_at '.$note->follow_up_at?->format('Y-m-d H:i').'.',
                'due_at' => $note->follow_up_at,
                'target_type' => 'suchak_profile_note',
                'target_id' => $note->id,
                'candidate_reference' => null,
                'action_label' => 'Open dashboard',
                'action_url' => route('suchak.dashboard'),
            ]);
    }

    private function consentsExpiring(SuchakAccount $account, Carbon $at): Collection
    {
        return SuchakProfileRepresentation::query()
            ->with(['matrimonyProfile.religion', 'matrimonyProfile.caste'])
            ->where('suchak_account_id', $account->id)
            ->withValidConsent()
            ->whereNotNull('consent_valid_until')
            ->whereBetween('consent_valid_until', [$at, $at->copy()->addDays(7)])
            ->whereHas('matrimonyProfile', fn (Builder $query) => $this->activeProfileQuery($query))
            ->orderBy('consent_valid_until')
            ->orderBy('id')
            ->limit(self::PER_BUCKET_LIMIT)
            ->get()
            ->map(fn (SuchakProfileRepresentation $representation): array => [
                'type' => 'consent_expiring',
                'label' => 'Consent expiring',
                'reason' => 'Consent expires on '.$representation->consent_valid_until?->format('Y-m-d H:i').', so renewal must be planned before public routing continues.',
                'due_at' => $representation->consent_valid_until,
                'target_type' => 'suchak_profile_representation',
                'target_id' => $representation->id,
                'candidate_reference' => $this->maskedCandidateReference($representation),
                'action_label' => 'Review representation',
                'action_url' => route('suchak.dashboard'),
            ]);
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
            ->map(fn (SuchakProfileRepresentation $representation): array => [
                'type' => 'pdf_missing',
                'label' => 'PDF missing',
                'reason' => 'No generated biodata PDF is attached for representation #'.$representation->id.'.',
                'due_at' => null,
                'target_type' => 'suchak_profile_representation',
                'target_id' => $representation->id,
                'candidate_reference' => $this->maskedCandidateReference($representation),
                'action_label' => 'Prepare biodata',
                'action_url' => route('suchak.dashboard'),
            ]);
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
            ->map(fn (SuchakPipeline $pipeline): array => [
                'type' => 'sla_risk',
                'label' => 'SLA risk',
                'reason' => 'Pipeline #'.$pipeline->id.' lock expires at '.$pipeline->lock_expires_at?->format('Y-m-d H:i').'.',
                'due_at' => $pipeline->lock_expires_at,
                'target_type' => 'suchak_pipeline',
                'target_id' => $pipeline->id,
                'candidate_reference' => null,
                'action_label' => 'Review request',
                'action_url' => route('suchak.dashboard'),
            ]);

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
            ->map(fn (SuchakPlatformLeadAllocation $allocation): array => [
                'type' => 'sla_risk',
                'label' => 'SLA risk',
                'reason' => 'Platform lead allocation #'.$allocation->id.' SLA expires at '.$allocation->sla_expires_at?->format('Y-m-d H:i').'.',
                'due_at' => $allocation->sla_expires_at,
                'target_type' => 'suchak_platform_lead_allocation',
                'target_id' => $allocation->id,
                'candidate_reference' => null,
                'action_label' => 'Review lead',
                'action_url' => route('suchak.dashboard'),
            ]);

        return $pipelineRisks->merge($leadAllocationRisks)
            ->sortBy([
                fn (array $item): int => $item['due_at'] instanceof Carbon ? $item['due_at']->getTimestamp() : PHP_INT_MAX,
                fn (array $item): int => (int) $item['target_id'],
            ])
            ->values()
            ->take(self::PER_BUCKET_LIMIT);
    }

    private function paymentsDue(SuchakAccount $account, Carbon $at): Collection
    {
        $ledgerEntries = SuchakLedgerEntry::query()
            ->where('suchak_account_id', $account->id)
            ->whereIn('status', [
                SuchakLedgerEntry::STATUS_DUE,
                SuchakLedgerEntry::STATUS_EXPECTED,
            ])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $at->toDateString())
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit(self::PER_BUCKET_LIMIT)
            ->get()
            ->map(fn (SuchakLedgerEntry $entry): array => [
                'type' => 'payment_due',
                'label' => 'Payment due',
                'reason' => 'Ledger entry #'.$entry->id.' is '.$entry->status.' with due_date '.$entry->due_date?->format('Y-m-d').'.',
                'due_at' => $entry->due_date?->copy()->startOfDay(),
                'target_type' => 'suchak_ledger_entry',
                'target_id' => $entry->id,
                'candidate_reference' => null,
                'action_label' => 'Review ledger',
                'action_url' => route('suchak.dashboard'),
            ]);

        $paymentRequests = SuchakPaymentRequest::query()
            ->where('suchak_account_id', $account->id)
            ->whereIn('payment_status', [
                SuchakPaymentRequest::STATUS_SENT,
                SuchakPaymentRequest::STATUS_OPENED,
                SuchakPaymentRequest::STATUS_PENDING,
                SuchakPaymentRequest::STATUS_PARTIALLY_PAID,
                SuchakPaymentRequest::STATUS_OVERDUE,
            ])
            ->where(function (Builder $query) use ($at): void {
                $query->where('payment_status', SuchakPaymentRequest::STATUS_OVERDUE)
                    ->orWhere(function (Builder $query) use ($at): void {
                        $query->whereNotNull('expires_at')
                            ->where('expires_at', '<=', $at->copy()->addDays(3));
                    });
            })
            ->orderByRaw('expires_at IS NULL')
            ->orderBy('expires_at')
            ->orderBy('id')
            ->limit(self::PER_BUCKET_LIMIT)
            ->get()
            ->map(fn (SuchakPaymentRequest $request): array => [
                'type' => 'payment_due',
                'label' => 'Payment due',
                'reason' => 'Payment request #'.$request->id.' is '.$request->payment_status.' and needs follow-up'.($request->expires_at ? ' before '.$request->expires_at->format('Y-m-d H:i').'.' : '.'),
                'due_at' => $request->expires_at,
                'target_type' => 'suchak_payment_request',
                'target_id' => $request->id,
                'candidate_reference' => null,
                'action_label' => 'Review payment request',
                'action_url' => route('suchak.dashboard'),
            ]);

        return $ledgerEntries->merge($paymentRequests)
            ->sortBy([
                fn (array $item): int => $item['due_at'] instanceof Carbon ? $item['due_at']->getTimestamp() : PHP_INT_MAX,
                fn (array $item): int => (int) $item['target_id'],
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

                $match = $this->firstDeterministicMatch($ownRepresentations, $candidate);

                if ($match === null) {
                    return null;
                }

                /** @var SuchakProfileRepresentation $ownRepresentation */
                $ownRepresentation = $match['own_representation'];

                return [
                    'type' => 'collaboration_opportunity',
                    'label' => 'Collaboration opportunity',
                    'reason' => $match['reason'].' Reference: '.$this->maskedCandidateReference($ownRepresentation).'.',
                    'due_at' => null,
                    'target_type' => 'suchak_profile_representation',
                    'target_id' => $candidate->id,
                    'candidate_reference' => $this->maskedCandidateReference($candidate),
                    'action_label' => 'Open marketplace',
                    'action_url' => route('suchak.search.index'),
                ];
            })
            ->filter()
            ->values()
            ->take(self::PER_BUCKET_LIMIT);
    }

    private function firstDeterministicMatch(Collection $ownRepresentations, SuchakProfileRepresentation $candidate): ?array
    {
        foreach ($ownRepresentations as $ownRepresentation) {
            if (! $ownRepresentation instanceof SuchakProfileRepresentation) {
                continue;
            }

            $ownProfile = $ownRepresentation->matrimonyProfile;
            $candidateProfile = $candidate->matrimonyProfile;

            if (! $ownProfile instanceof MatrimonyProfile || ! $candidateProfile instanceof MatrimonyProfile) {
                continue;
            }

            if ($ownProfile->caste_id !== null && $ownProfile->caste_id === $candidateProfile->caste_id) {
                return [
                    'own_representation' => $ownRepresentation,
                    'reason' => 'Same caste as an active Suchak representation.',
                ];
            }

            if ($ownProfile->religion_id !== null && $ownProfile->religion_id === $candidateProfile->religion_id) {
                return [
                    'own_representation' => $ownRepresentation,
                    'reason' => 'Same religion as an active Suchak representation.',
                ];
            }

            $ownDistrictId = $this->districtId($ownProfile);

            if ($ownDistrictId !== null && $ownDistrictId === $this->districtId($candidateProfile)) {
                return [
                    'own_representation' => $ownRepresentation,
                    'reason' => 'Same residence district as an active Suchak representation.',
                ];
            }
        }

        return null;
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

    private function maskedCandidateReference(SuchakProfileRepresentation $representation): ?string
    {
        $profile = $representation->matrimonyProfile;

        if (! $profile instanceof MatrimonyProfile) {
            return null;
        }

        $summary = $this->maskingService->maskedSummary($profile, $representation);

        return $summary['candidate_reference'] ?? null;
    }

    private function districtId(MatrimonyProfile $profile): ?int
    {
        $addressIds = $profile->residenceGeoAddressIds();

        return $addressIds['district_id'] ?? null;
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

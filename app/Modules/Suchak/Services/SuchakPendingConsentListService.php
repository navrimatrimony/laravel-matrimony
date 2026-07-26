<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakAccount;
use App\Models\SuchakConsent;
use App\Models\SuchakProfileRepresentation;
use App\Support\CandidateNameMask;
use App\Support\ConsentContactRole;
use App\Support\Suchak\SuchakLocalizedText;
use Illuminate\Support\Carbon;

/**
 * The Suchak's OWN pending consent claims — the exact complement of
 * SuchakCustomerListService.
 *
 * Consent-first linking (2026-07-26) made "ask to represent an existing person"
 * create a claim instead of a link. A claim is deliberately invisible in the
 * customer list, the detail endpoint, the share card and the dashboard, and
 * 403s on read AND write. Without this feed a Suchak who loses the WhatsApp
 * reply can never resend the consent — a dead end. This service is the one
 * place those claims are readable, and it exposes nothing beyond what the
 * Suchak already typed to create them: a masked name, the masked number the
 * request went to, and the consent's own lifecycle.
 */
class SuchakPendingConsentListService
{
    /**
     * @return list<array{
     *     representation_id: int,
     *     profile_id: ?int,
     *     candidate_name: string,
     *     consent_mobile: ?string,
     *     consent_id: ?int,
     *     consent_status: ?string,
     *     consent_status_label: ?string,
     *     consent_method: ?string,
     *     requested_at: ?string,
     *     expires_at: ?string,
     *     is_expired: bool,
     *     can_resend: bool,
     *     consent_link_available: false,
     * }>
     */
    public function rowsForAccount(SuchakAccount $account): array
    {
        return $account->profileRepresentations()
            ->onlyPendingConsentClaims()
            ->with(['consents', 'matrimonyProfile'])
            ->latest()
            ->get()
            ->map(fn (SuchakProfileRepresentation $representation): array => $this->row($representation))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(SuchakProfileRepresentation $representation): array
    {
        $consent = $this->latestActionableConsent($representation);
        $mobile = $consent === null
            ? null
            : trim((string) ($consent->intended_mobile ?: $consent->consent_mobile_number));
        $expiresAt = $consent?->expires_at ?? $consent?->token_expires_at;
        $isExpired = $expiresAt instanceof Carbon && $expiresAt->isPast();

        return [
            'representation_id' => (int) $representation->id,
            'profile_id' => $representation->matrimony_profile_id !== null
                ? (int) $representation->matrimony_profile_id
                : null,
            'candidate_name' => CandidateNameMask::mask(
                (string) ($representation->matrimonyProfile?->full_name ?? '')
            ),
            // Masked like every other pre-consent surface. The Suchak typed this
            // number themselves, so the visible digits are enough to confirm
            // they sent it to the right person without republishing it.
            'consent_mobile' => ($mobile === null || $mobile === '')
                ? null
                : ConsentContactRole::maskMobile($mobile),
            'consent_id' => $consent === null ? null : (int) $consent->id,
            'consent_status' => $consent?->consent_status,
            'consent_status_label' => $consent === null
                ? null
                : (SuchakLocalizedText::labelOrNull($consent->consent_status, 'consent')
                    ?? (string) __('suchak.labels.unknown')),
            'consent_method' => $consent?->consent_method,
            'requested_at' => $consent?->created_at instanceof Carbon
                ? $consent->created_at->toIso8601String()
                : null,
            'expires_at' => $expiresAt instanceof Carbon ? $expiresAt->toIso8601String() : null,
            'is_expired' => $isExpired,
            // A fresh link can only be minted through the resend endpoint, and
            // only while the consent is still awaiting the candidate.
            'can_resend' => $consent !== null,
            // Never true on a read: the raw token is hashed at rest, so no
            // usable URL exists outside the mint that created it. The app reads
            // this the same way it reads the create payload and routes through
            // resend.
            'consent_link_available' => false,
        ];
    }

    /**
     * The consent this claim is actually waiting on. Falls back to the newest
     * consent of any status so an expired/cancelled one is still shown (and
     * still resendable) rather than the row appearing to have none at all.
     */
    private function latestActionableConsent(
        SuchakProfileRepresentation $representation
    ): ?SuchakConsent {
        $consents = $representation->consents->sortByDesc('created_at');

        return $consents
            ->firstWhere(
                fn (SuchakConsent $consent): bool => in_array(
                    $consent->consent_status,
                    SuchakConsent::PENDING_ACTION_STATUSES,
                    true
                )
            )
            ?? $consents->first();
    }
}

<?php

namespace App\Modules\Suchak\Services;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakBiodataIntakeLink;
use App\Models\SuchakConsent;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakPaymentRequest;
use App\Models\SuchakProfileRepresentation;
use App\Services\ProfileCompletionService;
use App\Support\LocalizedText;
use App\Support\Suchak\SuchakLocalizedText;
use Illuminate\Support\Carbon;

class SuchakCustomerListService
{
    public function __construct(
        private readonly SuchakAccessService $accessService,
    ) {}

    /**
     * Compact rows for Suchak dashboard customer list (owned candidates + pending intakes).
     *
     * @return list<array{
     *     row_key: string,
     *     kind: 'represented'|'intake_pending',
     *     profile_id: ?int,
     *     representation_id: ?int,
     *     intake_id: ?int,
     *     source_link_id: ?int,
     *     photo_url: string,
     *     name: string,
     *     age: ?int,
     *     gender: ?string,
     *     address: string,
     *     status_label: string,
     *     consent_label: ?string,
     *     consent_status: ?string,
     *     consent_action_url: ?string,
     *     can_request_consent: bool,
     *     can_renew_consent: bool,
     *     default_consent_mobile: ?string,
     *     default_consent_giver_name: ?string,
     *     has_pending_consent: bool,
     *     has_active_consent: bool,
     *     lifecycle_label: ?string,
     *     completion_percent: int,
     *     incomplete_sections: list<string>,
     *     view_url: ?string,
     *     edit_url: ?string,
     *     manage_url: ?string,
     *     review_url: ?string,
     *     sort_at: ?\Illuminate\Support\Carbon,
     * }>
     */
    public function rowsForAccount(SuchakAccount $account): array
    {
        $canPrepareCustomers = $this->accessService->canPrepareCustomers($account);

        // Consent-first linking (2026-07-26): a matched_existing_profile claim
        // with no valid consent is NOT a customer yet — it must not surface in
        // any feed until that person accepts. Single source for the customer
        // list, the customer detail endpoint and the share card.
        $representations = $account->profileRepresentations()
            ->excludingPendingConsentClaims()
            ->with([
                'consents',
                'matrimonyProfile.gender',
                'matrimonyProfile.location.parent.parent.parent',
                'matrimonyProfile.user',
            ])
            ->latest()
            ->get();

        $paidRepIdSet = $this->paidRepresentationIdSet($account);

        $rows = $representations
            ->map(fn (SuchakProfileRepresentation $representation): array => $this->rowFromRepresentation($representation, $canPrepareCustomers, $paidRepIdSet))
            ->values();

        $representedProfileIds = $representations
            ->pluck('matrimony_profile_id')
            ->filter()
            ->map(static fn ($id) => (int) $id)
            ->all();

        $pendingIntakeLinks = SuchakBiodataIntakeLink::query()
            ->with(['biodataIntake'])
            ->where('suchak_account_id', $account->id)
            ->whereNull('matrimony_profile_id')
            ->where('source_status', '!=', SuchakBiodataIntakeLink::STATUS_CANCELLED)
            ->latest()
            ->get();

        foreach ($pendingIntakeLinks as $link) {
            $rows->push($this->rowFromIntakeLink($link));
        }

        return $rows
            ->sortByDesc(fn (array $row) => $row['sort_at']?->timestamp ?? 0)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Representation IDs the customer has paid the Suchak for (Track A). This is
     * only the Suchak's own earnings marker (Paid/Free) — it is NOT platform
     * billing and does not touch the core payment model.
     *
     * @return array<int, true>
     */
    private function paidRepresentationIdSet(SuchakAccount $account): array
    {
        $paidContextIds = SuchakPaymentRequest::query()
            ->where('suchak_account_id', $account->id)
            ->where('payment_status', SuchakPaymentRequest::STATUS_PAID)
            ->pluck('customer_context_id')
            ->filter()
            ->all();

        if ($paidContextIds === []) {
            return [];
        }

        $repIds = SuchakCustomerContext::query()
            ->whereIn('id', $paidContextIds)
            ->pluck('representation_id')
            ->filter()
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return array_fill_keys($repIds, true);
    }

    /**
     * Genders have only an English `label` column, so localize the display value
     * with a small map (same approach as the profile presenter's child gender).
     */
    private function genderLabel(?MatrimonyProfile $profile): ?string
    {
        $label = $profile?->gender?->label;
        if ($label === null || $label === '') {
            return null;
        }
        if (! LocalizedText::isMarathi()) {
            return $label;
        }

        return match (mb_strtolower(trim($label))) {
            'male' => 'पुरुष',
            'female' => 'स्त्री',
            'other' => 'इतर',
            default => $label,
        };
    }

    /**
     * Every status word this list puts on a Suchak's screen goes through here.
     *
     * It binds to the existing Suchak vocabulary
     * ({@see \App\Support\Suchak\SuchakLocalizedText}, backed by
     * `suchak.labels.*`) rather than prettifying the raw enum with
     * ucfirst()/ucwords(), which is what used to leak English words like
     * "Intake Uploaded" into an otherwise Marathi screen.
     *
     * An unmapped or brand-new enum value degrades to the neutral
     * `suchak.labels.unknown` ("स्थिती अज्ञात" / "Unknown status") — never a
     * notice, never blank, and deliberately never the raw English token, since
     * a stray English word in a Marathi list is the exact bug being fixed. The
     * gap shows up as an honest "unknown" instead of masquerading as a label.
     */
    private function statusLabel(?string $value, string $group): string
    {
        return SuchakLocalizedText::labelOrNull($value, $group)
            ?? (string) __('suchak.labels.unknown');
    }

    /**
     * @param  array<int, true>  $paidRepIdSet
     */
    private function rowFromRepresentation(SuchakProfileRepresentation $representation, bool $canPrepareCustomers, array $paidRepIdSet): array
    {
        /** @var MatrimonyProfile|null $profile */
        $profile = $representation->matrimonyProfile;
        $consents = $representation->consents->sortByDesc('created_at')->values();
        $pendingConsent = $consents
            ->first(fn (SuchakConsent $consent): bool => in_array($consent->consent_status, SuchakConsent::PENDING_ACTION_STATUSES, true));
        $acceptedConsent = $consents
            ->first(fn (SuchakConsent $consent): bool => $consent->consent_status === SuchakConsent::STATUS_ACCEPTED && $consent->revoked_at === null);
        $canRequestConsent = $canPrepareCustomers
            && $pendingConsent === null
            && $acceptedConsent === null
            && in_array($representation->representation_status, [
                SuchakProfileRepresentation::STATUS_PENDING,
                SuchakProfileRepresentation::STATUS_CONSENT_PENDING,
                SuchakProfileRepresentation::STATUS_REJECTED,
                SuchakProfileRepresentation::STATUS_EXPIRED,
                SuchakProfileRepresentation::STATUS_REVOKED,
            ], true)
            && $representation->candidate_deactivated_at === null;
        $canRenewConsent = $canPrepareCustomers
            && $pendingConsent === null
            && $acceptedConsent !== null
            && $representation->representation_status === SuchakProfileRepresentation::STATUS_ACTIVE
            && $representation->hasValidConsent();

        $completion = $this->onboardingCompletion($profile);

        return [
            'row_key' => 'rep:'.$representation->id,
            'kind' => 'represented',
            'profile_id' => $profile?->id,
            'representation_id' => (int) $representation->id,
            'intake_id' => $representation->biodata_intake_id ? (int) $representation->biodata_intake_id : null,
            'source_link_id' => null,
            'photo_url' => $profile ? (string) $profile->profile_photo_url : asset('images/placeholders/default-profile.svg'),
            'name' => trim((string) ($profile?->full_name ?? '')) ?: 'Name pending',
            'age' => $this->exactAge($profile?->date_of_birth),
            'gender' => $this->genderLabel($profile),
            'address' => $profile?->residenceLocationDisplayLine() ?: '—',
            'status_label' => $this->statusLabel($representation->representation_status, 'representation'),
            'consent_label' => $this->statusLabel($representation->consent_status, 'consent'),
            'consent_status' => (string) $representation->consent_status,
            'consent_action_url' => $canRenewConsent
                ? route('suchak.representations.consents.renew', $representation)
                : ($canRequestConsent ? route('suchak.representations.consents.request', $representation) : null),
            'can_request_consent' => $canRequestConsent,
            'can_renew_consent' => $canRenewConsent,
            'default_consent_mobile' => $profile?->primary_contact_number,
            'default_consent_giver_name' => $profile?->full_name,
            'has_pending_consent' => $pendingConsent !== null,
            'pending_consent_id' => $pendingConsent?->id,
            'has_active_consent' => $acceptedConsent !== null && $representation->hasValidConsent(),
            'lifecycle_label' => $profile ? $this->statusLabel($profile->lifecycle_state, 'lifecycle') : null,
            // Suchak-only Track A earnings marker (Paid = customer paid the
            // Suchak; unrelated to platform billing / the core payment model).
            'paid' => isset($paidRepIdSet[(int) $representation->id]),
            // Both come out of ONE evaluation of ONBOARDING_SECTIONS, so the
            // number and the section list can never contradict each other:
            // 100% means exactly "no incomplete sections". See
            // onboardingCompletion().
            'completion_percent' => $completion['percent'],
            'incomplete_sections' => $completion['incomplete'],
            'view_url' => $profile ? route('matrimony.profile.show', $profile) : null,
            'edit_url' => route('suchak.representations.profile-form', $representation),
            'manage_url' => route('suchak.dashboard', [
                'dashboard_tab' => 'profiles',
                'manage_representation' => $representation->id,
            ]).'#customer-management',
            'review_url' => null,
            'sort_at' => $representation->created_at,
        ];
    }

    /**
     * The profile sections the Suchak onboarding wizard actually collects —
     * the single definition of "done" for this list. Both `completion_percent`
     * and `incomplete_sections` are derived from it (see
     * onboardingCompletion()), so the bar and the section list always agree.
     *
     * Deliberately NOT every section: most of ProfileCompletionService::SECTIONS
     * carry weight 0 (siblings, relatives, alliance, property, horoscope,
     * about-me) and are normally empty for a perfectly good candidate. Treating
     * those as "unfinished" would brand every profile incomplete forever, which
     * is noise, not a signal. "Incomplete" here means the onboarding run itself
     * was abandoned.
     */
    public const ONBOARDING_SECTIONS = [
        'basic-info',
        'physical',
        'education-career',
        'about-preferences',
        'photo',
    ];

    /**
     * The ONE completeness reading behind this list: the percentage AND the
     * sections still missing, both derived from the same pass over
     * ONBOARDING_SECTIONS.
     *
     * Why not ProfileCompletionService::calculateCompletionPercentage() (PO
     * reversal 2026-07-26): it scores a DIFFERENT set — five 20% buckets of
     * basic-info / personal-family / location / about-preferences / photo. So
     * `physical` and `education-career` decided "incomplete" while carrying
     * zero weight (a profile missing only height read 100% and incomplete at
     * the same time), and `location` carried 20% while never being checked
     * (80% with nothing flagged). The Suchak saw two numbers disagreeing about
     * one profile. That shared method is still used by the represented-profile
     * detail endpoint, which shows the member-side section list and must keep
     * the member-side meaning, so it is left alone rather than redefined
     * globally — this list computes its own number from the section list it
     * already owns, and nothing computes completeness twice.
     *
     * The sections come back in wizard order so the app can send the Suchak
     * back to the first one.
     *
     * @return array{percent: int, incomplete: list<string>}
     */
    private function onboardingCompletion(?MatrimonyProfile $profile): array
    {
        // No profile = nothing to score. This stays a non-null 0 rather than
        // becoming null (considered and rejected 2026-07-26): `completion_percent`
        // is a shipped, non-nullable contract key, and the installed app reads it
        // as `(json['completion_percent'] as num?)?.toInt() ?? 100` — sending null
        // would make every already-installed app render a scanned biodata as
        // "100% पूर्ण", turning a wrong number into a much more convincing wrong
        // number. The honest signal for "this is not a customer yet" already
        // exists in the same row (`kind: 'intake_pending'` / `profile_id: null`),
        // so the client branches on that and no second signal is invented.
        if ($profile === null) {
            return ['percent' => 0, 'incomplete' => []];
        }

        $statuses = ProfileCompletionService::getSectionStatuses(
            $profile,
            self::ONBOARDING_SECTIONS
        );

        $incomplete = [];
        foreach (self::ONBOARDING_SECTIONS as $key) {
            if (($statuses[$key] ?? 'incomplete') !== 'completed') {
                $incomplete[] = $key;
            }
        }

        $total = count(self::ONBOARDING_SECTIONS);
        $done = $total - count($incomplete);

        // 100 is reserved for "nothing left", and floor() keeps a single
        // missing section out of rounding up into it however long the section
        // list grows. The invariant is structural, not arithmetic luck.
        $percent = $incomplete === []
            ? 100
            : ($total > 0 ? (int) floor($done / $total * 100) : 0);

        return ['percent' => $percent, 'incomplete' => $incomplete];
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFromIntakeLink(SuchakBiodataIntakeLink $link): array
    {
        $intake = $link->biodataIntake;
        $core = is_array($intake?->parsed_json) ? ($intake->parsed_json['core'] ?? []) : [];
        if (! is_array($core)) {
            $core = [];
        }

        $genderKey = trim((string) ($core['gender'] ?? ''));
        $genderLabel = $genderKey !== '' ? ucfirst($genderKey) : null;
        $addressLine = $this->intakeAddressLine($intake?->parsed_json ?? []);

        return [
            'row_key' => 'intake:'.$link->id,
            'kind' => 'intake_pending',
            'profile_id' => null,
            'representation_id' => null,
            'intake_id' => $intake?->id ? (int) $intake->id : null,
            'source_link_id' => (int) $link->id,
            'photo_url' => $this->placeholderPhotoForGender($genderKey),
            'name' => trim((string) ($core['full_name'] ?? '')) ?: 'Biodata review pending',
            'age' => $this->exactAge($core['date_of_birth'] ?? null),
            'gender' => $genderLabel,
            'address' => $addressLine !== '' ? $addressLine : '—',
            'status_label' => $this->statusLabel($link->source_status, 'intake_source'),
            'consent_label' => null,
            'consent_status' => null,
            'consent_action_url' => null,
            'can_request_consent' => false,
            'can_renew_consent' => false,
            'default_consent_mobile' => null,
            'default_consent_giver_name' => null,
            'has_pending_consent' => false,
            'has_active_consent' => false,
            'lifecycle_label' => $intake ? $this->statusLabel($intake->parse_status, 'intake_parse') : null,
            'view_url' => null,
            'edit_url' => null,
            'manage_url' => null,
            'review_url' => $intake ? route('intake.status', $intake) : null,
            'sort_at' => $link->created_at,
        ];
    }

    private function exactAge(mixed $dateOfBirth): ?int
    {
        if ($dateOfBirth === null || $dateOfBirth === '') {
            return null;
        }

        try {
            return Carbon::parse($dateOfBirth)->age;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function intakeAddressLine(array $parsed): string
    {
        $addresses = $parsed['addresses'] ?? [];
        if (! is_array($addresses) || $addresses === []) {
            return '';
        }

        $first = $addresses[0] ?? null;
        if (! is_array($first)) {
            return '';
        }

        $line = trim((string) ($first['address_line'] ?? $first['raw'] ?? ''));
        if ($line !== '') {
            return $line;
        }

        foreach (['location_text', 'city', 'place', 'village'] as $key) {
            $value = trim((string) ($first[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function placeholderPhotoForGender(string $genderKey): string
    {
        return match ($genderKey) {
            'male' => asset('images/placeholders/male-profile.svg'),
            'female' => asset('images/placeholders/female-profile.svg'),
            default => asset('images/placeholders/default-profile.svg'),
        };
    }
}

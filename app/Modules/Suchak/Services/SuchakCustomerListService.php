<?php

namespace App\Modules\Suchak\Services;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakBiodataIntakeLink;
use App\Models\SuchakConsent;
use App\Models\SuchakProfileRepresentation;
use App\Services\ProfileCompletionService;
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

        $representations = $account->profileRepresentations()
            ->with([
                'consents',
                'matrimonyProfile.gender',
                'matrimonyProfile.location.parent.parent.parent',
                'matrimonyProfile.user',
            ])
            ->latest()
            ->get();

        $rows = $representations
            ->map(fn (SuchakProfileRepresentation $representation): array => $this->rowFromRepresentation($representation, $canPrepareCustomers))
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
    private function rowFromRepresentation(SuchakProfileRepresentation $representation, bool $canPrepareCustomers): array
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
            'gender' => $profile?->gender?->label,
            'address' => $profile?->residenceLocationDisplayLine() ?: '—',
            'status_label' => ucfirst(str_replace('_', ' ', (string) $representation->representation_status)),
            'consent_label' => ucfirst(str_replace('_', ' ', (string) $representation->consent_status)),
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
            'lifecycle_label' => $profile ? ucfirst((string) ($profile->lifecycle_state ?? 'unknown')) : null,
            // So the app can mark a half-finished profile in the list and send the
            // Suchak back into onboarding at the section they stopped at, rather
            // than opening the edit hub. Same ProfileCompletionService the detail
            // endpoint uses — completeness is not recalculated here.
            'completion_percent' => $profile
                ? ProfileCompletionService::calculateCompletionPercentage($profile)
                : 0,
            'incomplete_sections' => $profile ? $this->incompleteSections($profile) : [],
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
     * Section keys that are not yet complete, in the order the profile defines
     * them — so the app can resume onboarding at the first one still missing.
     *
     * @return list<string>
     */
    private function incompleteSections(MatrimonyProfile $profile): array
    {
        $statuses = ProfileCompletionService::getSectionStatuses(
            $profile,
            array_keys(ProfileCompletionService::SECTIONS)
        );

        $incomplete = [];
        foreach ($statuses as $key => $status) {
            if ($status !== 'completed') {
                $incomplete[] = $key;
            }
        }

        return $incomplete;
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
            'status_label' => ucwords(str_replace('_', ' ', (string) $link->source_status)),
            'consent_label' => null,
            'consent_status' => null,
            'consent_action_url' => null,
            'can_request_consent' => false,
            'can_renew_consent' => false,
            'default_consent_mobile' => null,
            'default_consent_giver_name' => null,
            'has_pending_consent' => false,
            'has_active_consent' => false,
            'lifecycle_label' => $intake ? ucwords(str_replace('_', ' ', (string) $intake->parse_status)) : null,
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

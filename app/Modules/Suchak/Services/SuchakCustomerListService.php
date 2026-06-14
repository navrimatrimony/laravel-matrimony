<?php

namespace App\Modules\Suchak\Services;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakBiodataIntakeLink;
use App\Models\SuchakProfileRepresentation;
use Illuminate\Support\Carbon;

class SuchakCustomerListService
{
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
     *     lifecycle_label: ?string,
     *     view_url: ?string,
     *     manage_url: ?string,
     *     review_url: ?string,
     *     sort_at: ?\Illuminate\Support\Carbon,
     * }>
     */
    public function rowsForAccount(SuchakAccount $account): array
    {
        $representations = $account->profileRepresentations()
            ->with([
                'matrimonyProfile.gender',
                'matrimonyProfile.location.parent.parent.parent',
            ])
            ->latest()
            ->get();

        $rows = $representations
            ->map(fn (SuchakProfileRepresentation $representation): array => $this->rowFromRepresentation($representation))
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
    private function rowFromRepresentation(SuchakProfileRepresentation $representation): array
    {
        /** @var MatrimonyProfile|null $profile */
        $profile = $representation->matrimonyProfile;

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
            'lifecycle_label' => $profile ? ucfirst((string) ($profile->lifecycle_state ?? 'unknown')) : null,
            'view_url' => $profile ? route('matrimony.profile.show', $profile) : null,
            'manage_url' => route('suchak.representations.profile-form', $representation),
            'review_url' => null,
            'sort_at' => $representation->created_at,
        ];
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
            'lifecycle_label' => $intake ? ucwords(str_replace('_', ' ', (string) $intake->parse_status)) : null,
            'view_url' => null,
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

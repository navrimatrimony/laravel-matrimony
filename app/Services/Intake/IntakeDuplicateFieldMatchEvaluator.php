<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;

class IntakeDuplicateFieldMatchEvaluator
{
    /**
     * @return array<string, mixed>
     */
    public function evaluate(BiodataIntake $current, BiodataIntake $reference): array
    {
        $currentName = $this->candidateName($current);
        $referenceName = $this->candidateName($reference);
        $currentDob = $this->dateOfBirth($current);
        $referenceDob = $this->dateOfBirth($reference);
        $currentContact = $this->primaryContact($current);
        $referenceContact = $this->primaryContact($reference);
        $educationMatch = $this->matchLabel(
            $this->normalizeText($this->education($current)),
            $this->normalizeText($this->education($reference))
        );
        $addressMatch = $this->matchLabel(
            $this->addressPresent($current) ? 'present' : null,
            $this->addressPresent($reference) ? 'present' : null
        );

        $nameMatch = $this->matchLabel($this->normalizeName($currentName), $this->normalizeName($referenceName));
        $dobMatch = $this->matchLabel($this->normalizeDate($currentDob), $this->normalizeDate($referenceDob));
        $contactMatch = $this->matchLabel($this->normalizeContact($currentContact), $this->normalizeContact($referenceContact));
        $mismatchCodes = [];

        if ($contactMatch === 'no') {
            $mismatchCodes[] = 'contact_mismatch';
        }

        if ($dobMatch === 'no') {
            $mismatchCodes[] = 'dob_mismatch';
        }

        if ($nameMatch === 'no') {
            $mismatchCodes[] = 'name_mismatch';
        }

        if ($this->present($currentDob) && ! $this->present($referenceDob)) {
            $mismatchCodes[] = 'reference_missing_dob';
        }

        if ($this->present($currentName) && ! $this->present($referenceName)) {
            $mismatchCodes[] = 'reference_missing_name';
        }

        $identityMatches = count(array_filter([$nameMatch, $dobMatch, $contactMatch], static fn (string $match): bool => $match === 'yes'));
        $identityCompared = count(array_filter([$nameMatch, $dobMatch, $contactMatch], static fn (string $match): bool => $match !== 'unknown'));
        $weakMatches = count(array_filter([$educationMatch, $addressMatch], static fn (string $match): bool => $match === 'yes'));

        if ($identityMatches < 2) {
            $mismatchCodes[] = 'insufficient_identity_overlap';
        }

        if ($identityMatches === 0 && $weakMatches > 0) {
            $mismatchCodes[] = 'only_weak_fields_match';
        }

        $mismatchCodes = array_values(array_unique($mismatchCodes));

        return [
            'duplicate_field_match_eligible' => $mismatchCodes === [],
            'duplicate_field_match_score' => round($identityMatches / 3, 4),
            'duplicate_field_mismatch_codes' => $mismatchCodes,
            'current_reference_contact_match' => $contactMatch,
            'current_reference_dob_match' => $dobMatch,
            'current_reference_name_match' => $nameMatch,
            'current_reference_core_fields_compared' => $identityCompared,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function emptyEvaluation(): array
    {
        return [
            'duplicate_field_match_eligible' => false,
            'duplicate_field_match_score' => 0.0,
            'duplicate_field_mismatch_codes' => [],
            'current_reference_contact_match' => 'unknown',
            'current_reference_dob_match' => 'unknown',
            'current_reference_name_match' => 'unknown',
            'current_reference_core_fields_compared' => 0,
        ];
    }

    private function candidateName(BiodataIntake $intake): mixed
    {
        $data = $this->snapshotData($intake);

        return data_get($data, 'core.full_name')
            ?? data_get($data, 'core.name')
            ?? data_get($data, 'candidate.full_name')
            ?? data_get($data, 'candidate.name');
    }

    private function dateOfBirth(BiodataIntake $intake): mixed
    {
        $data = $this->snapshotData($intake);

        return data_get($data, 'core.date_of_birth')
            ?? data_get($data, 'core.dob')
            ?? data_get($data, 'candidate.date_of_birth')
            ?? data_get($data, 'candidate.dob');
    }

    private function primaryContact(BiodataIntake $intake): mixed
    {
        $data = $this->snapshotData($intake);
        $coreContact = data_get($data, 'core.primary_contact_number')
            ?? data_get($data, 'core.phone_number')
            ?? data_get($data, 'core.mobile_number')
            ?? data_get($data, 'candidate.primary_contact_number');

        if ($this->present($coreContact)) {
            return $coreContact;
        }

        $contacts = data_get($data, 'contacts');
        if (! is_array($contacts)) {
            return null;
        }

        $firstContact = null;
        foreach ($contacts as $contact) {
            if (! is_array($contact)) {
                continue;
            }

            $candidate = $contact['phone_number'] ?? $contact['mobile_number'] ?? $contact['phone'] ?? null;
            if ($firstContact === null && $candidate !== null) {
                $firstContact = $candidate;
            }

            if (! empty($contact['is_primary']) && $candidate !== null) {
                return $candidate;
            }
        }

        return $firstContact;
    }

    private function education(BiodataIntake $intake): mixed
    {
        $data = $this->snapshotData($intake);

        return data_get($data, 'core.highest_education')
            ?? data_get($data, 'core.education')
            ?? data_get($data, 'core.education_level')
            ?? data_get($data, 'education_history.0.degree')
            ?? data_get($data, 'education_history.0.qualification')
            ?? data_get($data, 'education_history.0.course');
    }

    private function addressPresent(BiodataIntake $intake): bool
    {
        $data = $this->snapshotData($intake);
        $addresses = data_get($data, 'addresses');
        if (is_array($addresses) && $addresses !== []) {
            return true;
        }

        foreach ([
            'core.address',
            'core.current_address',
            'core.permanent_address',
            'core.native_place',
            'core.city',
            'core.current_city',
        ] as $key) {
            $value = data_get($data, $key);
            if ($this->present($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotData(BiodataIntake $intake): array
    {
        $approval = is_array($intake->approval_snapshot_json) ? $intake->approval_snapshot_json : [];
        if ($approval !== []) {
            return $approval;
        }

        return is_array($intake->parsed_json) ? $intake->parsed_json : [];
    }

    private function matchLabel(?string $current, ?string $reference): string
    {
        if ($current === null && $reference === null) {
            return 'unknown';
        }

        if ($current === null || $reference === null) {
            return 'unknown';
        }

        return $current === $reference ? 'yes' : 'no';
    }

    private function normalizeName(mixed $value): ?string
    {
        $value = $this->normalizeText($value);
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value) !== '' ? trim($value) : null;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $value, $matches)) {
            return sprintf('%04d%02d%02d', (int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }

        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $value, $matches)) {
            return sprintf('%04d%02d%02d', (int) $matches[3], (int) $matches[2], (int) $matches[1]);
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return $digits !== '' ? $digits : null;
    }

    private function normalizeContact(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        if ($digits === '') {
            return null;
        }

        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return null;
        }

        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value) !== '' ? trim($value) : null;
    }

    private function present(mixed $value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '';
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-5: Duplicate detection from intake snapshot.
 * Does NOT create profile, write history, or change lifecycle.
 * Returns structured DuplicateResult only.
 */
class DuplicateDetectionService
{
    /**
     * Detect duplicate from approval/parsed snapshot.
     *
     * @param  array<string, mixed>  $snapshot  Snapshot (e.g. approval_snapshot_json: core, contacts, ...)
     * @param  int|null  $uploadedByUserId  Current user (for SAME_USER check); null to skip level 1
     * @return DuplicateResult
     */
    public function detectFromSnapshot(array $snapshot, ?int $uploadedByUserId = null): DuplicateResult
    {
        $core = $snapshot['core'] ?? [];
        $contacts = $snapshot['contacts'] ?? [];
        $primaryPhone = $this->normalize($this->getPrimaryContactFromSnapshot($core, $contacts));
        $fullName = $this->normalize($core['full_name'] ?? null);
        $dateOfBirth = $this->normalize($core['date_of_birth'] ?? null);
        $fatherName = $this->normalize($core['father_name'] ?? null);
        $districtId = isset($core['district_id']) ? (int) $core['district_id'] : null;
        $caste = $this->normalize($core['caste'] ?? null);
        $seriousIntentId = isset($core['serious_intent_id']) ? (int) $core['serious_intent_id'] : null;

        // 1) verified_otp_mobile / primary phone exact → SAME USER (same user's profile already has this phone)
        if ($primaryPhone !== null && $uploadedByUserId !== null) {
            $existingProfileId = $this->findProfileIdByPrimaryPhone($primaryPhone);
            if ($existingProfileId !== null) {
                $profileUserId = DB::table('matrimony_profiles')->where('id', $existingProfileId)->value('user_id');
                if ((int) $profileUserId === (int) $uploadedByUserId) {
                    return new DuplicateResult(
                        true,
                        DuplicateResult::TYPE_SAME_USER,
                        $existingProfileId,
                        'Primary contact already linked to your account (verified_otp_mobile / same user).'
                    );
                }
            }
        }

        // 2) primary_contact_number + full_name + date_of_birth exact → HARD DUPLICATE
        if ($primaryPhone !== null && $fullName !== null && $dateOfBirth !== null) {
            $existingProfileId = $this->findProfileIdByPrimaryPhoneFullNameDob($primaryPhone, $fullName, $dateOfBirth);
            if ($existingProfileId !== null) {
                return new DuplicateResult(
                    true,
                    DuplicateResult::TYPE_HARD_DUPLICATE,
                    $existingProfileId,
                    'Another profile exists with same primary contact, full name, and date of birth.'
                );
            }
        }

        // 3) full_name + date_of_birth + father_name + district_id + caste → HIGH PROBABILITY
        if ($fullName !== null && $dateOfBirth !== null && $districtId !== null && $caste !== null) {
            $existingProfileId = $this->findProfileIdByComposite($fullName, $dateOfBirth, $fatherName, $districtId, $caste);
            if ($existingProfileId !== null) {
                return new DuplicateResult(
                    true,
                    DuplicateResult::TYPE_HIGH_PROBABILITY,
                    $existingProfileId,
                    'High probability duplicate: full_name + date_of_birth + district_id + caste (and father_name when available) match existing profile.'
                );
            }
        }

        // 4) serious_intent_id exact match → HIGH-RISK
        if ($seriousIntentId !== null) {
            $existingProfileId = $this->findProfileIdBySeriousIntentId($seriousIntentId);
            if ($existingProfileId !== null) {
                return new DuplicateResult(
                    true,
                    DuplicateResult::TYPE_HIGH_RISK,
                    $existingProfileId,
                    'Another profile is already linked to this serious intent.'
                );
            }
        }

        return DuplicateResult::notDuplicate();
    }

    private function getPrimaryContactFromSnapshot(array $core, array $contacts): ?string
    {
        if (!empty($core['primary_contact_number'])) {
            return $core['primary_contact_number'];
        }
        if (!empty($core['verified_otp_mobile'])) {
            return $core['verified_otp_mobile'];
        }
        foreach ($contacts as $c) {
            if (is_array($c) && !empty($c['is_primary']) && !empty($c['phone_number'])) {
                return $c['phone_number'];
            }
        }
        return null;
    }

    private function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = is_string($value) ? trim($value) : (string) $value;
        return $s === '' ? null : $s;
    }

    private function findProfileIdByPrimaryPhone(string $phone): ?int
    {
        $profileId = DB::table('profile_contacts')
            ->where('phone_number', $phone)
            ->where('is_primary', true)
            ->value('profile_id');
        return $profileId !== null ? (int) $profileId : null;
    }

    private function findProfileIdByPrimaryPhoneFullNameDob(string $phone, string $fullName, string $dateOfBirth): ?int
    {
        $profileIdsFromPhone = $this->profileIdsWithPrimaryPhone($phone);
        if ($profileIdsFromPhone->isEmpty()) {
            return null;
        }
        $match = DB::table('matrimony_profiles')
            ->whereIn('id', $profileIdsFromPhone)
            ->where('full_name', $fullName)
            ->where('date_of_birth', $dateOfBirth)
            ->value('id');
        return $match !== null ? (int) $match : null;
    }

    private function profileIdsWithPrimaryPhone(string $phone): \Illuminate\Support\Collection
    {
        return DB::table('profile_contacts')
            ->where('phone_number', $phone)
            ->where('is_primary', true)
            ->pluck('profile_id')
            ->unique()
            ->filter();
    }

    private function findProfileIdByComposite(string $fullName, string $dateOfBirth, ?string $fatherName, int $districtId, string $caste): ?int
    {
        $query = DB::table('matrimony_profiles')
            ->where('full_name', $fullName)
            ->where('date_of_birth', $dateOfBirth)
            ->where('district_id', $districtId)
            ->where('caste', $caste);
        if ($fatherName !== null && Schema::hasColumn('matrimony_profiles', 'father_name')) {
            $query->where('father_name', $fatherName);
        }
        $id = $query->value('id');
        return $id !== null ? (int) $id : null;
    }

    private function findProfileIdBySeriousIntentId(int $seriousIntentId): ?int
    {
        $id = DB::table('matrimony_profiles')
            ->where('serious_intent_id', $seriousIntentId)
            ->value('id');
        return $id !== null ? (int) $id : null;
    }
}

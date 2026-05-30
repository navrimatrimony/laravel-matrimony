<?php

declare(strict_types=1);

namespace App\Services\Intake;

use App\Models\MatrimonyProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Self-address rows for intake preview (तुमचे पत्ते) — profile SSOT, plus biodata rows for missing types.
 */
final class IntakePreviewSelfAddressRows
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $parsed
     * @return array<int, array<string, mixed>>
     */
    public function rows(?MatrimonyProfile $profile, array $snapshot, array $parsed): array
    {
        if ($profile instanceof MatrimonyProfile) {
            $fromProfile = $this->rowsFromProfile($profile);
            if ($fromProfile !== []) {
                return $this->appendMissingBiodataSelfRows($fromProfile, $snapshot, $parsed);
            }
            $legacy = $this->legacySelfRowFromProfileColumns($profile);
            if ($legacy !== null) {
                return $this->appendMissingBiodataSelfRows([$legacy], $snapshot, $parsed);
            }
        }

        $fromSnapshot = $this->rowsFromIntakeAddresses($snapshot, $parsed);
        if ($fromSnapshot !== []) {
            return $this->appendMissingBiodataSelfRows($fromSnapshot, $snapshot, $parsed);
        }

        $core = is_array($snapshot['core'] ?? null) ? $snapshot['core'] : [];
        if ($core === []) {
            $core = is_array($parsed['core'] ?? null) ? $parsed['core'] : [];
        }

        $locationId = (int) ($core['location_id'] ?? $core['city_id'] ?? 0);
        $line = trim((string) ($core['address_line'] ?? ''));
        if ($locationId > 0 || $line !== '') {
            $disp = $locationId > 0
                ? MatrimonyProfile::residenceLocationDisplayLineFor((object) ['location_id' => $locationId])
                : '';

            return $this->appendMissingBiodataSelfRows([[
                'id' => null,
                'address_type_key' => 'current',
                'address_line' => $line,
                'location_id' => $locationId > 0 ? (string) $locationId : '',
                'display' => $disp,
                'rid' => 'intake-self-core',
            ]], $snapshot, $parsed);
        }

        return $this->appendMissingBiodataSelfRows([[
            'id' => null,
            'address_type_key' => 'current',
            'address_line' => '',
            'location_id' => '',
            'display' => '',
            'rid' => 'intake-self-0',
        ]], $snapshot, $parsed);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rowsFromProfile(MatrimonyProfile $profile): array
    {
        if (! $profile->exists || ! $profile->getKey()) {
            return [];
        }

        if (! Schema::hasTable('profile_addresses') || ! Schema::hasColumn('profile_addresses', 'address_scope')) {
            $legacy = $this->legacySelfRowFromProfileColumns($profile);

            return $legacy !== null ? [$legacy] : [];
        }

        $out = [];
        $rows = DB::table('profile_addresses as pa')
            ->join('master_address_types as mat', 'mat.id', '=', 'pa.address_type_id')
            ->where('pa.profile_id', $profile->id)
            ->where('pa.address_scope', 'self')
            ->orderBy('pa.id')
            ->select('pa.id', 'pa.address_line', 'pa.location_id', 'mat.key as address_type_key')
            ->get();

        foreach ($rows as $r) {
            $leaf = (int) ($r->location_id ?? 0);
            $lid = $leaf > 0 ? (string) $leaf : '';
            $disp = $leaf > 0
                ? MatrimonyProfile::residenceLocationDisplayLineFor((object) ['location_id' => $leaf])
                : '';
            $out[] = [
                'id' => (int) $r->id,
                'address_type_key' => (string) ($r->address_type_key ?? 'current'),
                'address_line' => (string) ($r->address_line ?? ''),
                'location_id' => $lid,
                'display' => $disp,
                'rid' => 'db-'.$r->id,
            ];
        }

        if ($out === []) {
            $legacy = $this->legacySelfRowFromProfileColumns($profile);

            return $legacy !== null ? [$legacy] : [];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function legacySelfRowFromProfileColumns(MatrimonyProfile $profile): ?array
    {
        if (! ($profile->location_id ?? null) && trim((string) ($profile->address_line ?? '')) === '') {
            return null;
        }

        return [
            'id' => null,
            'address_type_key' => 'current',
            'address_line' => (string) ($profile->address_line ?? ''),
            'location_id' => $profile->location_id ? (string) $profile->location_id : '',
            'display' => MatrimonyProfile::residenceLocationDisplayLineFor($profile),
            'rid' => 'legacy-self',
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $parsed
     * @return array<int, array<string, mixed>>
     */
    private function rowsFromIntakeAddresses(array $snapshot, array $parsed): array
    {
        $out = [];
        foreach ($this->intakeAddressList($snapshot, $parsed) as $i => $addr) {
            $line = trim((string) ($addr['address_line'] ?? $addr['raw'] ?? $addr['city'] ?? ''));
            $lid = (int) ($addr['location_id'] ?? $addr['city_id'] ?? 0);
            if ($line === '' && $lid < 1) {
                continue;
            }
            $typeKey = $this->normalizeAddressTypeKey((string) ($addr['type'] ?? 'current'));
            $out[] = [
                'id' => null,
                'address_type_key' => $typeKey,
                'address_line' => $line,
                'location_id' => $lid > 0 ? (string) $lid : '',
                'display' => $lid > 0
                    ? MatrimonyProfile::residenceLocationDisplayLineFor((object) ['location_id' => $lid])
                    : '',
                'rid' => 'intake-self-'.$i,
            ];
        }

        return $out;
    }

    /**
     * Append biodata address types not on the profile, or mark rows where biodata place/line differs.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $parsed
     * @return array<int, array<string, mixed>>
     */
    private function appendMissingBiodataSelfRows(array $rows, array $snapshot, array $parsed): array
    {
        foreach ($this->intakeAddressList($snapshot, $parsed) as $addr) {
            if (! is_array($addr)) {
                continue;
            }
            $typeKey = $this->normalizeAddressTypeKey((string) ($addr['type'] ?? 'current'));
            if (! in_array($typeKey, ['current', 'permanent', 'native', 'work', 'other'], true)) {
                continue;
            }

            $line = trim((string) ($addr['address_line'] ?? $addr['raw'] ?? $addr['city'] ?? ''));
            $lid = (int) ($addr['location_id'] ?? $addr['city_id'] ?? 0);
            if ($line === '' && $lid < 1) {
                continue;
            }

            $idx = $this->findRowIndexByType($rows, $typeKey);
            if ($idx !== null) {
                if (! $this->selfRowMatchesIntake($rows[$idx], $line, $lid)) {
                    $rows[$idx]['biodata_intake_line'] = $line;
                    if ($lid > 0) {
                        $rows[$idx]['biodata_intake_location_id'] = (string) $lid;
                    }
                }

                continue;
            }

            $rows[] = [
                'id' => null,
                'address_type_key' => $typeKey,
                'address_line' => $line,
                'location_id' => $lid > 0 ? (string) $lid : '',
                'display' => $lid > 0
                    ? MatrimonyProfile::residenceLocationDisplayLineFor((object) ['location_id' => $lid])
                    : '',
                'rid' => 'biodata-self-'.count($rows),
                'from_biodata' => true,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $parsed
     * @return list<array<string, mixed>>
     */
    public function intakeAddressList(array $snapshot, array $parsed): array
    {
        $addresses = is_array($snapshot['addresses'] ?? null) ? $snapshot['addresses'] : [];
        if ($addresses === []) {
            $addresses = is_array($parsed['addresses'] ?? null) ? $parsed['addresses'] : [];
        }

        $out = [];
        foreach ($addresses as $addr) {
            if (is_array($addr)) {
                $out[] = $addr;
            }
        }

        $core = is_array($parsed['core'] ?? null) ? $parsed['core'] : [];
        if ($core === []) {
            $core = is_array($snapshot['core'] ?? null) ? $snapshot['core'] : [];
        }
        $native = trim((string) ($core['native_place'] ?? ''));
        if ($native !== '' && ! $this->listContainsType($out, 'native')) {
            $out[] = ['address_line' => $native, 'raw' => $native, 'type' => 'native'];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $list
     */
    private function listContainsType(array $list, string $typeKey): bool
    {
        foreach ($list as $addr) {
            if ($this->normalizeAddressTypeKey((string) ($addr['type'] ?? '')) === $typeKey) {
                return true;
            }
        }

        return false;
    }

    private function normalizeAddressTypeKey(string $type): string
    {
        $t = strtolower(trim($type));

        return match ($t) {
            'residential', 'permanent', 'कायमचे' => 'permanent',
            'native', 'मूळ' => 'native',
            'work', 'काम' => 'work',
            'other', 'इतर' => 'other',
            'current', 'सध्याचे' => 'current',
            default => in_array($t, ['current', 'permanent', 'native', 'work', 'other'], true) ? $t : 'current',
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function findRowIndexByType(array $rows, string $typeKey): ?int
    {
        foreach ($rows as $i => $row) {
            if (($row['address_type_key'] ?? '') === $typeKey) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function selfRowMatchesIntake(array $row, string $intakeLine, int $intakeLocationId): bool
    {
        $profileLine = $this->normalizeCompareText((string) ($row['address_line'] ?? ''));
        $intakeNorm = $this->normalizeCompareText($intakeLine);
        $profileLid = (int) ($row['location_id'] ?? 0);

        if ($profileLid > 0 && $intakeLocationId > 0) {
            return $profileLid === $intakeLocationId
                && ($intakeNorm === '' || $profileLine === '' || $profileLine === $intakeNorm);
        }

        if ($intakeNorm === '') {
            return false;
        }

        return $profileLine !== '' && $profileLine === $intakeNorm;
    }

    private function normalizeCompareText(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? ''));
    }
}

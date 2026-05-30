<?php

declare(strict_types=1);

namespace App\Services\Intake;

use App\Models\MatrimonyProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parents-address rows for intake preview — profile SSOT, then biodata {@code parents_addresses} (not self {@code addresses[]}).
 */
final class IntakePreviewParentsAddressRows
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
                return $fromProfile;
            }
        }

        $fromParents = $this->rowsFromParentsAddresses($snapshot, $parsed);
        if ($fromParents !== []) {
            return $fromParents;
        }

        return [[
            'id' => null,
            'address_type_key' => 'permanent',
            'address_line' => '',
            'location_id' => '',
            'display' => '',
            'rid' => 'intake-addr-0',
        ]];
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
            return [];
        }

        $out = [];
        $rows = DB::table('profile_addresses as pa')
            ->join('master_address_types as mat', 'mat.id', '=', 'pa.address_type_id')
            ->where('pa.profile_id', $profile->id)
            ->where('pa.address_scope', 'parents')
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
                'address_type_key' => (string) ($r->address_type_key ?? 'permanent'),
                'address_line' => (string) ($r->address_line ?? ''),
                'location_id' => $lid,
                'display' => $disp,
                'rid' => 'pdb-'.$r->id,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $parsed
     * @return array<int, array<string, mixed>>
     */
    private function rowsFromParentsAddresses(array $snapshot, array $parsed): array
    {
        $list = is_array($snapshot['parents_addresses'] ?? null) ? $snapshot['parents_addresses'] : [];
        if ($list === []) {
            $list = is_array($parsed['parents_addresses'] ?? null) ? $parsed['parents_addresses'] : [];
        }

        $out = [];
        foreach ($list as $i => $addr) {
            if (! is_array($addr)) {
                continue;
            }
            $line = trim((string) ($addr['address_line'] ?? $addr['raw'] ?? ''));
            $locationText = trim((string) ($addr['location_text'] ?? ''));
            if ($locationText === '' && ParentsBiodataAddressSplitter::looksLikeParentsHomeBlob($line)) {
                $split = ParentsBiodataAddressSplitter::split($line);
                if ($split['address_line'] !== '') {
                    $line = $split['address_line'];
                }
                $locationText = $split['location_text'];
            }
            $lid = (int) ($addr['location_id'] ?? $addr['city_id'] ?? 0);
            if ($line === '' && $lid < 1 && $locationText === '') {
                continue;
            }
            $disp = $lid > 0
                ? MatrimonyProfile::residenceLocationDisplayLineFor((object) ['location_id' => $lid])
                : '';
            $out[] = [
                'id' => null,
                'address_type_key' => (string) ($addr['address_type_key'] ?? $addr['type'] ?? 'permanent'),
                'address_line' => $line,
                'location_id' => $lid > 0 ? (string) $lid : '',
                'display' => $disp,
                'location_text' => $locationText,
                'rid' => 'intake-parents-'.$i,
            ];
            if (count($out) >= 3) {
                break;
            }
        }

        return $out;
    }
}

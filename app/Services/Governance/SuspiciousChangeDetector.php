<?php

namespace App\Services\Governance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-5 Day-24: Read-only detector for suspicious change patterns.
 * No writes. Query Builder only.
 */
class SuspiciousChangeDetector
{
    /**
     * Detect income spikes: history rows where field_name = annual_income and new > old by threshold ratio.
     */
    public function detectIncomeSpike(float $minRatio = 2.0, int $limit = 50, ?\Carbon\Carbon $from = null, ?\Carbon\Carbon $to = null): array
    {
        if (!Schema::hasTable('profile_change_history')) {
            return [];
        }
        $query = DB::table('profile_change_history')
            ->where('field_name', 'annual_income')
            ->whereNotNull('new_value')
            ->where('new_value', '!=', '')
            ->orderByDesc('changed_at')
            ->limit($limit * 3);
        if ($from !== null) {
            $query->where('changed_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('changed_at', '<=', $to);
        }
        $rows = $query->get();
        $out = [];
        foreach ($rows as $row) {
            $old = $row->old_value !== null && $row->old_value !== '' ? (float) $row->old_value : 0.0;
            $new = (float) $row->new_value;
            if ($old <= 0 || $new <= $old) {
                continue;
            }
            if (($new / $old) < $minRatio) {
                continue;
            }
            $out[] = [
                'profile_id' => (int) $row->profile_id,
                'old_value' => $row->old_value,
                'new_value' => $row->new_value,
                'changed_at' => $row->changed_at,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Detect caste change where profile has serious_intent_id.
     */
    public function detectCasteFlipAfterSeriousIntent(int $limit = 50, ?\Carbon\Carbon $from = null, ?\Carbon\Carbon $to = null): array
    {
        if (!Schema::hasTable('profile_change_history') || !Schema::hasTable('matrimony_profiles')) {
            return [];
        }
        $query = DB::table('profile_change_history')
            ->where('field_name', 'caste')
            ->where('entity_type', 'matrimony_profile')
            ->orderByDesc('changed_at')
            ->limit(500);
        if ($from !== null) {
            $query->where('changed_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('changed_at', '<=', $to);
        }
        $casteChanges = $query->get();
        $profileIds = $casteChanges->pluck('profile_id')->unique()->values()->all();
        if (empty($profileIds)) {
            return [];
        }
        $withIntent = DB::table('matrimony_profiles')
            ->whereIn('id', $profileIds)
            ->whereNotNull('serious_intent_id')
            ->where('serious_intent_id', '!=', '')
            ->pluck('id')
            ->flip()
            ->all();
        $out = [];
        foreach ($casteChanges as $row) {
            if (!isset($withIntent[(int) $row->profile_id])) {
                continue;
            }
            $out[] = [
                'profile_id' => (int) $row->profile_id,
                'old_value' => $row->old_value,
                'new_value' => $row->new_value,
                'changed_at' => $row->changed_at,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Detect date_of_birth change for profiles that are currently active.
     */
    public function detectDobChangeAfterActive(int $limit = 50, ?\Carbon\Carbon $from = null, ?\Carbon\Carbon $to = null): array
    {
        if (!Schema::hasTable('profile_change_history') || !Schema::hasTable('matrimony_profiles')) {
            return [];
        }
        $query = DB::table('profile_change_history')
            ->where('field_name', 'date_of_birth')
            ->where('entity_type', 'matrimony_profile')
            ->orderByDesc('changed_at')
            ->limit(500);
        if ($from !== null) {
            $query->where('changed_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('changed_at', '<=', $to);
        }
        $dobChanges = $query->get();
        $profileIds = $dobChanges->pluck('profile_id')->unique()->values()->all();
        if (empty($profileIds)) {
            return [];
        }
        $activeIds = DB::table('matrimony_profiles')
            ->whereIn('id', $profileIds)
            ->where('lifecycle_state', 'active')
            ->pluck('id')
            ->flip()
            ->all();
        $out = [];
        foreach ($dobChanges as $row) {
            if (!isset($activeIds[(int) $row->profile_id])) {
                continue;
            }
            $out[] = [
                'profile_id' => (int) $row->profile_id,
                'old_value' => $row->old_value,
                'new_value' => $row->new_value,
                'changed_at' => $row->changed_at,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Profiles with frequent contact-related changes.
     */
    public function detectFrequentContactChanges(int $minChanges = 3, int $limit = 50, ?\Carbon\Carbon $from = null, ?\Carbon\Carbon $to = null): array
    {
        if (!Schema::hasTable('profile_change_history')) {
            return [];
        }
        $query = DB::table('profile_change_history')
            ->where(function ($q) {
                $q->where('entity_type', 'profile_contacts')
                    ->orWhere('field_name', 'primary_contact_number');
            })
            ->select('profile_id', DB::raw('COUNT(*) as contact_change_count'))
            ->groupBy('profile_id')
            ->having('contact_change_count', '>=', $minChanges)
            ->orderByDesc('contact_change_count')
            ->limit($limit);
        if ($from !== null) {
            $query->where('changed_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('changed_at', '<=', $to);
        }
        $counts = $query->get();
        return $counts->map(fn ($row) => [
            'profile_id' => (int) $row->profile_id,
            'contact_change_count' => (int) $row->contact_change_count,
        ])->all();
    }
}

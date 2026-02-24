<?php

namespace App\Services;

use App\Models\City;
use App\Models\CityAlias;
use App\Models\LocationSuggestion;
use Illuminate\Support\Facades\DB;

class LocationSuggestionApprovalService
{
    public function approve(int $suggestionId, int $adminId): void
    {
        DB::transaction(function () use ($suggestionId, $adminId) {
            $suggestion = LocationSuggestion::where('id', $suggestionId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->firstOrFail();

            $normalized = strtolower(trim($suggestion->suggested_name));

            $cityExists = City::where('taluka_id', $suggestion->taluka_id)
                ->whereRaw('LOWER(TRIM(name)) = ?', [$normalized])
                ->exists();
            if ($cityExists) {
                throw new \RuntimeException('Location already exists in canonical data.');
            }

            if (CityAlias::where('normalized_alias', $normalized)
                ->whereHas('city', function ($q) use ($suggestion) {
                    $q->where('taluka_id', $suggestion->taluka_id);
                })
                ->exists()) {
                throw new \RuntimeException('Location already exists in canonical data.');
            }

            City::create([
                'taluka_id' => $suggestion->taluka_id,
                'name' => trim($suggestion->suggested_name),
            ]);

            $suggestion->update([
                'status' => 'approved',
                'admin_reviewed_by' => $adminId,
                'admin_reviewed_at' => now(),
            ]);
        });
    }

    public function reject(int $suggestionId, int $adminId): void
    {
        DB::transaction(function () use ($suggestionId, $adminId) {
            $suggestion = LocationSuggestion::where('id', $suggestionId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->firstOrFail();

            $suggestion->update([
                'status' => 'rejected',
                'admin_reviewed_by' => $adminId,
                'admin_reviewed_at' => now(),
            ]);
        });
    }
}

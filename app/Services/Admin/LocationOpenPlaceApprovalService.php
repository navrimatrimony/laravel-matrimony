<?php

namespace App\Services\Admin;

use App\Models\City;
use App\Models\CityAlias;
use App\Models\LocationOpenPlaceSuggestion;
use App\Models\Taluka;
use App\Services\Location\LocationNormalizationService;
use Illuminate\Support\Facades\DB;

/**
 * Admin promotion for {@see LocationOpenPlaceSuggestion}: new {@see City} + {@see CityAlias}, or alias-only map.
 *
 * Alias keys use {@see LocationNormalizationService::mergeKeyFromRaw} — same contract as intake + `city_aliases`.
 */
class LocationOpenPlaceApprovalService
{
    /** @var array<int, string> */
    private const OPEN_REVIEWABLE_STATUSES = ['pending', 'auto_candidate'];

    public function __construct(
        private LocationNormalizationService $locationNormalization,
    ) {}

    /**
     * Create a new city under the given taluka and attach this suggestion's normalized key as an alias.
     *
     * @throws \RuntimeException
     */
    public function approveAsNewCity(int $suggestionId, int $adminUserId, int $talukaId, ?int $districtIdForValidation = null): void
    {
        DB::transaction(function () use ($suggestionId, $adminUserId, $talukaId, $districtIdForValidation): void {
            $suggestion = $this->lockPendingResolvable($suggestionId);

            $taluka = Taluka::query()->with('district')->whereKey($talukaId)->firstOrFail();
            if ($districtIdForValidation !== null && (int) $taluka->district_id !== (int) $districtIdForValidation) {
                throw new \RuntimeException('Selected taluka does not belong to the chosen district.');
            }
            if ($suggestion->district_id !== null && (int) $suggestion->district_id !== (int) $taluka->district_id) {
                throw new \RuntimeException('Suggestion context district does not match the selected taluka’s district. Pick the matching hierarchy or map to an existing city instead.');
            }

            $cityName = $this->cleanDisplayNameForNewCity((string) $suggestion->raw_input);
            if ($cityName === '') {
                throw new \RuntimeException('Cleaned city name is empty.');
            }

            $nameKey = mb_strtolower(trim($cityName), 'UTF-8');
            $cityExists = City::query()
                ->where('taluka_id', $taluka->id)
                ->whereRaw('LOWER(TRIM(name)) = ?', [$nameKey])
                ->exists();
            if ($cityExists) {
                throw new \RuntimeException('A city with this name already exists in the selected taluka. Use “Map to existing city” or pick another taluka.');
            }

            if (CityAlias::query()
                ->where('is_active', true)
                ->whereHas('city', fn ($q) => $q->where('taluka_id', $taluka->id))
                ->where('normalized_alias', $suggestion->normalized_input)
                ->exists()) {
                throw new \RuntimeException('This alias already exists for a city in this taluka.');
            }

            $normalizedKey = trim((string) $suggestion->normalized_input) !== ''
                ? trim((string) $suggestion->normalized_input)
                : $this->locationNormalization->mergeKeyFromRaw((string) $suggestion->raw_input);
            $this->assertAliasNormalizedNotActiveElsewhere($normalizedKey, null);

            $city = City::query()->create([
                'taluka_id' => (int) $taluka->id,
                'name' => $cityName,
            ]);

            $this->attachAliasForCity($city, $suggestion, $normalizedKey);

            $suggestion->update([
                'status' => 'approved',
                'resolved_city_id' => $city->id,
                'match_type' => 'manual',
                'admin_reviewed_by' => $adminUserId,
                'admin_reviewed_at' => now(),
            ]);
        });
    }

    /**
     * Attach the suggestion's normalized key to an existing city and mark approved.
     *
     * @throws \RuntimeException
     */
    public function mapToExistingCity(int $suggestionId, int $adminUserId, int $cityId): void
    {
        DB::transaction(function () use ($suggestionId, $adminUserId, $cityId): void {
            $suggestion = $this->lockPendingResolvable($suggestionId);
            $city = City::query()->with('taluka')->whereKey($cityId)->firstOrFail();

            $normalizedKey = trim((string) $suggestion->normalized_input) !== ''
                ? trim((string) $suggestion->normalized_input)
                : $this->locationNormalization->mergeKeyFromRaw((string) $suggestion->raw_input);
            $this->assertAliasNormalizedNotActiveElsewhere($normalizedKey, (int) $city->id);
            $this->attachAliasForCity($city, $suggestion, $normalizedKey);

            $suggestion->update([
                'status' => 'approved',
                'resolved_city_id' => $city->id,
                'match_type' => 'alias',
                'admin_reviewed_by' => $adminUserId,
                'admin_reviewed_at' => now(),
            ]);
        });
    }

    public function reject(int $suggestionId, int $adminUserId): void
    {
        DB::transaction(function () use ($suggestionId, $adminUserId): void {
            $suggestion = LocationOpenPlaceSuggestion::query()
                ->whereKey($suggestionId)
                ->whereIn('status', self::OPEN_REVIEWABLE_STATUSES)
                ->whereNull('merged_into_suggestion_id')
                ->lockForUpdate()
                ->firstOrFail();

            $suggestion->update([
                'status' => 'rejected',
                'admin_reviewed_by' => $adminUserId,
                'admin_reviewed_at' => now(),
            ]);
        });
    }

    /**
     * Fold one pending row into another (usage_count aggregate). Source becomes status {@code merged}.
     *
     * @throws \RuntimeException
     */
    public function mergeInto(int $sourceSuggestionId, int $targetSuggestionId, int $adminUserId): void
    {
        if ($sourceSuggestionId === $targetSuggestionId) {
            throw new \RuntimeException('Cannot merge a suggestion into itself.');
        }

        DB::transaction(function () use ($sourceSuggestionId, $targetSuggestionId, $adminUserId): void {
            $source = LocationOpenPlaceSuggestion::query()
                ->whereKey($sourceSuggestionId)
                ->lockForUpdate()
                ->firstOrFail();
            $target = LocationOpenPlaceSuggestion::query()
                ->whereKey($targetSuggestionId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array((string) $source->status, self::OPEN_REVIEWABLE_STATUSES, true) || $source->merged_into_suggestion_id !== null) {
                throw new \RuntimeException('Source suggestion is not an open reviewable row.');
            }
            if (! in_array((string) $target->status, self::OPEN_REVIEWABLE_STATUSES, true) || $target->merged_into_suggestion_id !== null) {
                throw new \RuntimeException('Target suggestion must be pending and not already merged.');
            }
            if ($source->resolved_city_id !== null || $target->resolved_city_id !== null) {
                throw new \RuntimeException('Merge is only for unresolved pending rows.');
            }

            $incrementBy = max(0, (int) $source->usage_count);
            if ($incrementBy > 0) {
                $target->increment('usage_count', $incrementBy);
            }

            $source->update([
                'status' => 'merged',
                'merged_into_suggestion_id' => $target->id,
                'admin_reviewed_by' => $adminUserId,
                'admin_reviewed_at' => now(),
            ]);
        });
    }

    private function lockPendingResolvable(int $suggestionId): LocationOpenPlaceSuggestion
    {
        $suggestion = LocationOpenPlaceSuggestion::query()
            ->whereKey($suggestionId)
            ->whereIn('status', self::OPEN_REVIEWABLE_STATUSES)
            ->whereNull('merged_into_suggestion_id')
            ->lockForUpdate()
            ->firstOrFail();

        if ($suggestion->resolved_city_id !== null) {
            throw new \RuntimeException('Suggestion is already resolved.');
        }

        return $suggestion;
    }

    /**
     * {@see CityAlias} is unique per (city_id, normalized_alias), but {@see LocationNormalizationService}
     * resolves globally by normalized_alias — a second active row for another city breaks lookup semantics.
     */
    /**
     * @param  int|null  $allowedCityId  When set, active aliases on this city are ignored (same-city idempotent attach).
     */
    private function assertAliasNormalizedNotActiveElsewhere(string $normalizedAlias, ?int $allowedCityId): void
    {
        $q = CityAlias::query()
            ->where('normalized_alias', $normalizedAlias)
            ->where('is_active', true);
        if ($allowedCityId !== null) {
            $q->where('city_id', '!=', $allowedCityId);
        }
        if ($q->exists()) {
            throw new \RuntimeException(
                'This normalized alias is already active on a different city. Resolve the existing alias first, or map this suggestion to that city.'
            );
        }
    }

    private function attachAliasForCity(City $city, LocationOpenPlaceSuggestion $suggestion, string $normalized): void
    {
        $aliasName = $this->cleanDisplayNameForNewCity((string) $suggestion->raw_input);
        if ($aliasName === '') {
            $aliasName = $city->name;
        }

        CityAlias::query()->firstOrCreate(
            [
                'city_id' => $city->id,
                'normalized_alias' => $normalized,
            ],
            [
                'alias_name' => mb_substr($aliasName, 0, 190),
                'is_active' => true,
            ],
        );
    }

    private function cleanDisplayNameForNewCity(string $raw): string
    {
        $s = trim($raw);
        $s = preg_replace('/,+$/u', '', $s) ?? $s;
        $s = trim($s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        return $s;
    }
}

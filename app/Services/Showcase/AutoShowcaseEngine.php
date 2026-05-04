<?php

namespace App\Services\Showcase;

use App\Models\City;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * After a member search, optionally creates one showcase profile via {@see ShowcaseProfileFactory} when admin AND-rules pass.
 */
class AutoShowcaseEngine
{
    public function __construct(
        private readonly ShowcaseProfileFactory $factory,
        private readonly ShowcaseResidenceResolver $residenceResolver
    ) {}

    /**
     * @return array{created: bool, profile_id: ?int, reason: string}
     */
    public function evaluateAfterSearchCounts(Request $request, ?User $user, int $totalCount, int $strictCount): array
    {
        if (! AutoShowcaseSettings::engineEnabled()) {
            return $this->logReturn('skipped_engine_off', false, null);
        }

        if (! $user || ! $user->matrimonyProfile) {
            return $this->logReturn('skipped_no_viewer_profile', false, null);
        }

        $perSearch = AutoShowcaseSettings::perSearchMaxCreate();
        if ($perSearch <= 0) {
            return $this->logReturn('skipped_per_search_zero', false, null);
        }

        $dailyCap = AutoShowcaseSettings::dailyUserCap();
        $dayKey = now()->format('Y-m-d');
        $dailyCacheKey = 'auto_showcase:daily:'.$user->id.':'.$dayKey;
        if ($dailyCap > 0) {
            $dayCount = (int) Cache::get($dailyCacheKey, 0);
            if ($dayCount >= $dailyCap) {
                return $this->logReturn('skipped_daily_cap', false, null, ['day_count' => $dayCount]);
            }
        }

        $lowTotal = ! AutoShowcaseSettings::requireLowTotal()
            || $totalCount <= AutoShowcaseSettings::minTotalResults();
        $strictLow = ! AutoShowcaseSettings::requireStrictLow()
            || $strictCount <= AutoShowcaseSettings::strictMax();

        if (! $lowTotal || ! $strictLow) {
            return $this->logReturn('skipped_and_gate', false, null, [
                'total_count' => $totalCount,
                'strict_count' => $strictCount,
                'low_total' => $lowTotal,
                'strict_low' => $strictLow,
            ]);
        }

        $fp = $this->requestFingerprint($request);
        $dedupeKey = 'auto_showcase:req:'.$user->id.':'.$fp;
        if (! Cache::add($dedupeKey, 1, now()->addMinutes(10))) {
            return $this->logReturn('skipped_duplicate_request', false, null);
        }

        $cityId = $this->residenceResolver->resolveCityId($request);
        if ($cityId === null) {
            Cache::forget($dedupeKey);

            return $this->logReturn('skipped_no_city', false, null);
        }

        $overrides = $this->buildAttributeOverrides($request, $user, $cityId);
        $allow = AutoShowcaseSettings::religionAllowlistIds();
        if ($allow !== [] && ! $request->filled('religion_id')) {
            Cache::forget($dedupeKey);

            return $this->logReturn('skipped_religion_filter_required', false, null);
        }

        $rid = isset($overrides['religion_id']) ? (int) $overrides['religion_id'] : null;
        if ($rid !== null) {
            if ($allow !== [] && ! in_array($rid, $allow, true)) {
                Cache::forget($dedupeKey);

                return $this->logReturn('skipped_religion_allowlist', false, null, ['religion_id' => $rid]);
            }
        }

        $seq = (int) (microtime(true) * 1000) % 1000000;
        $genderOverride = $this->oppositeGenderOverride($user);
        $actorUserId = (int) $user->id;
        $lifecycle = AutoShowcaseSettings::autoEngineShowcaseLifecycle();

        $searcherProfileId = (int) $user->matrimonyProfile->id;
        $newId = $this->factory->create($seq, $genderOverride, $actorUserId, $overrides, $lifecycle, $searcherProfileId);

        if ($newId === null) {
            Cache::forget($dedupeKey);

            return $this->logReturn('failed_factory', false, null);
        }

        if ($dailyCap > 0) {
            $n = (int) Cache::get($dailyCacheKey, 0);
            Cache::put($dailyCacheKey, $n + 1, now()->endOfDay()->addHour());
        }

        return $this->logReturn('created', true, $newId, [
            'total_count' => $totalCount,
            'strict_count' => $strictCount,
            'lifecycle' => $lifecycle,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAttributeOverrides(Request $request, User $user, int $cityId): array
    {
        $overrides = [];

        if ($request->filled('religion_id')) {
            $overrides['religion_id'] = (int) $request->religion_id;
        }
        if ($request->filled('caste_id')) {
            $overrides['caste_id'] = (int) $request->caste_id;
        }
        if ($request->filled('sub_caste_id')) {
            $overrides['sub_caste_id'] = (int) $request->sub_caste_id;
        }
        if ($request->filled('marital_status_id')) {
            $overrides['marital_status_id'] = (int) $request->marital_status_id;
        } elseif ($request->filled('marital_status')) {
            $msId = $request->input('marital_status') === 'single'
                ? \App\Models\MasterMaritalStatus::where('key', 'never_married')->value('id')
                : \App\Models\MasterMaritalStatus::where('key', $request->input('marital_status'))->value('id');
            if ($msId) {
                $overrides['marital_status_id'] = (int) $msId;
            }
        }

        if ($request->filled('education')) {
            $overrides['highest_education'] = (string) $request->education;
        }

        if ($request->filled('height_from') || $request->filled('height_to')) {
            $hFrom = $request->filled('height_from') ? (int) $request->height_from : 140;
            $hTo = $request->filled('height_to') ? (int) $request->height_to : 200;
            if ($hFrom > $hTo) {
                [$hFrom, $hTo] = [$hTo, $hFrom];
            }
            $overrides['height_cm'] = random_int(max(100, $hFrom), min(220, $hTo));
        }

        if ($request->filled('age_from') || $request->filled('age_to')) {
            $from = $request->filled('age_from') ? max(18, (int) $request->age_from) : 18;
            $to = $request->filled('age_to') ? max($from, (int) $request->age_to) : 55;
            $age = random_int($from, $to);
            $overrides['date_of_birth'] = now()->subYears($age)->subDays(random_int(0, 330))->format('Y-m-d');
        }

        $city = City::query()->with(['taluka.district.state'])->find($cityId);
        if ($city && $city->taluka && $city->taluka->district) {
            $overrides['city_id'] = (int) $city->id;
            $overrides['taluka_id'] = (int) $city->parent_id;
            $overrides['district_id'] = (int) $city->taluka->parent_id;
            $d = $city->taluka->district;
            $overrides['state_id'] = (int) $d->parent_id;
            $d->loadMissing('state');
            if ($d->state) {
                $overrides['country_id'] = (int) $d->state->parent_id;
            }
        } else {
            $overrides['city_id'] = $cityId;
        }

        if ($request->filled('profession_id')) {
            $overrides['profession_id'] = (int) $request->profession_id;
        }
        if ($request->filled('serious_intent_id')) {
            $overrides['serious_intent_id'] = (int) $request->serious_intent_id;
        }

        return $overrides;
    }

    private function oppositeGenderOverride(User $user): ?string
    {
        $p = $user->matrimonyProfile;
        if (! $p) {
            return null;
        }
        $p->loadMissing('gender');
        $key = $p->gender?->key ?? null;
        if ($key === 'male') {
            return 'female';
        }
        if ($key === 'female') {
            return 'male';
        }

        return null;
    }

    private function requestFingerprint(Request $request): string
    {
        $keys = [
            'religion_id', 'caste_id', 'sub_caste_id', 'country_id', 'state_id', 'district_id', 'taluka_id', 'city_id',
            'age_from', 'age_to', 'height_from', 'height_to', 'marital_status_id', 'marital_status', 'education',
            'profession_id', 'serious_intent_id', 'has_photo', 'verified_only', 'sort', 'per_page',
        ];

        return hash('sha256', json_encode($request->only($keys)));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{created: bool, profile_id: ?int, reason: string}
     */
    private function logReturn(string $reason, bool $created, ?int $profileId, array $extra = []): array
    {
        Log::info('auto_showcase', array_merge([
            'event' => $reason,
            'created' => $created,
            'profile_id' => $profileId,
        ], $extra));

        return ['created' => $created, 'profile_id' => $profileId, 'reason' => $reason];
    }
}

<?php

namespace App\Services\Matching\Rules;

use App\Models\MatrimonyProfile;
use App\Services\Matching\Contracts\MatchingRuleContract;
use Carbon\Carbon;

class AgeMatchRule implements MatchingRuleContract
{
    public function apply(MatrimonyProfile $a, MatrimonyProfile $b, array $rule): int
    {
        if (! $a->date_of_birth || ! $b->date_of_birth) {
            return 0;
        }
        $maxDiff = (int) ($rule['meta']['max_age_diff_years'] ?? 5);
        $maxDiff = $maxDiff > 0 ? $maxDiff : 5;
        $ageA = Carbon::parse($a->date_of_birth)->age;
        $ageB = Carbon::parse($b->date_of_birth)->age;

        return abs($ageA - $ageB) <= $maxDiff ? $rule['weight'] : 0;
    }
}

<?php

namespace App\Services\Matching\Rules;

use App\Models\MatrimonyProfile;
use App\Services\Matching\Contracts\MatchingRuleContract;

class LocationMatchRule implements MatchingRuleContract
{
    public function apply(MatrimonyProfile $a, MatrimonyProfile $b, array $rule): int
    {
        $sameCity = $a->city_id && $b->city_id && (int) $a->city_id === (int) $b->city_id;
        $sameState = $a->state_id && $b->state_id && (int) $a->state_id === (int) $b->state_id;

        return ($sameCity || $sameState) ? $rule['weight'] : 0;
    }
}

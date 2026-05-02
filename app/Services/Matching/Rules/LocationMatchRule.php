<?php

namespace App\Services\Matching\Rules;

use App\Models\MatrimonyProfile;
use App\Services\Matching\Contracts\MatchingRuleContract;

class LocationMatchRule implements MatchingRuleContract
{
    public function apply(MatrimonyProfile $a, MatrimonyProfile $b, array $rule): int
    {
        $sameCity = $a->location_id && $b->location_id && (int) $a->location_id === (int) $b->location_id;
        $sameState = $a->state_id && $b->state_id && (int) $a->state_id === (int) $b->state_id;

        return ($sameCity || $sameState) ? $rule['weight'] : 0;
    }
}

<?php

namespace App\Services\Matching\Rules;

use App\Models\MatrimonyProfile;
use App\Services\Matching\Contracts\MatchingRuleContract;

class NullMatchRule implements MatchingRuleContract
{
    public function apply(MatrimonyProfile $a, MatrimonyProfile $b, array $rule): int
    {
        return 0;
    }
}

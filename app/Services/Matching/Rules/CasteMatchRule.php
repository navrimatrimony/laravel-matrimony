<?php

namespace App\Services\Matching\Rules;

use App\Models\MatrimonyProfile;
use App\Services\Matching\Contracts\MatchingRuleContract;

class CasteMatchRule implements MatchingRuleContract
{
    public function apply(MatrimonyProfile $a, MatrimonyProfile $b, array $rule): int
    {
        if (! $a->caste_id || ! $b->caste_id) {
            return 0;
        }

        return (int) $a->caste_id === (int) $b->caste_id ? $rule['weight'] : 0;
    }
}

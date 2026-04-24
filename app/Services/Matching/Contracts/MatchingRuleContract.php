<?php

namespace App\Services\Matching\Contracts;

use App\Models\MatrimonyProfile;

interface MatchingRuleContract
{
    /**
     * @param  array{key: string, weight: int, meta: array<string, mixed>}  $rule
     */
    public function apply(MatrimonyProfile $a, MatrimonyProfile $b, array $rule): int;
}

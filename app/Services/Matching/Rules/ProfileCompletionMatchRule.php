<?php

namespace App\Services\Matching\Rules;

use App\Models\MatrimonyProfile;
use App\Services\Matching\Contracts\MatchingRuleContract;
use App\Services\ProfileCompletionEngine;

class ProfileCompletionMatchRule implements MatchingRuleContract
{
    public function __construct(
        private readonly ProfileCompletionEngine $profileCompletionEngine,
    ) {}

    public function apply(MatrimonyProfile $a, MatrimonyProfile $b, array $rule): int
    {
        $minPct = (int) ($rule['meta']['min_mandatory_pct'] ?? 80);
        $minPct = max(0, min(100, $minPct));
        $aPct = $this->profileCompletionEngine->forProfile($a)['mandatory_core'];
        $bPct = $this->profileCompletionEngine->forProfile($b)['mandatory_core'];

        return ($aPct >= $minPct && $bPct >= $minPct) ? $rule['weight'] : 0;
    }
}

<?php

namespace App\Services\Matching\Rules;

use App\Models\MatrimonyProfile;
use App\Services\Matching\Contracts\MatchingRuleContract;

class EducationMatchRule implements MatchingRuleContract
{
    public function apply(MatrimonyProfile $a, MatrimonyProfile $b, array $rule): int
    {
        $ea = trim((string) ($a->highest_education ?? ''));
        $eb = trim((string) ($b->highest_education ?? ''));
        if ($ea === '' || $eb === '') {
            return 0;
        }

        return strcasecmp($ea, $eb) === 0 ? $rule['weight'] : 0;
    }
}

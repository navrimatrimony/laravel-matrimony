<?php

namespace App\Observers;

use App\Models\SystemRule;

class SystemRuleObserver
{
    public function saved(SystemRule $systemRule): void
    {
        $this->forgetMatchingRulesIfNeeded($systemRule);
    }

    public function deleted(SystemRule $systemRule): void
    {
        $this->forgetMatchingRulesIfNeeded($systemRule);
    }

    private function forgetMatchingRulesIfNeeded(SystemRule $systemRule): void
    {
        $key = (string) ($systemRule->key ?? '');
        if ($key === '' || ! str_starts_with($key, 'matching')) {
            return;
        }
        // Matching rules cache invalidation removed after single-engine consolidation.
    }
}

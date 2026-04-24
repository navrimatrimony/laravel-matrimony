<?php

namespace App\Observers;

use App\Models\SystemRule;
use App\Services\MatchingEngine;

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

        MatchingEngine::forgetRulesCache();
    }
}

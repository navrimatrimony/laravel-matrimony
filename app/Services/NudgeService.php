<?php

namespace App\Services;

use App\Models\MatrimonyProfile;
use App\Models\User;

/**
 * Phase 4.3: lightweight nudges from {@see ProfileCompletionEngine} + {@see RuleEngineService} only (no scoring duplication).
 */
class NudgeService
{
    public function __construct(
        private readonly ProfileCompletionEngine $profileCompletionEngine,
        private readonly RuleEngineService $ruleEngine,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $recommendations  Rows from {@see RecommendationService::getTopMatches()} (includes RuleEngine scores).
     * @return list<array{type: string, message: string, action_label: string, action_url?: string, profile_id?: int}>
     */
    public function getNudges(User $user, array $recommendations): array
    {
        $nudges = [];

        $profile = $user->matrimonyProfile;
        if (! $profile instanceof MatrimonyProfile) {
            return $nudges;
        }

        $completion = $this->profileCompletionEngine->for($user);

        if (! ($completion['is_mandatory_complete'] ?? false)) {
            $nudges[] = [
                'type' => 'profile_incomplete',
                'message' => 'तुमचा प्रोफाइल अपूर्ण आहे. पूर्ण केल्यास अधिक matches मिळतील',
                'action_label' => 'प्रोफाइल पूर्ण करा',
                'action_url' => route('matrimony.profile.edit'),
            ];
        }

        $top = $recommendations[0] ?? null;
        if (
            $top
            && ($top['final_score'] ?? 0) >= 80
            && $this->ruleEngine->passesInterestMandatoryCore($profile)
        ) {
            $topProfile = $top['profile'] ?? null;
            if ($topProfile instanceof MatrimonyProfile) {
                $nudges[] = [
                    'type' => 'high_match',
                    'message' => 'हा प्रोफाइल तुमच्यासाठी योग्य आहे. interest पाठवा',
                    'action_label' => 'Interest पाठवा',
                    'action_url' => route('matrimony.profile.show', $topProfile->id),
                    'profile_id' => (int) $topProfile->id,
                ];
            }
        }

        if ($recommendations === []) {
            $nudges[] = [
                'type' => 'no_matches',
                'message' => 'अधिक matches साठी तुमचा प्रोफाइल अपडेट करा',
                'action_label' => 'प्रोफाइल अपडेट करा',
                'action_url' => route('matrimony.profile.edit'),
            ];
        }

        return $nudges;
    }
}

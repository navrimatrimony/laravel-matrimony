<?php

namespace App\Services;

use App\Models\MatrimonyProfile;
use App\Models\User;

/**
 * Phase 4.1: orchestrates {@see RuleEngineService::forMany()} only — no duplicate scoring.
 */
class RecommendationService
{
    public function __construct(
        private readonly RuleEngineService $ruleEngine,
        private readonly ExplanationService $explanationService,
    ) {}

    /**
     * @return list<array{profile: MatrimonyProfile, score: int, ai_boost: int, final_score: int, explanation: string}>
     */
    public function getTopMatches(User $user, int $limit = 10): array
    {
        $viewerProfile = $user->matrimonyProfile;
        if (! $viewerProfile instanceof MatrimonyProfile) {
            return [];
        }

        $candidates = MatrimonyProfile::query()
            ->where('id', '!=', $viewerProfile->id)
            ->where('gender_id', '!=', $viewerProfile->gender_id)
            ->whereNotNull('date_of_birth')
            ->whereBetween('date_of_birth', [
                now()->subYears(40),
                now()->subYears(20),
            ])
            ->limit(100)
            ->get();

        $profiles = $candidates->map(function (MatrimonyProfile $profile): array {
            return [
                'profile' => $profile,
                'completion' => $this->ruleEngine->mandatoryCoreCompletionPercent($profile),
            ];
        });

        $filtered = $profiles
            ->filter(fn (array $p): bool => $p['completion'] >= 40)
            ->pluck('profile')
            ->take(50)
            ->values();

        $results = $this->ruleEngine->forMany($user, $filtered->all());

        $combined = $filtered->map(function (MatrimonyProfile $profile, int $index) use ($results): array {
            $row = $results[$index] ?? [];

            return [
                'profile' => $profile,
                'score' => (int) ($row['score'] ?? 0),
                'ai_boost' => (int) ($row['ai_boost'] ?? 0),
                'final_score' => (int) ($row['final_score'] ?? ($row['score'] ?? 0)),
                'explanation' => $this->explanationService->explain($row),
            ];
        });

        return $combined
            ->sortByDesc('final_score')
            ->take($limit)
            ->values()
            ->all();
    }
}

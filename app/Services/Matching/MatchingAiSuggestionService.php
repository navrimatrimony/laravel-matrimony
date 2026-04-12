<?php

namespace App\Services\Matching;

use App\Models\MatchingHardFilter;

/**
 * Advisory-only heuristics for admins. Never mutates configuration.
 */
class MatchingAiSuggestionService
{
    public function __construct(
        protected MatchingConfigService $config,
    ) {}

    /**
     * @return array{suggestions: list<array{type: string, title: string, detail: string, suggested_action: string}>, meta: array<string, mixed>}
     */
    public function suggest(): array
    {
        $this->config->ensureDefaults();
        $fields = $this->config->getActiveFields();
        $sum = 0;
        $active = 0;
        foreach ($fields as $row) {
            if ($row['is_active']) {
                $sum += max(0, (int) $row['weight']);
                $active++;
            }
        }

        $suggestions = [];
        if ($sum > 100) {
            $suggestions[] = [
                'type' => 'weights',
                'title' => 'Active weights exceed 100',
                'detail' => 'Current sum is '.$sum.'. Consider lowering secondary fields first, or disable low-priority signals.',
                'suggested_action' => 'Reduce occupation or preferences weights until the total is at most 100.',
            ];
        }

        if ($sum < 60 && $active >= 4) {
            $suggestions[] = [
                'type' => 'coverage',
                'title' => 'Low total weight',
                'detail' => 'Scores may cluster tightly; differentiation is weak.',
                'suggested_action' => 'Raise core field weights slightly (age, community, preferences) while keeping sum ≤ 100.',
            ];
        }

        foreach ($this->config->getHardFilters() as $key => $row) {
            if (($row['mode'] ?? '') === MatchingHardFilter::MODE_STRICT) {
                $suggestions[] = [
                    'type' => 'filters',
                    'title' => 'Strict filter: '.$key,
                    'detail' => 'Strict modes shrink the candidate pool and can hide viable matches.',
                    'suggested_action' => 'If inbound quality complaints mention "no matches", try preferred mode with a small penalty instead.',
                ];
            }
        }

        $beh = $this->config->getBehaviorWeights();
        if (($beh['skip']['weight'] ?? 0) > 0) {
            $suggestions[] = [
                'type' => 'behavior',
                'title' => 'Skip weight is positive',
                'detail' => 'Positive skip weight would reward skipping; usually skip should be zero or negative.',
                'suggested_action' => 'Set skip weight to a negative value or disable the skip signal.',
            ];
        }

        return [
            'suggestions' => $suggestions,
            'meta' => [
                'active_field_weight_sum' => $sum,
                'active_field_count' => $active,
                'generated_at' => now()->toIso8601String(),
            ],
        ];
    }
}

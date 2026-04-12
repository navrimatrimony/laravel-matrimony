<?php

namespace App\Services\Matching;

use App\Models\MatrimonyProfile;

/**
 * Structured "why this match" lines with approximate impact points.
 */
class MatchingExplainService
{
    public function __construct(
        protected MatchingService $matching,
    ) {}

    /**
     * @return list<array{reason: string, impact: int}>
     */
    public function explainPair(MatrimonyProfile $seeker, MatrimonyProfile $candidate): array
    {
        $bd = $this->matching->computeMatchBreakdown($seeker, $candidate);
        $out = [];
        foreach ($bd['field_parts'] as $fp) {
            $label = implode(' · ', array_filter($fp['reasons']));
            if ($label !== '') {
                $out[] = ['reason' => $label, 'impact' => (int) $fp['points']];
            }
        }
        foreach ($bd['preferred_penalties'] as $p) {
            $out[] = ['reason' => (string) $p['reason'], 'impact' => (int) $p['impact']];
        }
        if ($bd['behavior_delta'] !== 0) {
            $out[] = [
                'reason' => $bd['behavior_delta'] > 0
                    ? __('matching_engine.behavior_positive', ['n' => $bd['behavior_delta']])
                    : __('matching_engine.behavior_negative', ['n' => abs($bd['behavior_delta'])]),
                'impact' => (int) $bd['behavior_delta'],
            ];
        }
        $boostDelta = (int) $bd['final_score'] - (int) $bd['before_boost'];
        if ($boostDelta !== 0) {
            $out[] = ['reason' => __('matching_engine.boost_layer', ['n' => $boostDelta]), 'impact' => $boostDelta];
        }

        return $out;
    }
}

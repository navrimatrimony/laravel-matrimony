<?php

namespace App\Services\Governance\Repeaters;

class RepeaterDiffEngine
{
    public function __construct(private readonly RepeaterRowMatcher $matcher) {}

    /**
     * @param  array<int,array<string,mixed>>  $wizardRows
     * @param  array<int,array<string,mixed>>  $publicRows
     * @return array<int,array<string,mixed>>
     */
    public function diff(string $repeater, array $wizardRows, array $publicRows): array
    {
        $matches = $this->matcher->match($wizardRows, $publicRows);
        $diffs = [];
        foreach ($matches as $m) {
            if (($m['status'] ?? '') === 'missing_row') {
                $diffs[] = [
                    'repeater' => $repeater,
                    'row' => $m['wizard_index'],
                    'field' => '*',
                    'wizard' => $wizardRows[$m['wizard_index']] ?? null,
                    'public_profile' => null,
                    'status' => 'missing_row',
                ];
                continue;
            }
            $w = $wizardRows[$m['wizard_index']] ?? [];
            $p = $publicRows[$m['public_index']] ?? [];
            foreach (array_keys(array_merge($w, $p)) as $field) {
                $wv = $w[$field] ?? null;
                $pv = $p[$field] ?? null;
                if ($wv === $pv) {
                    continue;
                }
                $diffs[] = [
                    'repeater' => $repeater,
                    'row' => $m['wizard_index'],
                    'field' => (string) $field,
                    'wizard' => $wv,
                    'public_profile' => $pv,
                    'status' => 'mismatch',
                ];
            }
        }

        return $diffs;
    }
}


<?php

namespace App\Services\Governance\Repeaters;

class RepeaterRowMatcher
{
    /**
     * @param  array<int,array<string,mixed>>  $wizardRows
     * @param  array<int,array<string,mixed>>  $publicRows
     * @return array<int,array<string,mixed>>
     */
    public function match(array $wizardRows, array $publicRows): array
    {
        $out = [];
        $used = [];
        foreach ($wizardRows as $wIdx => $wRow) {
            $matched = null;
            foreach ($publicRows as $pIdx => $pRow) {
                if (in_array($pIdx, $used, true)) {
                    continue;
                }
                if ($this->signature($wRow) === $this->signature($pRow)) {
                    $matched = $pIdx;
                    $used[] = $pIdx;
                    break;
                }
            }
            $out[] = [
                'wizard_index' => $wIdx,
                'public_index' => $matched,
                'status' => $matched === null ? 'missing_row' : 'matched',
            ];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function signature(array $row): string
    {
        unset($row['id'], $row['created_at'], $row['updated_at']);
        ksort($row);

        return sha1(json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }
}


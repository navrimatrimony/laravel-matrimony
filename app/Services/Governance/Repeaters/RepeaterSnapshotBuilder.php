<?php

namespace App\Services\Governance\Repeaters;

class RepeaterSnapshotBuilder
{
    /**
     * @param  array<string,mixed>  $repeaters
     * @return array<string,mixed>
     */
    public function build(array $repeaters): array
    {
        $out = [];
        foreach ($repeaters as $name => $rows) {
            if (! is_array($rows)) {
                continue;
            }
            $norm = [];
            foreach (array_values($rows) as $idx => $row) {
                if (! is_array($row)) {
                    continue;
                }
                ksort($row);
                $norm[$idx] = $row;
            }
            $out[(string) $name] = $norm;
        }
        ksort($out);

        return $out;
    }
}


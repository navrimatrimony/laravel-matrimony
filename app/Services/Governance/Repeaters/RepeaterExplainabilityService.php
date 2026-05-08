<?php

namespace App\Services\Governance\Repeaters;

class RepeaterExplainabilityService
{
    /**
     * @param  array<int,array<string,mixed>>  $diffs
     * @return array<int,array<string,mixed>>
     */
    public function explain(array $diffs): array
    {
        return array_map(function (array $d): array {
            $status = (string) ($d['status'] ?? 'mismatch');
            $message = match ($status) {
                'missing_row' => 'Row exists in wizard/db snapshot but is missing in public profile rendering.',
                'mismatch' => 'Row field value differs between wizard/db and public profile.',
                default => 'Repeater drift detected.',
            };

            return $d + [
                'message' => $message,
                'recommended_action' => 'Rebuild snapshot and validate repeater rendering pipeline.',
            ];
        }, $diffs);
    }
}


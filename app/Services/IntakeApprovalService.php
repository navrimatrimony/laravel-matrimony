<?php

namespace App\Services;

use App\Models\BiodataIntake;
use Illuminate\Support\Facades\DB;

class IntakeApprovalService
{
    /**
     * @param  array<string, mixed>|null  $snapshot  Edited snapshot from preview; when null use parsed_json.
     * @return array{mutation_success: bool, conflict_detected: bool, profile_id: int|null}
     */
    public function approve(BiodataIntake $intake, int $userId, ?array $snapshot = null): array
    {
        if ($intake->intake_locked === true) {
            throw new \RuntimeException('Intake is locked and cannot be approved.');
        }

        if ($intake->parse_status !== 'parsed') {
            throw new \RuntimeException('Intake must be parsed before approval.');
        }

        if ($intake->approved_by_user === true) {
            throw new \RuntimeException('Intake is already approved.');
        }

        $approvalSnapshot = $snapshot !== null ? $snapshot : $intake->parsed_json;
        if (!is_array($approvalSnapshot)) {
            $approvalSnapshot = [];
        }

        DB::transaction(function () use ($intake, $approvalSnapshot): void {
            $intake->approved_by_user = true;
            $intake->approved_at = now();
            $intake->approval_snapshot_json = $approvalSnapshot;
            $intake->snapshot_schema_version = 1;
            $intake->intake_status = 'approved';

            $intake->save();
        });

        return app(MutationService::class)->applyApprovedIntake($intake->id);
    }
}

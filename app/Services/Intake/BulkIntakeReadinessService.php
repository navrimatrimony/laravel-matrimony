<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\User;

class BulkIntakeReadinessService
{
    private const DISPLAY_REASONS = [
        'missing_linked_intake' => 'Linked biodata intake is missing.',
        'owner_unassigned' => 'Owner is not assigned.',
        'parse_pending' => 'Parse is pending.',
        'parse_queued' => 'Parse is queued.',
        'parse_error' => 'Parse failed or has an error.',
        'needs_review' => 'Bulk item is marked for review.',
        'missing_parsed_json' => 'Parsed JSON is missing.',
        'already_has_profile' => 'Owner already has a profile.',
        'owner_is_admin_invalid' => 'Owner is an admin account.',
        'intake_locked' => 'Intake is locked.',
        'intake_approved_already' => 'Intake is already approved.',
        'item_failed' => 'Bulk item failed.',
    ];

    /**
     * @return array{status: 'not_ready'|'ready_for_profile_review'|'blocked', ready: bool, reason_codes: list<string>, display_reasons: list<string>}
     */
    public function readinessForItem(BulkIntakeBatchItem $item): array
    {
        $item->loadMissing([
            'biodataIntake:id,uploaded_by,matrimony_profile_id,parse_status,last_error,approved_by_user,intake_locked,parsed_json',
            'biodataIntake.uploadedByUser:id,name,email,mobile,is_admin,admin_role',
            'biodataIntake.uploadedByUser.matrimonyProfile:id,user_id',
        ]);

        $blockedReasons = [];
        $notReadyReasons = [];
        $intake = $item->biodataIntake;

        if (! $intake instanceof BiodataIntake) {
            $blockedReasons[] = 'missing_linked_intake';

            return $this->result($blockedReasons, $notReadyReasons);
        }

        $owner = null;
        if ($intake->uploaded_by === null) {
            $notReadyReasons[] = 'owner_unassigned';
        } else {
            $owner = $intake->uploadedByUser;
            if (! $owner instanceof User) {
                $notReadyReasons[] = 'owner_unassigned';
            } else {
                if ($owner->isAnyAdmin()) {
                    $blockedReasons[] = 'owner_is_admin_invalid';
                }

                if ($intake->matrimony_profile_id !== null || $owner->matrimonyProfile !== null) {
                    $blockedReasons[] = 'already_has_profile';
                }
            }
        }

        if ((string) $item->item_status === BulkIntakeBatchItem::STATUS_FAILED) {
            $blockedReasons[] = 'item_failed';
        }

        if ((bool) $intake->intake_locked) {
            $blockedReasons[] = 'intake_locked';
        }

        if ((bool) $intake->approved_by_user) {
            $blockedReasons[] = 'intake_approved_already';
        }

        if ((string) $item->item_status === BulkIntakeBatchItem::STATUS_NEEDS_REVIEW) {
            $notReadyReasons[] = 'needs_review';
        }

        if (
            (string) $item->item_status === BulkIntakeBatchItem::STATUS_PARSE_QUEUED
            && (string) $intake->parse_status !== 'parsed'
        ) {
            $notReadyReasons[] = 'parse_queued';
        } elseif ((string) $intake->parse_status === 'pending') {
            $notReadyReasons[] = 'parse_pending';
        } elseif ((string) $intake->parse_status === 'error') {
            $notReadyReasons[] = 'parse_error';
        } elseif ((string) $intake->parse_status !== 'parsed') {
            $notReadyReasons[] = 'parse_pending';
        }

        if ((string) $intake->parse_status === 'parsed' && ! $this->hasParsedJson($intake)) {
            $notReadyReasons[] = 'missing_parsed_json';
        }

        return $this->result($blockedReasons, $notReadyReasons);
    }

    /**
     * @return array{by_item_id: array<int, array{status: 'not_ready'|'ready_for_profile_review'|'blocked', ready: bool, reason_codes: list<string>, display_reasons: list<string>}>, summary: array{ready_for_profile_review: int, not_ready: int, blocked: int, owner_missing: int, parse_pending_error: int}}
     */
    public function readinessForBatch(BulkIntakeBatch $batch): array
    {
        $items = $batch->items()
            ->with([
                'biodataIntake:id,uploaded_by,matrimony_profile_id,parse_status,last_error,approved_by_user,intake_locked,parsed_json',
                'biodataIntake.uploadedByUser:id,name,email,mobile,is_admin,admin_role',
                'biodataIntake.uploadedByUser.matrimonyProfile:id,user_id',
            ])
            ->orderBy('item_sequence')
            ->get();

        $byItemId = [];
        $summary = [
            'ready_for_profile_review' => 0,
            'not_ready' => 0,
            'blocked' => 0,
            'owner_missing' => 0,
            'parse_pending_error' => 0,
        ];

        foreach ($items as $item) {
            $readiness = $this->readinessForItem($item);
            $byItemId[(int) $item->id] = $readiness;
            $summary[$readiness['status']]++;

            if (in_array('owner_unassigned', $readiness['reason_codes'], true)) {
                $summary['owner_missing']++;
            }

            if (
                in_array('parse_pending', $readiness['reason_codes'], true)
                || in_array('parse_queued', $readiness['reason_codes'], true)
                || in_array('parse_error', $readiness['reason_codes'], true)
            ) {
                $summary['parse_pending_error']++;
            }
        }

        return [
            'by_item_id' => $byItemId,
            'summary' => $summary,
        ];
    }

    private function hasParsedJson(BiodataIntake $intake): bool
    {
        return is_array($intake->parsed_json) && $intake->parsed_json !== [];
    }

    /**
     * @param  list<string>  $blockedReasons
     * @param  list<string>  $notReadyReasons
     * @return array{status: 'not_ready'|'ready_for_profile_review'|'blocked', ready: bool, reason_codes: list<string>, display_reasons: list<string>}
     */
    private function result(array $blockedReasons, array $notReadyReasons): array
    {
        $reasonCodes = array_values(array_unique(array_merge($blockedReasons, $notReadyReasons)));
        $status = $blockedReasons !== []
            ? 'blocked'
            : ($notReadyReasons === [] ? 'ready_for_profile_review' : 'not_ready');

        return [
            'status' => $status,
            'ready' => $status === 'ready_for_profile_review',
            'reason_codes' => $reasonCodes,
            'display_reasons' => array_map(fn (string $reason): string => $this->displayReason($reason), $reasonCodes),
        ];
    }

    private function displayReason(string $reason): string
    {
        return self::DISPLAY_REASONS[$reason] ?? ucwords(str_replace('_', ' ', $reason)).'.';
    }
}

<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakCollaborationStageEvent;

/**
 * ONE payload shape for a marketplace ladder rung (blueprint 6a), shared by:
 *
 *   - the two CLAIM doors — `SuchakCollaborationStagesApiController::claimEngagementStage()`
 *     and `claimCustomerStage()`, which answer with the rung they just wrote;
 *   - the collaborations LIST read — `SuchakCollaborationsApiController`, which now carries the
 *     rungs an engagement already holds so a claimed-but-unconfirmed terminal rung survives an
 *     app restart instead of living only in Flutter session state.
 *
 * Written once for the reason {@see SuchakRequestPresenter} already gives: a rung read back on
 * the list and the same rung echoed by the write door must never be two different shapes, or the
 * client grows two parsers for one fact and they drift.
 *
 * ── WHAT IS DELIBERATELY NOT IN IT ────────────────────────────────────────────────────────────
 *
 * `event_note` is free text a Suchak typed and can name the family; `claimed_by_suchak_account_id`
 * / `claimed_by_user_id` / `claimed_via_customer_portal_link_id` name WHO acted. None of them is a
 * ladder fact, and the list read reaches a CROSS-SUCHAK counterparty's rungs, so neither is
 * published here. What is published is the rung, when it was claimed, when the family confirmed
 * it, and the rule it was written under.
 */
class SuchakStageLadderPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function rung(SuchakCollaborationStageEvent $event): array
    {
        $stageKey = (string) $event->stage_key;

        return [
            'stage_event_id' => (int) $event->id,
            'stage_key' => $stageKey,
            'stage_label' => SuchakCollaborationStageEvent::stageLabel($stageKey),
            'owner' => $event->ownerColumn(),
            'collaboration_id' => $event->collaboration_request_id === null
                ? null
                : (int) $event->collaboration_request_id,
            'customer_agreement_id' => $event->customer_agreement_id === null
                ? null
                : (int) $event->customer_agreement_id,
            'claimed_at' => $event->claimed_at?->toIso8601String(),
            'confirmed_at' => $event->confirmed_at?->toIso8601String(),
            // Who was entitled to write this rung. The app can grey the button out instead of
            // guessing, and a stored row carries the rule it was written under.
            'claimant' => SuchakCollaborationStageEvent::claimantFor($stageKey),
            'requires_confirmation' => SuchakCollaborationStageEvent::requiresConfirmation($stageKey),
            // D26 in one boolean: a confirmable rung is NOT settled until the family confirms, and
            // the success-fee ledger releases on exactly this predicate.
            'is_settled' => $event->isSettled(),
        ];
    }

    /**
     * The rungs of one owner, in LADDER order — never insertion order. "Furthest rung reached"
     * only means something if the list reads the way the ladder does.
     *
     * @param  iterable<int, SuchakCollaborationStageEvent>  $events
     * @return list<array<string, mixed>>
     */
    public function rungs(iterable $events): array
    {
        $rows = [];
        foreach ($events as $event) {
            $stageKey = (string) $event->stage_key;
            if (! SuchakCollaborationStageEvent::isValidStage($stageKey)) {
                continue;
            }

            $rows[] = [SuchakCollaborationStageEvent::stageIndex($stageKey), $this->rung($event)];
        }

        usort($rows, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return array_map(static fn (array $row): array => $row[1], $rows);
    }
}

<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakCollaborationStageEvent;
use App\Models\SuchakCustomerPlan;
use App\Models\SuchakServicePackage;
use App\Models\SuchakSuccessFeeTranche;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The ONE owner of blueprint section 7.4 — the success-fee split, its four arithmetic rules,
 * and the canonical shape that goes into the agreement snapshot digest.
 *
 * Nothing else may compute a tranche amount. T2 ("the parts must sum to the whole, exactly")
 * is only true if a single routine does the rounding, and that routine is {@see amounts()}.
 *
 * Messages thrown from here are Marathi because a Suchak reads them on the screen where he
 * types the split. The English exceptions elsewhere in this module are internal invariants
 * ("only the latest revision can…"), which a customer or Suchak never sees; these are input
 * validation of what a human just entered.
 */
class SuchakSuccessFeeTrancheService
{
    /**
     * @param  array<int, mixed>  $input  raw rows: trigger_stage_key, share_percent, optional
     *                                    sort_order and is_final_tranche
     * @return list<array<string, mixed>> canonical plan rows, ordered
     *
     * @throws InvalidArgumentException on any T2 or T3 breach (Marathi, shown to the Suchak)
     */
    public function normalizePlan(array $input): array
    {
        if ($input === []) {
            return [];
        }

        $rows = [];
        $seenStages = [];
        $previousLadderIndex = -1;

        foreach (array_values($input) as $position => $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException('हप्त्यांची माहिती अपूर्ण आहे.');
            }

            $stageKey = is_string($raw['trigger_stage_key'] ?? null) ? trim($raw['trigger_stage_key']) : '';

            if (! SuchakCollaborationStageEvent::isValidStage($stageKey)) {
                throw new InvalidArgumentException('हप्ता ज्या टप्प्यावर द्यायचा तो टप्पा वैध नाही.');
            }

            if (isset($seenStages[$stageKey])) {
                throw new InvalidArgumentException('एकाच टप्प्यावर दोन हप्ते ठेवता येणार नाहीत.');
            }
            $seenStages[$stageKey] = true;

            // The ladder is the order money is earned in, so the plan must read the same way.
            // Out of order, "the first tranche" and "the final tranche" stop meaning anything
            // and T2 and T4 both lose their subject.
            $ladderIndex = SuchakCollaborationStageEvent::stageIndex($stageKey);
            if ($ladderIndex <= $previousLadderIndex) {
                throw new InvalidArgumentException('हप्त्यांचा क्रम टप्प्यांच्या क्रमाप्रमाणेच असावा.');
            }
            $previousLadderIndex = $ladderIndex;

            $percent = $this->percent($raw['share_percent'] ?? null);

            $rows[] = [
                'sort_order' => ((int) $position + 1) * 10,
                'trigger_stage_key' => $stageKey,
                'share_percent' => $percent,
                'is_final_tranche' => (bool) ($raw['is_final_tranche'] ?? false),
            ];
        }

        $rows = $this->applyFinalTrancheRule($rows);
        $this->assertSharesSumTo100($rows);

        return $rows;
    }

    /**
     * T2 — the last tranche is the remainder, and only the last one.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function applyFinalTrancheRule(array $rows): array
    {
        $lastIndex = count($rows) - 1;
        $flagged = [];

        foreach ($rows as $index => $row) {
            if ($row['is_final_tranche'] === true) {
                $flagged[] = $index;
            }
        }

        if (count($flagged) > 1) {
            throw new InvalidArgumentException('फक्त एकच हप्ता "उर्वरित रक्कम" असू शकतो.');
        }

        if ($flagged !== [] && $flagged[0] !== $lastIndex) {
            throw new InvalidArgumentException('"उर्वरित रक्कम" हा शेवटचाच हप्ता असला पाहिजे.');
        }

        // Nobody flagged it: the blueprint leaves no choice about which row is the remainder,
        // so this is a reading of the plan, not a guess about the Suchak's intent.
        $rows[$lastIndex]['is_final_tranche'] = true;

        return $rows;
    }

    /**
     * T3 — the declared shares must sum to exactly 100%. Without this, 10/40/40 ships and 10%
     * of the fee belongs to nobody.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertSharesSumTo100(array $rows): void
    {
        // Summed in hundredths of a percent as integers: 0.1 + 0.2 in floats is not 0.3, and a
        // rule that says "exactly" cannot be enforced with a tolerance.
        $totalBasisPoints = 0;
        foreach ($rows as $row) {
            $totalBasisPoints += (int) round(((float) $row['share_percent']) * 100);
        }

        if ($totalBasisPoints !== 10000) {
            throw new InvalidArgumentException(
                'हप्त्यांची टक्केवारी एकूण 100% असणे आवश्यक आहे. सध्या ती '
                .$this->readablePercent($totalBasisPoints / 100).'% आहे.'
            );
        }
    }

    /**
     * T1 + T2 — the rupee figure of each tranche.
     *
     * T1: every share is a percentage OF THE TOTAL, never of the running balance.
     * T2: the final tranche is whatever is left, so the parts sum to the whole to the paisa.
     *
     * Computed in paise as integers throughout, then handed back as decimal strings in the same
     * shape the money columns use. No amount is ever stored — this is the only place it exists.
     *
     * @param  iterable<int, SuchakSuccessFeeTranche|array<string, mixed>>  $tranches
     * @return list<string> aligned with the plan order
     */
    public function amounts(int|float|string|null $totalFeeAmount, iterable $tranches): array
    {
        $rows = $this->planRows($tranches);
        if ($rows === []) {
            return [];
        }

        $totalPaise = (int) round(((float) $totalFeeAmount) * 100);
        $lastIndex = count($rows) - 1;

        $paise = [];
        $allocated = 0;
        foreach ($rows as $index => $row) {
            if ($index === $lastIndex) {
                continue;
            }

            $share = (int) round($totalPaise * ((float) $row['share_percent']) / 100);
            $paise[$index] = $share;
            $allocated += $share;
        }

        $paise[$lastIndex] = $totalPaise - $allocated;
        ksort($paise);

        // T2 as a live assertion rather than a promise in a comment. If this ever trips, a
        // rupee has gone missing and no amount may be quoted to anyone.
        if (array_sum($paise) !== $totalPaise) {
            throw new InvalidArgumentException('हप्त्यांची बेरीज एकूण शुल्काएवढी होत नाही.');
        }

        return array_map(
            static fn (int $value): string => number_format($value / 100, 2, '.', ''),
            array_values($paise),
        );
    }

    /**
     * T4 — advisory, never a block.
     *
     * The blueprint says the first tranche "should" be the smallest, and a "should" that
     * refuses to save is a "must". Two honest plans breach it: an equal 50/50 split, and a plan
     * whose first trigger is genuinely the hardest evidence. Blocking those would push the
     * Suchak into a shape he did not agree to, which is worse than the risk T4 guards against.
     * So it is recorded, not enforced: returned for the screen to show and written to the log
     * so the pattern D25 worries about (a big prize on the softest evidence) is visible in
     * hindsight rather than invisible.
     *
     * @param  iterable<int, SuchakSuccessFeeTranche|array<string, mixed>>  $tranches
     * @return list<string> Marathi advisories, empty when the plan is well shaped
     */
    public function advisories(iterable $tranches): array
    {
        $rows = $this->planRows($tranches);
        if (count($rows) < 2) {
            return [];
        }

        $first = (float) $rows[0]['share_percent'];
        $smallest = min(array_map(static fn (array $row): float => (float) $row['share_percent'], $rows));

        if ($first > $smallest) {
            return ['पहिला हप्ता सर्वात लहान ठेवणे योग्य — तो सर्वात कमी पुराव्यावर मिळतो.'];
        }

        return [];
    }

    /**
     * The canonical fragment that goes inside agreement_snapshot_hash.
     *
     * Only plan facts. Release and settlement state is deliberately excluded: a tranche firing
     * on a match is not a change to the agreed terms, and hashing it would make every released
     * tranche read as "package changed" and freeze the agreement it just earned money under.
     *
     * @param  iterable<int, SuchakSuccessFeeTranche|array<string, mixed>>  $tranches
     * @return list<array<string, mixed>>
     */
    public function snapshotPayload(iterable $tranches): array
    {
        return array_map(static fn (array $row): array => [
            'sort_order' => (int) $row['sort_order'],
            'trigger_stage_key' => (string) $row['trigger_stage_key'],
            'share_percent' => number_format((float) $row['share_percent'], 2, '.', ''),
            'is_final_tranche' => (bool) $row['is_final_tranche'],
        ], $this->planRows($tranches));
    }

    /**
     * A split only means something against a fixed figure. `as_wished` and `none` have no total
     * to take a percentage of, and a NULL amount has no total at all.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function assertPackageCarriesFixedSuccessFee(SuchakServicePackage $package, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        if ($package->post_marriage_fee_mode !== SuchakCustomerPlan::MODE_FIXED
            || $package->post_marriage_fee_amount === null
            || (float) $package->post_marriage_fee_amount <= 0.0) {
            throw new InvalidArgumentException('ठरलेले यशस्वी विवाह शुल्क नसताना हप्ते ठरवता येणार नाहीत.');
        }
    }

    /**
     * M9's guard rail. Once a tranche has been released against a match or paid, the split is
     * spent history: re-cutting it on a new revision would reset what the family already owes
     * and let the same rupee be charged twice.
     *
     * @param  iterable<int, SuchakSuccessFeeTranche>  $existing
     * @param  list<array<string, mixed>>  $rows
     */
    public function assertPlanChangeAllowed(iterable $existing, array $rows): void
    {
        $committed = false;
        $existingRows = [];
        foreach ($existing as $tranche) {
            if ($tranche->isCommitted()) {
                $committed = true;
            }
            $existingRows[] = $tranche;
        }

        if (! $committed) {
            return;
        }

        if ($this->snapshotPayload($existingRows) !== $this->snapshotPayload($rows)) {
            throw new InvalidArgumentException('एक हप्ता आधीच लागू झाला असल्याने हप्त्यांची विभागणी बदलता येणार नाही.');
        }
    }

    /**
     * Log the T4 advisory once, where the plan is actually persisted.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function logAdvisories(array $rows, int $agreementId): void
    {
        foreach ($this->advisories($rows) as $advisory) {
            Log::warning('Suchak success-fee tranche advisory (blueprint 7.4 T4).', [
                'customer_agreement_id' => $agreementId,
                'advisory' => $advisory,
                'shares' => array_map(static fn (array $row): string => (string) $row['share_percent'], $rows),
            ]);
        }
    }

    /**
     * @param  iterable<int, SuchakSuccessFeeTranche|array<string, mixed>>  $tranches
     * @return list<array<string, mixed>>
     */
    private function planRows(iterable $tranches): array
    {
        $rows = [];
        foreach ($tranches as $tranche) {
            $rows[] = $tranche instanceof SuchakSuccessFeeTranche
                ? [
                    'sort_order' => (int) $tranche->sort_order,
                    'trigger_stage_key' => (string) $tranche->trigger_stage_key,
                    'share_percent' => (string) $tranche->share_percent,
                    'is_final_tranche' => (bool) $tranche->is_final_tranche,
                ]
                : $tranche;
        }

        usort($rows, static fn (array $a, array $b): int => (int) $a['sort_order'] <=> (int) $b['sort_order']);

        return array_values($rows);
    }

    private function percent(mixed $value): string
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException('प्रत्येक हप्त्याची टक्केवारी लिहिणे आवश्यक आहे.');
        }

        $percent = (float) $value;
        if ($percent <= 0.0 || $percent > 100.0) {
            throw new InvalidArgumentException('प्रत्येक हप्त्याची टक्केवारी 0 पेक्षा जास्त आणि 100 पर्यंत असावी.');
        }

        return number_format($percent, 2, '.', '');
    }

    /**
     * Latin digits, and no trailing ".00" on a whole percentage — the frozen digit rule, and
     * "90%" is what the Suchak typed, not "90.00%".
     */
    private function readablePercent(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.');
    }
}

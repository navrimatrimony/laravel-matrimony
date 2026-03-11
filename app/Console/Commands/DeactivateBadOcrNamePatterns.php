<?php

namespace App\Console\Commands;

use App\Models\OcrCorrectionPattern;
use App\Services\Ocr\OcrNormalize;
use Illuminate\Console\Command;

/**
 * Mark full_name patterns with garbage corrected_value as inactive (review).
 * Does not delete; preserves history. Run once to clean bad suggestions.
 */
class DeactivateBadOcrNamePatterns extends Command
{
    protected $signature = 'ocr:deactivate-bad-name-patterns';

    protected $description = 'Deactivate OCR patterns for full_name that fail sanity (garbage corrected_value)';

    public function handle(): int
    {
        $patterns = OcrCorrectionPattern::where('field_key', 'full_name')
            ->orWhere('field_key', 'core.full_name')
            ->where('is_active', true)
            ->get();

        $deactivated = 0;
        foreach ($patterns as $p) {
            $v = trim((string) $p->corrected_value);
            if ($v === '') {
                continue;
            }
            if (! OcrNormalize::sanityCheckLearnedValue('full_name', $v)) {
                $p->update([
                    'is_active' => false,
                    'retired_at' => now(),
                    'retirement_reason' => 'sanity_check_failed',
                    'promotion_status' => 'retired',
                ]);
                $deactivated++;
                $this->line("Deactivated pattern id={$p->id} wrong_pattern=" . substr($p->wrong_pattern, 0, 40) . ' corrected_value=' . substr($v, 0, 50));
            }
        }

        $this->info("Deactivated {$deactivated} bad full_name pattern(s).");
        return self::SUCCESS;
    }
}

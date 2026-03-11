<?php

namespace App\Console\Commands;

use App\Models\OcrCorrectionPattern;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SSOT Day-27: Build OCR frequency patterns from correction logs.
 * Groups corrections by field_key + original_value + corrected_value.
 * Creates/updates patterns with usage_count >= 5.
 * 
 * Run: php artisan ocr:build-frequency-patterns
 */
class BuildOcrFrequencyPatterns extends Command
{
    protected $signature = 'ocr:build-frequency-patterns';

    protected $description = 'Build OCR correction frequency patterns from logs (SSOT Day-27)';

    public function handle(): int
    {
        $this->info('Building OCR frequency patterns from correction logs...');

        DB::transaction(function () {
            // Query correction logs with valid original and corrected values
            $groups = DB::table('ocr_correction_logs')
                ->select(
                    'field_key',
                    'original_value',
                    'corrected_value',
                    DB::raw('COUNT(*) as usage_count')
                )
                ->whereNotNull('original_value')
                ->whereNotNull('corrected_value')
                ->whereRaw("TRIM(original_value) != ''")
                ->whereRaw("TRIM(corrected_value) != ''")
                ->whereColumn('original_value', '!=', 'corrected_value')
                ->groupBy('field_key', 'original_value', 'corrected_value')
                ->having('usage_count', '>=', 5)
                ->orderBy('usage_count', 'desc')
                ->get();

            $total = $groups->count();
            $this->info("Found {$total} pattern groups with usage_count >= 5");

            if ($total === 0) {
                $this->warn('No patterns to create/update.');
                return;
            }

            $bar = $this->output->createProgressBar($total);
            $bar->start();

            $created = 0;
            $updated = 0;

            foreach ($groups as $group) {
                // Upsert: unique by field_key + wrong_pattern + corrected_value + source
                $pattern = OcrCorrectionPattern::updateOrCreate(
                    [
                        'field_key' => $group->field_key,
                        'wrong_pattern' => $group->original_value,
                        'corrected_value' => $group->corrected_value,
                        'source' => 'frequency_rule',
                    ],
                    [
                        'usage_count' => (int) $group->usage_count,
                        'pattern_confidence' => 0.80,
                        'is_active' => true,
                        'authored_by_type' => 'system',
                        'updated_at' => now(),
                    ]
                );

                if ($pattern->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }

                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
            $this->info("Created: {$created}, Updated: {$updated}");
        });

        $this->info('Pattern building completed successfully.');

        return self::SUCCESS;
    }
}

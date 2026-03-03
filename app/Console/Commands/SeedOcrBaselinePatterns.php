<?php

namespace App\Console\Commands;

use App\Models\OcrCorrectionPattern;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SSOT Day-27: Seed baseline OCR patterns from config.
 * Upserts patterns with source='frequency_rule' into ocr_correction_patterns.
 * 
 * Run: php artisan ocr:seed-baseline-patterns
 * 
 * VERIFICATION:
 * 1) php artisan ocr:seed-baseline-patterns
 * 2) php artisan tinker:
 *    \App\Models\OcrCorrectionPattern::where('source','frequency_rule')->count();
 *    \App\Models\OcrCorrectionPattern::where('source','frequency_rule')->take(5)->get(['field_key','wrong_pattern','corrected_value','pattern_confidence','source','is_active'])->toArray();
 * 3) Test OCR parsing with sample text containing "O+ve" or "A+ve" â†’ should normalize to "O+" or "A+"
 */
class SeedOcrBaselinePatterns extends Command
{
    protected $signature = 'ocr:seed-baseline-patterns';

    protected $description = 'Seed baseline OCR correction patterns from config (SSOT Day-27)';

    public function handle(): int
    {
        $this->info('Seeding baseline OCR patterns...');

        $patterns = config('ocr_baseline_patterns', []);

        if (empty($patterns)) {
            $this->warn('No baseline patterns found in config/ocr_baseline_patterns.php');
            return self::FAILURE;
        }

        DB::transaction(function () use ($patterns) {
            $created = 0;
            $updated = 0;

            $bar = $this->output->createProgressBar(count($patterns));
            $bar->start();

            foreach ($patterns as $pattern) {
                // Ensure source is frequency_rule
                $pattern['source'] = 'frequency_rule';

                // Upsert by unique key: field_key + wrong_pattern + corrected_value + source
                $model = OcrCorrectionPattern::updateOrCreate(
                    [
                        'field_key' => $pattern['field_key'],
                        'wrong_pattern' => $pattern['wrong_pattern'],
                        'corrected_value' => $pattern['corrected_value'],
                        'source' => 'frequency_rule',
                    ],
                    [
                        'usage_count' => 0, // Baseline patterns have usage_count = 0
                        'pattern_confidence' => $pattern['pattern_confidence'] ?? 0.70,
                        'is_active' => $pattern['is_active'] ?? true,
                        'updated_at' => now(),
                    ]
                );

                if ($model->wasRecentlyCreated) {
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

        $this->info('Baseline pattern seeding completed successfully.');

        return self::SUCCESS;
    }
}


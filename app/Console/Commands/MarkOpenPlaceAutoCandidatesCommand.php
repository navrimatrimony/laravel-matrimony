<?php

namespace App\Console\Commands;

use App\Models\LocationOpenPlaceSuggestion;
use Illuminate\Console\Command;

class MarkOpenPlaceAutoCandidatesCommand extends Command
{
    protected $signature = 'location:mark-open-place-auto-candidates {--threshold=5 : Minimum usage_count to mark auto_candidate}';

    protected $description = 'Mark pending unresolved open-place suggestions as auto_candidate when usage threshold is reached';

    public function handle(): int
    {
        $threshold = max(1, (int) $this->option('threshold'));

        $updated = LocationOpenPlaceSuggestion::query()
            ->where('status', 'pending')
            ->whereNull('resolved_city_id')
            ->whereNull('merged_into_suggestion_id')
            ->where('usage_count', '>=', $threshold)
            ->update(['status' => 'auto_candidate']);

        $this->info("Marked {$updated} open-place suggestions as auto_candidate (threshold: {$threshold}).");

        return self::SUCCESS;
    }
}

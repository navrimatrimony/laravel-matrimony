<?php

namespace App\Console\Commands;

use App\Services\Location\OpenPlaceGeoJsonPromotionService;
use Illuminate\Console\Command;

class PromoteOpenPlaceSuggestionsCommand extends Command
{
    protected $signature = 'location:promote-open-places
        {--path= : Target geo JSON path (default: database/seeders/data/geo/cities.json)}
        {--dry-run : Compute and print without writing files}';

    protected $description = 'Promote approved open-place suggestions into geo JSON SSOT (append-only)';

    public function handle(OpenPlaceGeoJsonPromotionService $promotion): int
    {
        $path = (string) ($this->option('path') ?: base_path('database/seeders/data/geo/cities.json'));
        $dryRun = (bool) $this->option('dry-run');

        $stats = $promotion->promoteToGeoJson($path, $dryRun);

        $this->info('Open-place promotion completed.');
        $this->line('Path: '.$stats['path']);
        $this->line('Scanned: '.$stats['scanned']);
        $this->line('Promoted (new JSON entries): '.$stats['promoted']);
        $this->line('Skipped (already exists, aliases merged if needed): '.$stats['skipped']);
        $this->line('Invalid hierarchy/statecode skipped: '.$stats['invalid']);
        $this->line('Final cities count: '.$stats['count']);
        $this->line('Checksum: '.$stats['checksum']);
        $this->line('Version: '.$stats['version']);
        $this->line('Manifest path: '.$stats['manifest_path']);
        if ($dryRun) {
            $this->warn('Dry-run mode: no file/database writes were performed.');
        }

        return self::SUCCESS;
    }
}


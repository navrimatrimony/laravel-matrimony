<?php

namespace App\Console\Commands;

use App\Models\Location;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class LocationExportJsonCommand extends Command
{
    protected $signature = 'location:export-json
                            {--path=location_export.json : Filename relative to storage/app}';

    protected $description = 'Export canonical locations (with parent label and pincodes) to JSON for backup or external sync.';

    public function handle(): int
    {
        $relative = ltrim((string) $this->option('path'), '/\\');
        $locations = Location::query()
            ->with(['parent', 'pincodes'])
            ->orderBy('id')
            ->get();

        $payload = [];
        foreach ($locations as $loc) {
            $payload[] = [
                'id' => (int) $loc->id,
                'name' => (string) $loc->name,
                'type' => (string) $loc->type,
                'parent' => $loc->parent ? (string) $loc->parent->name : '',
                'pincodes' => $loc->pincodes->pluck('pincode')->values()->all(),
            ];
        }

        $fullPath = storage_path('app/'.$relative);
        File::ensureDirectoryExists(dirname($fullPath));
        File::put($fullPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info('Wrote '.count($payload).' location(s) to '.$fullPath);

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Location;
use Database\Seeders\Location\LocationSeeder;
use Database\Seeders\PincodeSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class SeedMaharashtraLocations extends Command
{
    protected $signature = 'location:seed-maharashtra {--force : Seed even when the geo SSOT table already has data}';

    protected $description = 'Seed Maharashtra sample location dataset (addresses rows + pincode/lat/lng) in controlled order';

    public function handle(): int
    {
        if (! $this->schemaReady()) {
            return self::FAILURE;
        }

        if (! $this->datasetFilesExist()) {
            return self::FAILURE;
        }

        if (! $this->guardExistingData()) {
            return self::FAILURE;
        }

        $this->info('Seeding states / districts / talukas / canonical addresses...');
        $this->call('db:seed', ['--class' => LocationSeeder::class]);

        $this->info('Seeding pincodes...');
        $this->call('db:seed', ['--class' => PincodeSeeder::class]);

        $this->line('');
        $this->info('Maharashtra location seeding complete.');
        $this->line('States: '.$this->countByType('state'));
        $this->line('Districts: '.$this->countByType('district'));
        $this->line('Talukas: '.$this->countByType('taluka'));
        $this->line('Locations (city+suburb+village): '.$this->countLocationsLeaf());
        $this->line('Rows with pincode set: '.Location::query()->whereNotNull('pincode')->count());

        return self::SUCCESS;
    }

    private function schemaReady(): bool
    {
        if (! Schema::hasTable(Location::geoTable())) {
            $this->error('Geographic SSOT table "'.Location::geoTable().'" is missing. Run migrations first.');

            return false;
        }

        return true;
    }

    private function datasetFilesExist(): bool
    {
        $required = [
            'states.json',
            'districts_maharashtra.json',
            'talukas_maharashtra.json',
            'cities_maharashtra.json',
            'suburbs_maharashtra.json',
            'villages_maharashtra.json',
            'pincodes_maharashtra.json',
        ];

        $ok = true;
        foreach ($required as $file) {
            $path = database_path('seeders/location/'.$file);
            if (! is_file($path)) {
                $this->error("Missing dataset file: {$path}");
                $ok = false;
            }
        }

        return $ok;
    }

    private function guardExistingData(): bool
    {
        $existing = Location::query()->count();
        if ($existing === 0) {
            return true;
        }

        if ((bool) $this->option('force')) {
            $this->warn("Existing locations detected ({$existing}). Proceeding due to --force.");

            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->error("Existing locations detected ({$existing}). Re-run with --force in non-interactive mode.");

            return false;
        }

        $confirmed = $this->confirm("Locations already has {$existing} rows. Continue seeding anyway?", false);
        if (! $confirmed) {
            $this->warn('Seeding aborted by user.');

            return false;
        }

        return true;
    }

    private function countByType(string $type): int
    {
        return Location::query()->where('type', $type)->count();
    }

    private function countLocationsLeaf(): int
    {
        return Location::query()->whereIn('type', ['city', 'suburb', 'village'])->count();
    }
}

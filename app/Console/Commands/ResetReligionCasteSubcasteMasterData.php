<?php

namespace App\Console\Commands;

use App\Services\MasterData\ReligionCasteSubcasteImportService;
use App\Support\MasterData\ReligionCasteSubcasteMultilanguageSeedSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ResetReligionCasteSubcasteMasterData extends Command
{
    protected $signature = 'master:reset-religion-caste-subcaste {--force-local : Allow running outside the local environment}';

    protected $description = 'LOCAL ONLY: clear religion/caste/sub-caste mappings, wipe master tables, reimport from database/data/religion_caste_subcaste_master.tsv, then apply EN/MR from database/seeders/data/religion_caste_subcaste_seed_*.json';

    public function handle(ReligionCasteSubcasteImportService $importService): int
    {
        if (! $this->environmentAllowsRun()) {
            throw new RuntimeException(
                'master:reset-religion-caste-subcaste may only run when APP_ENV=local, or when --force-local is passed explicitly.'
            );
        }

        $path = base_path('database/data/religion_caste_subcaste_master.tsv');

        $parsed = $importService->parseAndDedupeTsv($path);

        DB::transaction(function () use ($importService) {
            $importService->clearForeignReferences();
            $importService->deleteMasterRows();
        });

        $importService->resetMasterTableAutoIncrements();

        $summary = DB::transaction(function () use ($importService, $parsed) {
            return $importService->insertFromParsed($parsed);
        });

        $this->info('Religions imported: '.$summary['religions']);
        $this->info('Castes imported: '.$summary['castes']);
        $this->info('Sub-castes imported: '.$summary['sub_castes']);
        $this->info('Skipped duplicate source rows: '.$summary['skipped_duplicate_rows']);

        ReligionCasteSubcasteMultilanguageSeedSync::apply();
        $this->info('Bilingual labels synced from religion_caste_subcaste_seed_*.json where rows match.');

        return self::SUCCESS;
    }

    private function environmentAllowsRun(): bool
    {
        if ($this->option('force-local')) {
            return true;
        }

        return app()->environment('local');
    }
}

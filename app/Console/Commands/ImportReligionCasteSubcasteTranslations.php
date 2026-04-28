<?php

namespace App\Console\Commands;

use App\Services\MasterData\MasterDataTranslationImportService;
use App\Support\MasterData\ReligionCasteSubcasteMultilanguageSeedSync;
use Illuminate\Console\Command;

class ImportReligionCasteSubcasteTranslations extends Command
{
    protected $signature = 'master:import-religion-caste-subcaste-translations';

    protected $description = 'Apply EN/MR from database/seeders/data/religion_caste_subcaste_seed_*.json (canonical). If database/data/religion_caste_subcaste_translations.json exists, also imports alias rows from that legacy file.';

    public function handle(MasterDataTranslationImportService $service): int
    {
        $legacyPath = base_path('database/data/religion_caste_subcaste_translations.json');
        if (is_readable($legacyPath)) {
            $json = file_get_contents($legacyPath);
            if ($json === false) {
                $this->error('Could not read legacy JSON.');

                return self::FAILURE;
            }
            $data = json_decode($json, true);
            if (! is_array($data)) {
                $this->error('Invalid legacy JSON.');

                return self::FAILURE;
            }
            $service->importFromDecodedJson($data);
            $this->info('Legacy translations JSON imported (aliases + labels).');
        }

        ReligionCasteSubcasteMultilanguageSeedSync::apply();
        $this->info('Bilingual labels synced from religion_caste_subcaste_seed_*.json where rows match.');

        return self::SUCCESS;
    }
}

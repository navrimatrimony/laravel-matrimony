<?php

namespace App\Console\Commands;

use App\Services\MasterData\MasterDataTranslationImportService;
use Illuminate\Console\Command;

class ImportReligionCasteSubcasteTranslations extends Command
{
    protected $signature = 'master:import-religion-caste-subcaste-translations';

    protected $description = 'Import database/data/religion_caste_subcaste_translations.json into master tables and alias tables';

    public function handle(MasterDataTranslationImportService $service): int
    {
        $path = base_path('database/data/religion_caste_subcaste_translations.json');
        if (! is_readable($path)) {
            $this->error('Missing or unreadable: '.$path);

            return self::FAILURE;
        }

        $json = file_get_contents($path);
        if ($json === false) {
            $this->error('Could not read JSON.');

            return self::FAILURE;
        }

        $data = json_decode($json, true);
        if (! is_array($data)) {
            $this->error('Invalid JSON.');

            return self::FAILURE;
        }

        $service->importFromDecodedJson($data);
        $this->info('Translations imported.');

        return self::SUCCESS;
    }
}

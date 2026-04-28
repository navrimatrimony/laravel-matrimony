<?php

namespace App\Console\Commands;

use App\Services\MasterData\ReligionCasteSubcasteTranslationJsonGenerator;
use Illuminate\Console\Command;

class GenerateReligionCasteSubcasteTranslationsJson extends Command
{
    protected $signature = 'master:generate-religion-caste-subcaste-translations-json';

    protected $description = 'Generate optional legacy database/data/religion_caste_subcaste_translations.json (aliases/OCR shape) from TSV; canonical EN/MR for seeding lives in database/seeders/data/religion_caste_subcaste_seed_*.json';

    public function handle(ReligionCasteSubcasteTranslationJsonGenerator $generator): int
    {
        $path = base_path('database/data/religion_caste_subcaste_master.tsv');
        $outPath = base_path('database/data/religion_caste_subcaste_translations.json');

        $entries = $generator->buildEntriesFromTsv($path);
        file_put_contents($outPath, json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n");

        $this->info('Wrote '.$outPath.' ('.count($entries).' entries).');

        return self::SUCCESS;
    }
}

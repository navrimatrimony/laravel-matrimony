<?php

namespace Database\Factories;

use App\Models\SuchakBiodataExport;
use App\Models\SuchakProfileRepresentation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SuchakBiodataExport>
 */
class SuchakBiodataExportFactory extends Factory
{
    protected $model = SuchakBiodataExport::class;

    public function definition(): array
    {
        $representation = SuchakProfileRepresentation::factory()->create();
        $representation->loadMissing('suchakAccount');

        return [
            'suchak_account_id' => $representation->suchak_account_id,
            'matrimony_profile_id' => $representation->matrimony_profile_id,
            'representation_id' => $representation->id,
            'export_type' => SuchakBiodataExport::TYPE_BIODATA_PDF,
            'file_path' => null,
            'generated_by_user_id' => $representation->suchakAccount->user_id,
            'downloaded_at' => null,
            'shared_at' => null,
        ];
    }
}

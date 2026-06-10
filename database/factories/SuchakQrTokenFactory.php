<?php

namespace Database\Factories;

use App\Models\SuchakBiodataExport;
use App\Models\SuchakQrToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SuchakQrToken>
 */
class SuchakQrTokenFactory extends Factory
{
    protected $model = SuchakQrToken::class;

    public function definition(): array
    {
        $export = SuchakBiodataExport::factory()->create();
        $rawToken = Str::random(64);

        return [
            'token_hash' => hash('sha256', $rawToken),
            'suchak_account_id' => $export->suchak_account_id,
            'matrimony_profile_id' => $export->matrimony_profile_id,
            'representation_id' => $export->representation_id,
            'export_id' => $export->id,
            'expires_at' => now()->addDays(30),
            'scan_count' => 0,
            'last_scanned_at' => null,
            'revoked_at' => null,
            'revoked_reason' => null,
            'replaced_by_token_id' => null,
        ];
    }
}

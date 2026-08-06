<?php

namespace Database\Seeders;

use App\Models\FeatureFlag;
use App\Services\FeatureFlagService;
use App\Support\FeatureFlagKey;
use Illuminate\Database\Seeder;

/**
 * Seeds the first global feature flag only. Never overwrites an existing row
 * (production must stay OFF until an admin explicitly enables it).
 */
class FeatureFlagSeeder extends Seeder
{
    public function run(): void
    {
        $defaultEnabled = app(FeatureFlagService::class)->environmentDefault();

        FeatureFlag::query()->firstOrCreate(
            ['key' => FeatureFlagKey::SHOWCASE_PROFILES],
            [
                'display_name' => 'Showcase Profiles',
                'description' => 'Master switch for the entire Showcase Profiles module: admin tools, member search/listing, profile pages, scheduled jobs, automation, and related notifications. Turning this off gates access only — code and data are never deleted.',
                'enabled' => $defaultEnabled,
            ]
        );
    }
}

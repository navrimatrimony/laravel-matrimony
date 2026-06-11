<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('matrimony_profiles') && ! Schema::hasColumn('matrimony_profiles', 'property_details')) {
            Schema::table('matrimony_profiles', function (Blueprint $table) {
                $table->longText('property_details')->nullable()->after('other_relatives_text');
            });
        }

        $this->backfillPropertyDetails();

        Schema::dropIfExists('profile_property_summary');
        Schema::dropIfExists('profile_property_assets');
        Schema::dropIfExists('master_asset_types');
    }

    public function down(): void
    {
        if (! Schema::hasTable('master_asset_types')) {
            Schema::create('master_asset_types', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('label');
                $table->string('label_mr')->nullable();
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('profile_property_assets')) {
            Schema::create('profile_property_assets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('profile_id')
                    ->constrained('matrimony_profiles')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('asset_type_id')->nullable();
                $table->string('location')->nullable();
                $table->decimal('estimated_value', 12, 2)->nullable();
                $table->unsignedBigInteger('ownership_type_id')->nullable();
                $table->longText('notes')->nullable();
                $table->text('additional_information')->nullable();
                $table->unsignedBigInteger('city_id')->nullable();
                $table->unsignedBigInteger('taluka_id')->nullable();
                $table->unsignedBigInteger('district_id')->nullable();
                $table->unsignedBigInteger('state_id')->nullable();
                $table->timestamps();

                $table->index('profile_id');
                $table->index('asset_type_id');
                $table->index('ownership_type_id');
            });
        }

        if (Schema::hasColumn('matrimony_profiles', 'property_details') && Schema::hasTable('profile_property_assets')) {
            DB::table('matrimony_profiles')
                ->whereNotNull('property_details')
                ->where('property_details', '!=', '')
                ->orderBy('id')
                ->select(['id', 'property_details'])
                ->chunkById(200, function ($profiles): void {
                    foreach ($profiles as $profile) {
                        DB::table('profile_property_assets')->insert([
                            'profile_id' => $profile->id,
                            'asset_type_id' => null,
                            'location' => null,
                            'estimated_value' => null,
                            'ownership_type_id' => null,
                            'notes' => trim((string) $profile->property_details),
                            'additional_information' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                });
        }

        if (Schema::hasTable('matrimony_profiles') && Schema::hasColumn('matrimony_profiles', 'property_details')) {
            Schema::table('matrimony_profiles', function (Blueprint $table) {
                $table->dropColumn('property_details');
            });
        }
    }

    private function backfillPropertyDetails(): void
    {
        if (! Schema::hasTable('matrimony_profiles') || ! Schema::hasColumn('matrimony_profiles', 'property_details')) {
            return;
        }

        DB::table('matrimony_profiles')
            ->orderBy('id')
            ->select(['id', 'property_details'])
            ->chunkById(200, function ($profiles): void {
                foreach ($profiles as $profile) {
                    $lines = [];
                    $existing = trim((string) ($profile->property_details ?? ''));
                    if ($existing !== '') {
                        $lines[] = $existing;
                    }

                    foreach ($this->summaryLinesForProfile((int) $profile->id) as $line) {
                        $lines[] = $line;
                    }

                    foreach ($this->assetLinesForProfile((int) $profile->id) as $line) {
                        $lines[] = $line;
                    }

                    $merged = $this->mergeLines($lines);
                    if ($merged === '') {
                        continue;
                    }

                    DB::table('matrimony_profiles')
                        ->where('id', $profile->id)
                        ->update([
                            'property_details' => $merged,
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    /**
     * @return list<string>
     */
    private function summaryLinesForProfile(int $profileId): array
    {
        if (! Schema::hasTable('profile_property_summary')) {
            return [];
        }

        $summary = DB::table('profile_property_summary')
            ->where('profile_id', $profileId)
            ->first();
        if (! $summary) {
            return [];
        }

        $lines = [];
        if (! empty($summary->owns_house)) {
            $lines[] = 'Owns house: Yes';
        }
        if (! empty($summary->owns_flat)) {
            $lines[] = 'Owns flat: Yes';
        }
        if (! empty($summary->owns_agriculture)) {
            $lines[] = 'Owns agriculture: Yes';
        }
        if (trim((string) ($summary->agriculture_type ?? '')) !== '') {
            $lines[] = 'Agriculture type: '.trim((string) $summary->agriculture_type);
        }
        if (($summary->total_land_acres ?? null) !== null && (string) $summary->total_land_acres !== '') {
            $lines[] = 'Total land (acres): '.trim((string) $summary->total_land_acres);
        }
        if (($summary->annual_agri_income ?? null) !== null && (string) $summary->annual_agri_income !== '') {
            $lines[] = 'Annual agriculture income: '.trim((string) $summary->annual_agri_income);
        }
        if (trim((string) ($summary->summary_notes ?? '')) !== '') {
            $lines[] = trim((string) $summary->summary_notes);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function assetLinesForProfile(int $profileId): array
    {
        if (! Schema::hasTable('profile_property_assets')) {
            return [];
        }

        $assetLabels = $this->masterLabelsById('master_asset_types');
        $ownershipLabels = $this->masterLabelsById('master_ownership_types');
        $columns = array_fill_keys(Schema::getColumnListing('profile_property_assets'), true);

        return DB::table('profile_property_assets')
            ->where('profile_id', $profileId)
            ->orderBy('id')
            ->get()
            ->map(function ($asset) use ($assetLabels, $ownershipLabels, $columns): string {
                $parts = [];
                $assetType = null;
                if (isset($columns['asset_type_id']) && ! empty($asset->asset_type_id)) {
                    $assetType = $assetLabels[(int) $asset->asset_type_id] ?? null;
                }
                if ($assetType === null && isset($columns['asset_type'])) {
                    $assetType = trim((string) ($asset->asset_type ?? '')) ?: null;
                }
                if ($assetType !== null) {
                    $parts[] = $assetType;
                }
                if (isset($columns['location']) && trim((string) ($asset->location ?? '')) !== '') {
                    $parts[] = trim((string) $asset->location);
                }
                if (isset($columns['ownership_type_id']) && ! empty($asset->ownership_type_id)) {
                    $ownership = $ownershipLabels[(int) $asset->ownership_type_id] ?? null;
                    if ($ownership !== null) {
                        $parts[] = $ownership;
                    }
                } elseif (isset($columns['ownership_type']) && trim((string) ($asset->ownership_type ?? '')) !== '') {
                    $parts[] = trim((string) $asset->ownership_type);
                }
                if (isset($columns['estimated_value']) && ($asset->estimated_value ?? null) !== null && (string) $asset->estimated_value !== '') {
                    $parts[] = 'Estimated value: '.trim((string) $asset->estimated_value);
                }
                if (isset($columns['notes']) && trim((string) ($asset->notes ?? '')) !== '') {
                    $parts[] = trim((string) $asset->notes);
                }
                if (isset($columns['additional_information']) && trim((string) ($asset->additional_information ?? '')) !== '') {
                    $parts[] = trim((string) $asset->additional_information);
                }

                return implode(' - ', array_values(array_unique(array_filter($parts))));
            })
            ->filter(fn (string $line): bool => trim($line) !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function masterLabelsById(string $table): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)
            ->get(['id', 'label'])
            ->mapWithKeys(fn ($row): array => [(int) $row->id => trim((string) $row->label)])
            ->all();
    }

    /**
     * @param  list<string>  $lines
     */
    private function mergeLines(array $lines): string
    {
        $merged = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            foreach (preg_split('/\R+/u', $line) ?: [] as $part) {
                $part = trim((string) $part);
                if ($part !== '' && ! in_array($part, $merged, true)) {
                    $merged[] = $part;
                }
            }
        }

        return implode("\n", $merged);
    }
};

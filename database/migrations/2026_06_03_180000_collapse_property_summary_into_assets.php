<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('profile_property_assets') && ! Schema::hasColumn('profile_property_assets', 'notes')) {
            Schema::table('profile_property_assets', function (Blueprint $table) {
                $table->longText('notes')->nullable()->after('estimated_value');
            });
        }

        if (Schema::hasTable('profile_property_summary') && Schema::hasTable('profile_property_assets')) {
            $summaries = DB::table('profile_property_summary')->orderBy('id')->get();
            foreach ($summaries as $summary) {
                $notes = $this->summaryRowToNotes($summary);
                if ($notes === '') {
                    continue;
                }

                $existingAsset = DB::table('profile_property_assets')
                    ->where('profile_id', $summary->profile_id)
                    ->orderBy('id')
                    ->first();

                if ($existingAsset) {
                    $merged = $this->mergeNotes((string) ($existingAsset->notes ?? ''), $notes);
                    DB::table('profile_property_assets')
                        ->where('id', $existingAsset->id)
                        ->update([
                            'notes' => $merged,
                            'updated_at' => now(),
                        ]);
                } else {
                    DB::table('profile_property_assets')->insert([
                        'profile_id' => $summary->profile_id,
                        'asset_type_id' => null,
                        'location' => null,
                        'estimated_value' => null,
                        'notes' => $notes,
                        'ownership_type_id' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::table('profile_property_assets')
                ->whereNull('asset_type_id')
                ->where(function ($q) {
                    $q->whereNull('location')->orWhere('location', '');
                })
                ->whereNull('estimated_value')
                ->where(function ($q) {
                    $q->whereNull('notes')->orWhere('notes', '');
                })
                ->whereNull('ownership_type_id')
                ->where(function ($q) {
                    $q->whereNull('city_id')->orWhere('city_id', 0);
                })
                ->where(function ($q) {
                    $q->whereNull('taluka_id')->orWhere('taluka_id', 0);
                })
                ->where(function ($q) {
                    $q->whereNull('district_id')->orWhere('district_id', 0);
                })
                ->where(function ($q) {
                    $q->whereNull('state_id')->orWhere('state_id', 0);
                })
                ->delete();
        }

        Schema::dropIfExists('profile_property_summary');
    }

    public function down(): void
    {
        if (! Schema::hasTable('profile_property_summary')) {
            Schema::create('profile_property_summary', function (Blueprint $table) {
                $table->id();
                $table->foreignId('profile_id')
                    ->unique()
                    ->constrained('matrimony_profiles')
                    ->restrictOnDelete();
                $table->boolean('owns_house')->default(false);
                $table->boolean('owns_flat')->default(false);
                $table->boolean('owns_agriculture')->default(false);
                $table->string('agriculture_type', 50)->nullable();
                $table->decimal('total_land_acres', 10, 2)->nullable();
                $table->decimal('annual_agri_income', 12, 2)->nullable();
                $table->longText('summary_notes')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('profile_property_assets') && Schema::hasColumn('profile_property_assets', 'notes')) {
            Schema::table('profile_property_assets', function (Blueprint $table) {
                $table->dropColumn('notes');
            });
        }
    }

    private function summaryRowToNotes(object $summary): string
    {
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
        if (! empty($summary->agriculture_type)) {
            $lines[] = 'Agriculture type: '.trim((string) $summary->agriculture_type);
        }
        if ($summary->total_land_acres !== null && (string) $summary->total_land_acres !== '') {
            $lines[] = 'Total land (acres): '.trim((string) $summary->total_land_acres);
        }
        if ($summary->annual_agri_income !== null && (string) $summary->annual_agri_income !== '') {
            $lines[] = 'Annual agriculture income: '.trim((string) $summary->annual_agri_income);
        }
        $summaryNotes = trim((string) ($summary->summary_notes ?? ''));
        if ($summaryNotes !== '') {
            $lines[] = $summaryNotes;
        }

        return implode("\n", $lines);
    }

    private function mergeNotes(string $existing, string $incoming): string
    {
        $existing = trim($existing);
        $incoming = trim($incoming);
        if ($existing === '') {
            return $incoming;
        }
        if ($incoming === '' || str_contains($existing, $incoming)) {
            return $existing;
        }

        return $existing."\n\n".$incoming;
    }
};

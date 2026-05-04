<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single SSOT for geo aliases on {@code addresses}: merge legacy {@code city_aliases}
 * into {@code location_aliases}, then drop duplicate table. Rename {@code city_display_meta}
 * → {@code location_display_meta} with {@code location_id} FK (still {@code addresses}.id).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('location_aliases') && ! Schema::hasColumn('location_aliases', 'is_active')) {
            Schema::table('location_aliases', function (Blueprint $table): void {
                $table->boolean('is_active')->default(true)->after('normalized_alias');
            });
        }

        if (Schema::hasTable('city_aliases') && Schema::hasTable('location_aliases')) {
            $rows = DB::table('city_aliases')->get();
            foreach ($rows as $row) {
                $exists = DB::table('location_aliases')
                    ->where('location_id', $row->city_id)
                    ->where('normalized_alias', $row->normalized_alias)
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('location_aliases')->insert([
                    'location_id' => $row->city_id,
                    'alias' => $row->alias_name,
                    'normalized_alias' => $row->normalized_alias,
                    'created_at' => $row->created_at ?? now(),
                    'updated_at' => $row->updated_at ?? now(),
                    'is_active' => (bool) ($row->is_active ?? true),
                ]);
            }
            Schema::dropIfExists('city_aliases');
        }

        if (Schema::hasTable('city_display_meta') && ! Schema::hasTable('location_display_meta')) {
            Schema::rename('city_display_meta', 'location_display_meta');
        }

        if (Schema::hasTable('location_display_meta') && Schema::hasColumn('location_display_meta', 'city_id')) {
            Schema::table('location_display_meta', function (Blueprint $table): void {
                $table->renameColumn('city_id', 'location_id');
            });
        }
    }

    public function down(): void
    {
        // Irreversible data merge: location_aliases + location_display_meta SSOT.
    }
};

<?php

use App\Services\Location\LocationSsotNormalizationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('matrimony_profiles') && Schema::hasTable('locations')
            && ! Schema::hasColumn('matrimony_profiles', 'location_id')) {
            Schema::table('matrimony_profiles', function (Blueprint $table) {
                $table->unsignedBigInteger('location_id')->nullable()->after('city_id');
            });

            Schema::table('matrimony_profiles', function (Blueprint $table) {
                $table->foreign('location_id')->references('id')->on('locations')->nullOnDelete();
            });
        }

        if (Schema::hasTable('matrimony_profiles') && Schema::hasColumn('matrimony_profiles', 'location_id')
            && Schema::hasTable('locations')) {
            DB::statement(
                'UPDATE matrimony_profiles SET location_id = city_id '
                .'WHERE city_id IS NOT NULL AND location_id IS NULL '
                .'AND EXISTS (SELECT 1 FROM locations WHERE locations.id = matrimony_profiles.city_id)'
            );
        }

        if (Schema::hasTable('locations')) {
            /** @var LocationSsotNormalizationService $normalize */
            $normalize = app(LocationSsotNormalizationService::class);
            $normalize->normalizeNamesSlugsLevels();
            $normalize->deduplicateParents();
            try {
                $normalize->addUniqueIdentityConstraint();
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('matrimony_profiles') && Schema::hasColumn('matrimony_profiles', 'location_id')) {
            Schema::table('matrimony_profiles', function (Blueprint $table) {
                try {
                    $table->dropForeign(['location_id']);
                } catch (\Throwable $e) {
                }
                $table->dropColumn('location_id');
            });
        }

        if (Schema::hasTable('locations') && Schema::hasIndex('locations', 'locations_parent_name_type_unique')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->dropUnique('locations_parent_name_type_unique');
            });
        }
    }
};
